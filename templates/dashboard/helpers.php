<?php
// Formatos y piezas comunes del Dashboard. Son variables (no funciones) porque las pruebas cargan la plantilla varias veces.
$monthNames = ['01'=>'enero','02'=>'febrero','03'=>'marzo','04'=>'abril','05'=>'mayo','06'=>'junio','07'=>'julio','08'=>'agosto','09'=>'septiembre','10'=>'octubre','11'=>'noviembre','12'=>'diciembre'];
$monthShort = ['01'=>'ene','02'=>'feb','03'=>'mar','04'=>'abr','05'=>'may','06'=>'jun','07'=>'jul','08'=>'ago','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dic'];
$monthLabel = $monthNames[substr($analytics['month'], 5, 2)] . ' de ' . substr($analytics['month'], 0, 4);
$fmt = static fn($n) => number_format((float)$n, 0, ',', '.');
$pct = static fn($n) => number_format((float)$n, 1, ',', '.') . '%';
// Colores de las series: las 5 primeras con color; el resto, «Otras» en gris (sc-other).
$colors = ['sc1', 'sc2', 'sc3', 'sc4', 'sc5'];

// Variación frente al mes anterior: [tendencia (up|down|flat), texto]. Si el mes anterior fue 0 no hay base para un porcentaje.
$delta = static function (int $now, int $before): ?array {
    if ($before === 0) return $now === 0 ? ['flat', 'Igual que el mes anterior'] : ['up', '+' . number_format($now, 0, ',', '.') . ' (el mes anterior no hubo)'];
    $change = ($now - $before) / $before * 100;
    if (abs($change) < 0.05) return ['flat', 'Igual que el mes anterior'];
    return [$change > 0 ? 'up' : 'down', ($change > 0 ? '+' : '−') . number_format(abs($change), 1, ',', '.') . '% vs. mes anterior'];
};

// Dona SVG: segmentos con pathLength=100 y 0,6 de separación entre ellos (el espacio en color de superficie).
// $parts: [['label' => …, 'value' => …, 'color' => 'sc1'], …]. Cada segmento lleva su aviso (data-tip) para app.js.
$donut = static function (array $parts, string $label): string {
    $total = array_sum(array_column($parts, 'value'));
    $svg = '<svg class="donut" viewBox="0 0 160 160" role="img" aria-label="' . h($label) . '"><circle class="donut-track" cx="80" cy="80" r="62" pathLength="100"/>';
    $offset = 0;
    $gap = count(array_filter($parts, fn($p) => $p['value'] > 0)) > 1 ? 0.6 : 0;
    foreach ($parts as $part) {
        if ($part['value'] <= 0) continue;
        $share = $part['value'] / $total * 100;
        $length = max(0.2, $share - $gap);
        $svg .= '<circle class="donut-segment ' . $part['color'] . '" cx="80" cy="80" r="62" pathLength="100"'
            . ' stroke-dasharray="' . number_format($length, 3, '.', '') . ' ' . number_format(100 - $length, 3, '.', '') . '"'
            . ' stroke-dashoffset="' . number_format(-$offset, 3, '.', '') . '" tabindex="0" data-tip="' . h($part['label']) . '"'
            . ' data-tip-value="' . h(number_format($part['value'], 0, ',', '.') . ' · ' . number_format($share, 1, ',', '.') . '%') . '"/>';
        $offset += $share;
    }
    return $svg . '</svg>';
};

// Leyenda de una dona: muestra, nombre, cantidad y porcentaje de cada parte.
$legend = static function (array $parts, int $total, string $tag) use ($fmt, $pct): string {
    $items = '';
    foreach ($parts as $part) {
        $items .= '<li><span class="swatch ' . $part['color'] . '"></span><span class="legend-name">' . h($part['label']) . '</span>'
            . '<span class="legend-value">' . $fmt($part['value']) . '</span><span class="legend-share">' . $pct($part['value'] / $total * 100) . '</span></li>';
    }
    return '<' . $tag . ' class="donut-legend">' . $items . '</' . $tag . '>';
};

// Campos ocultos que conservan los otros filtros del Dashboard al enviar uno de sus formularios.
$keepFilters = static function (array $fields): string {
    $html = '<input type="hidden" name="page" value="analytics">';
    foreach ($fields as $name => $value) if ($value !== null) $html .= '<input type="hidden" name="' . $name . '" value="' . h($value) . '">';
    return $html;
};
