<?php
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__).'/vendor/autoload.php';
$config=array_replace(require dirname(__DIR__).'/config/default.php',require dirname(__DIR__).'/config/local.php');
$db=new App\Database($config);
// Temporary tables shadow the real tables only on this test connection.
$db->pdo->exec('CREATE TEMPORARY TABLE specialties (id INT, name VARCHAR(120))');
$db->pdo->exec('CREATE TEMPORARY TABLE encounters (id INT, specialty_id INT, attended_at DATETIME)');
$db->pdo->exec('CREATE TEMPORARY TABLE packages (id INT, encounter_id INT)');
$db->pdo->exec('CREATE TEMPORARY TABLE clinical_files (id INT, package_id INT)');
$db->pdo->exec("INSERT INTO specialties VALUES (1,'Medicina general'),(2,'Ortopedia')");
$db->pdo->exec("INSERT INTO encounters VALUES (1,1,'2026-06-01 00:00:00'),(2,2,'2026-06-30 23:59:59'),(3,2,'2026-06-15 12:00:00'),(4,1,'2026-07-01 00:00:00'),(5,1,'2026-06-10 00:00:00'),(6,1,'2025-06-01 00:00:00')");
$db->pdo->exec('INSERT INTO packages VALUES (1,1),(2,1),(3,2),(4,3),(5,4),(6,6)');
$db->pdo->exec('INSERT INTO clinical_files VALUES (1,1),(2,1),(3,2),(4,3),(5,4),(6,5),(7,6)');
$service=new App\AppointmentAnalytics($db);
function analyticsCheck($ok,$label){if(!$ok)throw new RuntimeException($label);echo "OK $label\n";}
$r=$service->monthly('2026-06');
analyticsCheck($r['total']===3,'Cuenta atenciones sin duplicar anexos ni versiones; excluye registros sin PDF');
analyticsCheck($r['rows'][0]['name']==='Ortopedia' && $r['rows'][0]['total']===2,'Ordena especialidades por demanda registrada');
analyticsCheck(abs($r['rows'][0]['percentage']-200/3)<0.001,'Porcentaje por total del mes');
analyticsCheck(abs(array_sum(array_column($r['rows'],'percentage'))-100)<0.001,'Porcentajes suman 100 antes de redondear');
analyticsCheck($service->monthly('2026-07')['total']===1,'Límites de mes sin incluir el mes anterior');
analyticsCheck($service->monthly('2025-06')['total']===1,'Separa años');
analyticsCheck($service->monthly('2026-08')['total']===0,'Mes vacío sin división por cero');
analyticsCheck($service->monthly()['month']==='2026-07','Selecciona el mes más reciente con PDF');
foreach(['2026-13','2026-00','foo','2026-6','9999-01'] as $invalid){try{$service->monthly($invalid);throw new RuntimeException('Aceptó mes inválido');}catch(DomainException){}}
echo "OK rechaza meses inválidos\n";
function h($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
foreach(['2026-06','2026-07','2026-08'] as $month){$analytics=$service->monthly($month);ob_start();require dirname(__DIR__).'/templates/analytics.php';$html=ob_get_clean();analyticsCheck(!str_contains($html,'NAN') && !str_contains($html,'INF'),'Render válido '.$month);}
$ch=curl_init('http://localhost/CitaYaV2/public/?page=analytics');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);$html=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
analyticsCheck($code===302 && str_contains($html,'admin-login'),'Análisis protegido por sesión administrativa');

$db->pdo->exec('CREATE TEMPORARY TABLE requests (id INT, created_at DATETIME, status VARCHAR(32))');
$db->pdo->exec('CREATE TEMPORARY TABLE jobs (id INT, request_id INT, kind VARCHAR(16), status VARCHAR(24), sent_at DATETIME NULL)');
$db->pdo->exec("INSERT INTO requests VALUES (1,'2026-06-15 00:00:00','failed'),(2,'2026-06-15 23:59:59','sent'),(3,'2026-06-01 00:00:00','queued'),(4,'2026-01-01 00:00:00','expired'),(5,'2025-12-31 23:59:59','sent'),(6,'2027-01-01 00:00:00','sent')");
$db->pdo->exec("INSERT INTO jobs VALUES (1,1,'history','sent','2026-06-15 00:00:00'),(2,1,'history','sent','2026-06-15 23:59:59'),(3,2,'history','simulated','2026-06-15 12:00:00'),(4,2,'otp','sent','2026-06-15 12:00:00'),(5,2,'history','failed','2026-06-15 12:00:00'),(6,3,'history','queued',NULL),(7,3,'history','sent','2026-06-01 00:00:00'),(8,4,'history','sent','2026-12-31 23:59:59'),(9,5,'history','sent','2025-12-31 23:59:59'),(10,6,'history','sent','2027-01-01 00:00:00')");
$activity=$service->activity('2026-06-15');
analyticsCheck($activity['totals']['day']===['requests'=>2,'sent'=>2,'simulated'=>1],'Totales diarios: todos los estados de solicitud, reenvíos individuales y simulaciones separadas');
analyticsCheck($activity['totals']['month']===['requests'=>3,'sent'=>3,'simulated'=>1],'Totales del mes completo, sin OTP, pendientes ni fallidos');
analyticsCheck($activity['totals']['year']===['requests'=>4,'sent'=>4,'simulated'=>1],'Totales del año con límites exclusivos y fecha de envío propia');
analyticsCheck($service->activity('2024-02-29')['totals']['day']===['requests'=>0,'sent'=>0,'simulated'=>0],'Día bisiesto y períodos vacíos');
foreach(['2026-02-29','2026-04-31','2026-6-1','9999-01-01','invalid'] as $date){try{$service->activity($date);throw new RuntimeException('Aceptó fecha inválida');}catch(DomainException){}}
analyticsCheck(true,'Valida fechas de referencia');
$analytics=$service->monthly('2026-06');ob_start();require dirname(__DIR__).'/templates/analytics.php';$html=ob_get_clean();
analyticsCheck(str_contains($html,'Totales de solicitudes y envíos') && str_contains($html,'2026-06-15'),'Render de resumen y filtros conservados');
// Gráficas: crecimiento de 12 meses y canales del mes.
$trend=$service->trend('2026-06');
$june=end($trend);
analyticsCheck(count($trend)===12 && $trend[0]['month']==='2025-07' && $june['month']==='2026-06','Crecimiento: 12 meses que terminan en el mes consultado');
analyticsCheck($june===['month'=>'2026-06','requests'=>3,'sent'=>3,'attentions'=>3] && $june['attentions']===$service->monthly('2026-06')['total'] && $trend[5]['month']==='2025-12' && $trend[5]['requests']===1 && $trend[6]['requests']===1,'Crecimiento: cuenta solicitudes, envíos reales y atenciones por mes, con ceros en meses vacíos');
$db->pdo->exec('ALTER TABLE requests ADD whatsapp_phone VARCHAR(20) NULL');
$db->pdo->exec("UPDATE requests SET whatsapp_phone='573000000000' WHERE id IN (1,4)");
analyticsCheck($service->channels('2026-06')===['whatsapp'=>1,'web'=>2] && $service->channels('2026-08')===['whatsapp'=>0,'web'=>0],'Canales: WhatsApp frente a solo web, y mes vacío en cero');
$channels=$service->channels('2026-06');ob_start();require dirname(__DIR__).'/templates/analytics.php';$html=ob_get_clean();
analyticsCheck(substr_count($html,'class="donut"')===2 && str_contains($html,'data-growth') && str_contains($html,'Ver los datos por mes') && !str_contains($html,'NAN'),'Render con dos donas, la gráfica de crecimiento y la tabla de respaldo');
$analytics=$service->monthly('2026-08');$trend=$service->trend('2026-08');$channels=$service->channels('2026-08');ob_start();require dirname(__DIR__).'/templates/analytics.php';$html=ob_get_clean();
analyticsCheck(str_contains($html,'No hay atenciones registradas') && str_contains($html,'No hubo solicitudes') && !str_contains($html,'NAN') && !str_contains($html,'INF'),'Mes vacío: estados vacíos sin división por cero');
