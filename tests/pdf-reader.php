<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('America/Bogota');
require __DIR__.'/TestPdf.php';
$checks=0;
function expectRead(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;echo "OK $label\n";}
function rejectRead(callable $fn,string $label):void{try{$fn();}catch(DomainException){expectRead(true,$label);return;}expectRead(false,$label);}
$reader=new App\ClinicalPdfReader();
$text="CEDIM\nIngreso: ABC123\nFecha historia:10/07/2026 1:00:00 p. m.\nFecha ingreso:Enfermedad general10/07/2026 11:30:00 a. m.\nIdentificación: 001234 Nombres: ANA MARIA Apellidos: PRUEBA\nTipo documento:CCNúmero: 001234\nEspecialidad:ORTOPEDIA Y TRAUMATOLOGIA\nImpreso el 04/08/2026\nProfesional: MEDICO PRUEBA\nIdentificación: 999999\n";
$metadata=$reader->extract([$text]);
$shortDate=str_replace('10/07/2026','5/8/2026',$text);
expectRead($reader->extract([$shortDate])['attended_at']==='2026-08-05 11:30:00','Acepta día y mes sin cero inicial');
$paddedContinuation="CEDIM\nIngreso: ABC123\nFecha historia:05/08/2026\nContinuacion de medicamentos\n";
expectRead($reader->extract([$shortDate,$paddedContinuation])['page_count']===2,'Compara fechas equivalentes con y sin ceros en anexos');
expectRead($reader->extract([str_replace('10/07/2026','7/09/2026',$text)])['attended_at']==='2026-09-07 11:30:00','Acepta día de un dígito y mes de dos dígitos');
rejectRead(fn()=>$reader->extract([str_replace('10/07/2026','31/2/2026',$text)]),'No acepta fechas imposibles sin ceros iniciales');
rejectRead(fn()=>$reader->extract([$shortDate,str_replace('05/08/2026','6/8/2026',$paddedContinuation)]),'Sigue rechazando anexos de otro día con formato corto');
expectRead($metadata['document_number']==='001234','Lee la identificación del paciente, conserva ceros y omite la del médico');
expectRead($metadata['full_name']==='ANA MARIA PRUEBA','Lee nombre y apellidos');
expectRead($metadata['attended_at']==='2026-07-10 11:30:00','Prefiere fecha y hora de ingreso sobre fecha de historia e impresión');
expectRead($metadata['specialty']==='ORTOPEDIA Y TRAUMATOLOGIA','Lee especialidad del profesional');
$continuation="CEDIM\nIngreso: ABC123\nFecha historia:10/07/2026\nContinuacion de medicamentos\n";
expectRead($reader->extract([$text,$continuation])['page_count']===2,'Incluye anexos con el mismo ingreso y fecha');
rejectRead(fn()=>$reader->extract([$text,str_replace('001234','005678',$text)]),'Rechaza páginas de otro paciente');
rejectRead(fn()=>$reader->extract([$text,str_replace('ABC123','OTHER456',$continuation)]),'Rechaza ingresos diferentes');
rejectRead(fn()=>$reader->extract([$text,str_replace('10/07/2026','11/07/2026',$continuation)]),'Rechaza fechas de atención distintas');
rejectRead(fn()=>$reader->extract([$text,'Anexo sin referencias']),'Rechaza anexos sin identificación o referencia comprobable');
rejectRead(fn()=>$reader->extract(['']),'Indica necesidad de OCR para escaneos sin texto');
// Cada error dice en qué página está el problema.
$pageOf=function(array $texts):array{try{(new App\ClinicalPdfReader())->extract($texts);return [null,''];}catch(App\ClinicalPdfException $e){return [$e->page,$e->getMessage()];}};
[$page,$message]=$pageOf([$text,$continuation,str_replace('001234','005678',$text)]);
expectRead($page===3 && str_starts_with($message,'Página 3: es de otro paciente') && str_contains($message,'005678'),'Señala la página de otro paciente y su documento');
[$page,$message]=$pageOf([$text,$continuation,str_replace('ABC123','OTHER456',$continuation)]);
expectRead($page===3 && str_contains($message,'OTHER456'),'Señala la página de otro ingreso');
[$page,$message]=$pageOf([$text,'   ',$continuation]);
expectRead($page===2 && str_contains($message,'parece escaneada'),'Señala la página escaneada sin texto dentro del PDF');
[$page,$message]=$pageOf([$text,$continuation,'Anexo sin referencias']);
expectRead($page===3 && str_contains($message,'no trae los datos del paciente'),'Señala el anexo sin identificación');
[$page,$message]=$pageOf([str_replace('Ingreso: ABC123','',$text)]);
expectRead($page===1 && str_starts_with($message,'Página 1: No se pudo identificar el número de ingreso'),'Los datos de la atención se buscan en la página 1');
[$page,$message]=$pageOf(['',$continuation]);
expectRead($page===1 && str_contains($message,'parece escaneada'),'Una página 1 escaneada se señala como tal');
[$page]=$pageOf(['','']);
expectRead($page===null,'Un PDF sin texto en ninguna página no señala una página concreta');
rejectRead(fn()=>$reader->extract([str_replace('10/07/2026','31/02/2026',$text)]),'Rechaza fechas inválidas');
$path=dirname(__DIR__).'/.runtime/parser-test-'.bin2hex(random_bytes(5)).'.pdf';
try{file_put_contents($path,testPdf('000456','TEST123','NOTA'));$parsed=$reader->read($path);expectRead($parsed['document_number']==='000456' && $parsed['page_count']===1,'Extrae datos desde bytes de un PDF real de prueba');}finally{if(is_file($path))unlink($path);}
echo "\nPASS: $checks comprobaciones del lector PDF.\n";
