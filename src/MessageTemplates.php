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
        'menu' => ['Bienvenida (menú)', 'Respuesta a cualquier mensaje que no es una opción. La opción 1 envía el formulario y la 2 inicia el reporte de error.', [],
            "Hola 👋 Soy el asistente de *CitaYa*.\n\n*1.* Solicitar historia clínica\n*2.* Reportar un error en mi historia"],
        'form_link' => ['Enlace al formulario', 'Respuesta cuando el paciente pide su historia.', ['enlace', 'vigencia'],
            "Completa tus datos aquí:\n{enlace}\n\nEl enlace vence en {vigencia} horas."],
        'history_found' => ['Historia encontrada', 'Primer mensaje antes de enviar los PDF por WhatsApp.', ['radicado', 'nombre', 'documentos', 'correo'],
            "Tu historia clínica está lista (radicado *{radicado}*). Te enviamos {documentos} documento(s) por aquí y al correo {correo}."],
        'pending' => ['Solicitud con radicado', 'Cuando la historia no está cargada y la solicitud queda para gestión.', ['radicado', 'nombre', 'horas'],
            "Recibimos tu solicitud. Radicado: *{radicado}*.\n\nAún no tenemos tu historia cargada. Te la enviaremos por aquí y al correo en máximo *{horas} horas*."],
        'response_approved' => ['Historia aprobada', 'Texto sugerido al responder una solicitud como «Aprobado» con los documentos adjuntos.', ['radicado', 'nombre'],
            "Hola, {nombre}. Tu solicitud *{radicado}* fue aprobada. Te enviamos los documentos de tu historia clínica."],
        'not_registered' => ['No registra', 'Respuesta de la persona encargada cuando el nombre no coincide con el documento o el paciente no existe.', ['radicado', 'nombre', 'enlace'],
            "Solicitud *{radicado}*.\n\nNo encontramos una historia clínica con ese documento y nombre. Revisa tus datos y haz una nueva solicitud:\n{enlace}"],
        'nr_document' => ['No registra: número de documento', 'Motivo «No Registra»: no hay historias con el número de documento indicado.', ['radicado', 'nombre', 'documento', 'enlace'],
            "Solicitud *{radicado}*.\n\nNo encontramos historias con el documento *{documento}*. Revísalo y haz una nueva solicitud:\n{enlace}"],
        'nr_name' => ['No registra: nombre', 'Motivo «No Registra»: el documento existe, pero el nombre no coincide.', ['radicado', 'nombre', 'documento', 'enlace'],
            "Solicitud *{radicado}*.\n\nEl nombre *{nombre}* no coincide con el documento *{documento}*. Haz una nueva solicitud con tu nombre completo:\n{enlace}"],
        'nr_date' => ['No registra: fecha de atención', 'Motivo «No Registra»: no hay atención en la fecha indicada.', ['radicado', 'nombre', 'fecha', 'enlace'],
            "Solicitud *{radicado}*.\n\nNo encontramos una atención el *{fecha}*. Revisa la fecha y haz una nueva solicitud:\n{enlace}"],
        'nr_specialty' => ['No registra: especialidad', 'Motivo «No Registra»: no hay atención en la especialidad indicada.', ['radicado', 'nombre', 'especialidad', 'enlace'],
            "Solicitud *{radicado}*.\n\nNo encontramos una atención en *{especialidad}*. Revisa la especialidad y haz una nueva solicitud:\n{enlace}"],
        'order_formula' => ['Orden: fórmula médica', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Reclama tus medicamentos en la farmacia de tu EPS. Lleva esta fórmula y tu documento."],
        'order_laboratorio' => ['Orden: laboratorio', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Pide la cita en el laboratorio de tu EPS y pregunta si debes ir en ayunas."],
        'order_imagenes' => ['Orden: imágenes diagnósticas', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Pide una cita en tu EPS para hacerte este examen."],
        'order_procedimiento' => ['Orden: procedimiento', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Pide la autorización en tu EPS y luego agenda el procedimiento."],
        'order_terapia' => ['Orden: terapias', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Pide la autorización en tu EPS y agenda tus sesiones."],
        'order_remision' => ['Orden: remisión o interconsulta', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Pide la cita con el especialista en tu EPS."],
        'order_incapacidad' => ['Orden: incapacidad médica', 'Qué debe hacer el paciente con esta orden (una o dos líneas). Va debajo de lo que se le ordenó.', [],
            "👉 Entrega esta incapacidad en tu trabajo."],
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
        foreach (['form_link', 'not_registered', 'nr_document', 'nr_name', 'nr_date', 'nr_specialty'] as $required) {
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
            'enlace' => 'https://portal.ejemplo/?page=home', 'correo' => 'paciente@correo.com', 'documentos' => '2',
            'documento' => 'CC 1000000000', 'fecha' => '15/09/2026', 'especialidad' => 'Medicina general'];
    }
}
