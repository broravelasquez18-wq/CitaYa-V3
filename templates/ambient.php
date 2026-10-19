<?php // Fondo animado común del inicio de sesión y del formulario del paciente: halos, barrido, pastillas, cápsulas y chispas.
// En la consola interna ($ambientVariant = 'console') van menos formas y chispas: detrás hay datos que leer.
$ambientConsole = ($ambientVariant ?? 'full') === 'console';
$ambientShapes = $ambientConsole ? ['capsule c1', 'tablet t1', 'capsule c4', 'tablet t3'] : ['capsule c1', 'capsule c2', 'tablet t1', 'capsule c3', 'tablet t2', 'capsule c4', 'tablet t3', 'capsule c5', 'tablet t4', 'capsule c6'];
?>
<div class="login-glow" aria-hidden="true"><span class="glow glow-a"></span><span class="glow glow-b"></span><span class="glow glow-c"></span><span class="grid-sweep"></span><?php foreach ($ambientShapes as $shape): ?><b class="med <?= $shape ?>"></b><?php endforeach ?><?php for ($i = 1; $i <= ($ambientConsole ? 14 : 14); $i += $ambientConsole ? 2 : 1): ?><i class="spark s<?= $i ?>"></i><?php endfor ?></div>
