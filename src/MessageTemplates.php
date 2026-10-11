<?php
declare(strict_types=1);
namespace App;

/**
 * Textos que recibe el paciente por WhatsApp y correo. Se editan desde Acceso interno → Plantillas;
 * si no hay versión guardada se usa el texto predeterminado. En WhatsApp *texto* se ve en negrita.
 */
final class MessageTemplates
{
    /** código => [nombre, descripción, variables permitidas, texto predeterminado] */
    public const DEFINITIONS = [
        'menu' => ['Bienvenida', 'Respuesta a cualquier mensaje que no pide la historia.', [],
            "Hola, soy el asistente de *CitaYa*.\n\nPara solicitar tu historia clínica escribe *HISTORIA* y te enviaré el enlace del formulario."],
        'form_link' => ['Enlace al formulario', 'Respuesta cuando el paciente pide su historia.', ['enlace', 'vigencia'],
            "Con gusto te ayudamos con tu historia clínica.\n\nCompleta este formulario con los datos de tu atención:\n{enlace}\n\nEl enlace es personal y vence en {vigencia} horas. Si encontramos tu historia te la enviaremos por este chat y al correo que indiques."],
        'history_found' => ['Historia encontrada', 'Primer mensaje antes de enviar los PDF por WhatsApp.', ['radicado', 'nombre', 'documentos', 'correo'],
            "Encontramos tu historia clínica. Radicado *{radicado}*.\n\nTe enviamos {documentos} documento(s) por este chat y también al correo {correo}. Consérvalos en un lugar privado."],
        'pending' => ['Solicitud con radicado', 'Cuando la historia no está cargada y la solicitud queda para gestión.', ['radicado', 'nombre', 'horas'],
            "Recibimos tu solicitud de historia clínica.\n\nNo encontramos una historia clínica cargada con los datos que indicaste, por eso quedó registrada para gestión con el radicado:\n\n*{radicado}*\n\nGuarda este número. El tiempo estimado de respuesta es de *{horas} horas*: cuando nuestro equipo cargue tu historia te la enviaremos automáticamente por este chat y a tu correo."],
        'response_approved' => ['Historia aprobada', 'Texto sugerido al responder una solicitud como «Aprobado» con los documentos adjuntos.', ['radicado', 'nombre'],
            "Hola, {nombre}.\n\nTu solicitud *{radicado}* fue aprobada. Te enviamos adjuntos los documentos de tu historia clínica.\n\nConsérvalos en un lugar privado."],
        'not_registered' => ['No registra', 'Respuesta de la persona encargada cuando el nombre no coincide con el documento o el paciente no existe.', ['radicado', 'nombre', 'enlace'],
            "Respuesta a tu solicitud *{radicado}*.\n\nNo encontramos una historia clínica registrada con el número de documento y el nombre que indicaste.\n\nVerifica nuevamente tu *número de documento* y escribe tus nombres y apellidos completos tal como aparecen en tu documento. Puedes hacer una nueva solicitud aquí:\n{enlace}"],
    ];

    public function __construct(private readonly Application $app) {}

    public function body(string $code): string
    {
        if (!isset(self::DEFINITIONS[$code])) throw new \InvalidArgumentException('Plantilla desconocida.');
        $saved = $this->app->db->one('SELECT body FROM message_templates WHERE code=?', [$code]);
        return $saved['body'] ?? self::DEFINITIONS[$code][3];
    }

    public function isCustom(string $code): bool
    {
        return (bool)$this->app->db->one('SELECT code FROM message_templates WHERE code=?', [$code]);
    }

    /** Reemplaza solo las variables permitidas de la plantilla. */
    public function render(string $code, array $values = []): string
    {
        $allowed = self::DEFINITIONS[$code][2] ?? [];
        $pairs = [];
        foreach ($allowed as $name) $pairs['{' . $name . '}'] = (string)($values[$name] ?? '');
        return strtr($this->body($code), $pairs);
    }

    /** Versión para correo: sin los asteriscos de negrita de WhatsApp. */
    public function renderPlain(string $code, array $values = []): string
    {
        return preg_replace('/\*([^*\n]+)\*/u', '$1', $this->render($code, $values));
    }

    public function save(string $code, string $body, int $adminId): void
    {
        if (!isset(self::DEFINITIONS[$code])) throw new \DomainException('Plantilla desconocida.');
        $body = trim(str_replace("\r\n", "\n", $body));
        if (mb_strlen($body) < 10 || mb_strlen($body) > 3000) throw new \DomainException('El texto debe tener entre 10 y 3000 caracteres.');
        preg_match_all('/\{([a-z_]+)\}/', $body, $used);
        $unknown = array_diff(array_unique($used[1]), self::DEFINITIONS[$code][2]);
        if ($unknown) throw new \DomainException('Variable no permitida en esta plantilla: {' . implode('}, {', $unknown) . '}.');
        // Sin el enlace el paciente no podría continuar.
        foreach (['form_link', 'not_registered'] as $required) {
            if ($code === $required && !str_contains($body, '{enlace}')) throw new \DomainException('Esta plantilla debe incluir {enlace}.');
        }
        $this->app->db->run('INSERT INTO message_templates (code,body,updated_by,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE body=VALUES(body),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)', [$code, $body, $adminId, $this->app->now()]);
        $this->app->event(null, 'template_saved', $code . ':admin_' . $adminId);
    }

    public function reset(string $code, int $adminId): void
    {
        if (!isset(self::DEFINITIONS[$code])) throw new \DomainException('Plantilla desconocida.');
        $this->app->db->run('DELETE FROM message_templates WHERE code=?', [$code]);
        $this->app->event(null, 'template_reset', $code . ':admin_' . $adminId);
    }

    /** Valores de ejemplo para la vista previa. */
    public static function sample(): array
    {
        return ['radicado' => 'HC-261006-A1B2C3D4E5', 'nombre' => 'Paciente Demo', 'horas' => '24', 'vigencia' => '6',
            'enlace' => 'https://portal.ejemplo/?page=home', 'correo' => 'paciente@correo.com', 'documentos' => '2'];
    }
}
