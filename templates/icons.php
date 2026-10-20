<?php
// Íconos de la interfaz v2: trazo único de 1.8 px sobre una cuadrícula de 24, heredan el color del texto.
if (!function_exists('ui_icon')) {
    function ui_icon(string $name, int $size = 20, string $class = ''): string
    {
        static $paths = [
            'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
            'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.2 2.8 2.8L16 9.6"/>',
            'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
            'alert' => '<path d="M12 3.5 2.8 19.5h18.4Z"/><path d="M12 10v4.5M12 17.2h.01"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8h.01"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
            'chat' => '<path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.2-4.6a8.5 8.5 0 1 1 16.3-3.9Z"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
            'file' => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8Z"/><path d="M14 3v5h5M8.5 13h7M8.5 16.5h5"/>',
            'files' => '<path d="M9 7h8l3 3v11H9Z"/><path d="M17 7v3h3M5 17V3h9l2 2M12 14h5M12 17h3"/>',
            'upload' => '<path d="M12 15V4M7 9l5-5 5 5"/><path d="M4 15v4.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V15"/>',
            'inbox' => '<path d="M21 12h-5.5l-2 3h-3l-2-3H3"/><path d="M5.6 5h12.8L21 12v6.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5V12Z"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
            'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10.5A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V9M10 13h4"/>',
            'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
            'gauge' => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="m12 14 4-5"/>',
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
            'template' => '<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3.5-3.5a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3.5 3.5a4 4 0 0 0 5.7 5.7l1-1"/>',
            'logout' => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15M10 16l-4-4 4-4M6 12h10"/>',
            'shield' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6Z"/><path d="m9 12 2 2 4-4"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="1.5"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'refresh' => '<path d="M20 12a8 8 0 1 1-2.3-5.6L20 8.7"/><path d="M20 3.5v5.2h-5.2"/>',
            'send' => '<path d="m21 3-7 18-3.5-7.5L3 10Z"/><path d="M21 3 10.5 13.5"/>',
            'ticket' => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h15A1.5 1.5 0 0 1 21 7.5V10a2 2 0 0 0 0 4v2.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 16.5V14a2 2 0 0 0 0-4Z"/><path d="M9 6v12" stroke-dasharray="2 2.2"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
            'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'lock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="1.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3M12 14.5v2.5"/>',
            'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off' => '<path d="M10.6 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.9 3.9M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18"/>',
            'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        ];
        $body = $paths[$name] ?? $paths['info'];
        return '<svg class="icon' . ($class !== '' ? ' ' . $class : '') . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}
if (!function_exists('ui_tone')) {
    /** Tono visual de un estado de solicitud o envío: en curso (ámbar), listo, detenido o en espera. */
    function ui_tone(string $status): string
    {
        return match ($status) {
            'queued', 'sending', 'retry', 'pending_verification' => 'live',
            'sent', 'simulated', 'matched', 'verified' => 'ok',
            'failed', 'uncertain', 'unavailable', 'not_eligible', 'expired', 'cancelled', 'not_registered' => 'stop',
            default => 'wait',
        };
    }
}
if (!function_exists('ui_reopen')) {
    /** Verdadero si la última acción enviada falló y su drawer o modal debe abrirse de nuevo con lo escrito. */
    function ui_reopen(string ...$actions): bool
    {
        return !empty($GLOBALS['error']) && in_array((string)($_POST['action'] ?? ''), $actions, true);
    }
}
if (!function_exists('ui_sheet_head')) {
    /** Encabezado común de drawers y modales: título, descripción opcional y botón de cierre. */
    function ui_sheet_head(string $id, string $title, string $lead = ''): string
    {
        return '<header class="sheet-head"><div><h2 id="' . $id . '-title">' . h($title) . '</h2>' . ($lead !== '' ? '<p>' . h($lead) . '</p>' : '')
            . '</div><a class="sheet-close" href="#" data-close aria-label="Cerrar">' . ui_icon('x', 20) . '</a></header>';
    }
}
if (!function_exists('ui_dialog_attrs')) {
    /** Atributos de un drawer o modal; con $open se muestra al cargar (sin JavaScript también, por el atributo open). */
    function ui_dialog_attrs(string $id, string $kind, bool $open = false, string $extra = ''): string
    {
        return 'id="' . $id . '" class="' . $kind . ($extra !== '' ? ' ' . $extra : '') . '" aria-labelledby="' . $id . '-title"' . ($open ? ' open data-auto-open' : '');
    }
}
if (!function_exists('ui_toast')) {
    /** Toast del servidor. success/info se ocultan solos; error queda hasta cerrarlo. */
    function ui_toast(string $tone, string $title, string $text): string
    {
        $icon = ['success' => 'check-circle', 'error' => 'alert', 'info' => 'info'][$tone] ?? 'info';
        return '<div class="toast ' . $tone . '" role="' . ($tone === 'error' ? 'alert' : 'status') . '" data-toast>' . ui_icon($icon, 20)
            . '<div class="toast-body"><strong>' . h($title) . '</strong><p>' . h($text) . '</p></div>'
            . '<button type="button" class="toast-close" data-toast-close aria-label="Cerrar aviso">' . ui_icon('x', 16) . '</button></div>';
    }
}
