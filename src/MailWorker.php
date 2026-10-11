<?php
declare(strict_types=1);
namespace App;

use PHPMailer\PHPMailer\PHPMailer;

final class MailWorker
{
    public function __construct(private readonly Application $app) {}

    public function tick(?int $jobId = null): bool
    {
        if ($jobId !== null && $jobId < 1) throw new \InvalidArgumentException('El identificador de envío debe ser positivo.');
        $db = $this->app->db;
        $config = $this->app->config;
        // A crashed worker may have already handed the message to SMTP. Do not replay blindly.
        $scope = $jobId === null ? '' : ' AND id=?';
        $scopeParams = $jobId === null ? [] : [$jobId];
        $stale = $db->all("SELECT id,request_id,kind FROM jobs WHERE status='sending' AND locked_at < ?" . $scope, [date('Y-m-d H:i:s', time() - 300), ...$scopeParams]);
        foreach ($stale as $row) {
            $db->transaction(function () use ($db, $row) {
                $changed = $db->run("UPDATE jobs SET status='uncertain',last_error='worker_interrupted',encrypted_payload=NULL WHERE id=? AND status='sending'", [$row['id']])->rowCount();
                if ($changed && in_array($row['kind'], ['history', 'response'], true)) $db->run("UPDATE requests SET status='uncertain' WHERE id=?", [$row['request_id']]);
            });
        }
        $job = $db->transaction(function () use ($db, $scope, $scopeParams) {
            $job = $db->one("SELECT * FROM jobs WHERE status IN ('queued','retry') AND available_at <= ?" . $scope . " ORDER BY id LIMIT 1 FOR UPDATE", [$this->app->now(), ...$scopeParams]);
            if (!$job) return null;
            $db->run("UPDATE jobs SET status='sending',attempts=attempts+1,locked_at=? WHERE id=?", [$this->app->now(), $job['id']]);
            if (in_array($job['kind'], ['history', 'response'], true)) $db->run("UPDATE requests SET status='sending' WHERE id=?", [$job['request_id']]);
            $job['attempts']++;
            return $job;
        });
        if (!$job) return false;
        $smtp = null;
        $transportStarted = false;
        try {
            $request = $this->app->request((int) $job['request_id']);
            $patient = $db->one('SELECT * FROM patients WHERE id=? AND active=1', [$request['patient_id']]);
            if (in_array($job['kind'], ['notice', 'response'], true)) {
                // Aviso o respuesta de la persona encargada (p. ej. «No registra»): el paciente puede no existir en el repositorio.
                if (!$request['direct_search'] || !hash_equals($request['contact_email'] ?? '', $job['recipient']) || !filter_var($job['recipient'], FILTER_VALIDATE_EMAIL)) throw new \DomainException('recipient_changed_or_untrusted');
            } else {
                $directDelivery = (bool)$request['direct_search'] && $job['kind'] === 'history';
                if (!$patient || !$this->app->canUseRecipient($patient, $job['recipient'], $directDelivery) || !hash_equals($request['contact_email'] ?? '', $job['recipient'])) throw new \DomainException('recipient_changed_or_untrusted');
                if (!$directDelivery && $config['mail_transport'] === 'smtp' && $config['mode'] === 'demo' && strcasecmp($job['recipient'], $config['test_recipient']) !== 0) throw new \DomainException('test_recipient_not_allowed');
            }
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($config['mail_from'], $config['mail_from_name']);
            $mail->addAddress($job['recipient']);
            $mail->MessageID = $job['message_id'];
            if ($job['kind'] === 'otp') {
                $verification = $db->one('SELECT * FROM verifications WHERE id=?', [$job['verification_id']]);
                if (!$verification || $verification['consumed_at'] || strtotime($verification['expires_at']) <= time() || $request['status'] !== 'pending_verification') {
                    $this->finish($job, 'cancelled', 'verification_inactive');
                    return true;
                }
                $code = Crypto::open($job['encrypted_payload'], $config['app_key']);
                $mail->Subject = 'Código de verificación · ' . $request['reference'];
                $mail->Body = "Tu código de verificación es: $code\n\nVence en 10 minutos y solo puede usarse una vez.\nSi no solicitaste este código, ignora este mensaje.\n\nCitaYa";
            } elseif (in_array($job['kind'], ['notice', 'response'], true)) {
                $mail->Subject = 'Respuesta a tu solicitud ' . $request['reference'];
                $mail->Body = Crypto::open($job['encrypted_payload'], $config['app_key']) . "\n\nCitaYa";
                // Adjuntos que eligió la persona encargada al responder (si los hay), verificados antes de salir.
                foreach ($this->app->responseFiles((int) $job['id']) as $file) $mail->addAttachment($file['path'], $file['original_name'], PHPMailer::ENCODING_BASE64, $file['mime']);
            } else {
                if ($request['direct_search']) $this->app->requireVerified((int)$request['id']);
                elseif (!hash_equals($request['verified_email'] ?? '', $job['recipient'])) throw new \DomainException('verification_required');
                $package = $db->one('SELECT p.*,e.patient_id FROM packages p JOIN encounters e ON e.id=p.encounter_id WHERE p.id=?', [$job['package_id']]);
                if (!$package || (int) $package['patient_id'] !== (int) $request['patient_id'] || (int) $package['encounter_id'] !== (int) $request['encounter_id']) throw new \DomainException('package_mismatch');
                $files = $this->app->packageFiles((int) $job['package_id']);
                $mail->Subject = 'Respuesta a tu solicitud ' . $request['reference'];
                $mail->Body = "Adjuntamos el paquete completo correspondiente a tu solicitud {$request['reference']}.\n\nConserva estos documentos en un lugar privado.\n\nCitaYa";
                foreach ($files as $index => $file) $mail->addAttachment($file['path'], sprintf('Documento_%02d.pdf', $index + 1));
            }
            if ($config['mail_transport'] === 'local') {
                $mail->preSend();
                $message = $mail->getSentMIMEMessage();
                if (strlen($message) > $config['max_mail_bytes']) throw new \DomainException('message_too_large');
                $path = $config['storage_path'] . '/outbox/' . $job['id'] . '.eml';
                if (file_put_contents($path . '.tmp', $message, LOCK_EX) === false || !rename($path . '.tmp', $path)) throw new \RuntimeException('local_mail_write_failed');
                $this->finish($job, 'simulated');
                return true;
            }
            if ($config['mail_transport'] !== 'smtp' || !$config['smtp_host'] || !$config['smtp_user'] || !$config['smtp_password'] || !in_array($config['smtp_encryption'], ['tls','ssl'], true)) throw new \DomainException('smtp_not_configured');
            $mail->isSMTP();
            $smtp = new TrackedSMTP();
            $mail->setSMTPInstance($smtp);
            $mail->Host = $config['smtp_host'];
            $mail->Port = (int) $config['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_user'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_encryption'];
            $mail->Timeout = 30;
            $mail->preSend();
            if (strlen($mail->getSentMIMEMessage()) > $config['max_mail_bytes']) throw new \DomainException('message_too_large');
            $transportStarted = true;
            $mail->postSend();
            $this->finish($job, 'sent');
        } catch (\DomainException $error) {
            // Domain messages contain internal codes or fixed text, never clinical content.
            $this->finish($job, 'failed', $error->getMessage());
        } catch (\Throwable) {
            $smtpError = $smtp?->getError() ?? [];
            $code = (int) ($smtpError['smtp_code'] ?? 0);
            // PHPMailer can put OS socket errors (e.g. Windows 10061) in smtp_code.
            $hasNegativeReply = $code >= 400 && $code <= 599;
            $permanentReply = $code >= 500 && $code <= 599;
            $uncertain = $transportStarted && $smtp?->dataStarted && !$hasNegativeReply;
            $status = $uncertain ? 'uncertain' : (($permanentReply || $job['attempts'] >= $config['mail_attempts']) ? 'failed' : 'retry');
            $this->finish($job, $status, $uncertain ? 'smtp_result_uncertain' : 'transport_error_' . $code);
        }
        return true;
    }

    private function finish(array $job, string $status, ?string $error = null): void
    {
        $db = $this->app->db;
        $db->transaction(function () use ($db, $job, $status, $error) {
            $terminal = $status !== 'retry';
            $db->run('UPDATE jobs SET status=?,last_error=?,sent_at=?,available_at=?,encrypted_payload=CASE WHEN ?=1 THEN NULL ELSE encrypted_payload END WHERE id=?', [
                $status, $error, in_array($status, ['sent','simulated'], true) ? $this->app->now() : null,
                date('Y-m-d H:i:s', time() + 60 * (int) $job['attempts']), $terminal ? 1 : 0, $job['id'],
            ]);
            if (in_array($job['kind'], ['history', 'response'], true)) $db->run('UPDATE requests SET status=? WHERE id=?', [$status, $job['request_id']]);
            $this->app->event((int) $job['request_id'], 'mail_' . $job['kind'], $status);
        });
        if (in_array($status, ['failed', 'uncertain'], true)) (new Automation($this->app))->emit('envio.fallido', ['canal' => 'correo', 'job_id' => (int) $job['id'], 'kind' => $job['kind'],
            'reference' => $this->app->request((int) $job['request_id'])['reference'], 'error' => $error], 'mail-fail:' . $job['id'] . ':' . $job['attempts']);
    }
}
