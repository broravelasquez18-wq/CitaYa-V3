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
expectRead($reader->extract([$text,'Anexo sin referencias'])['page_count']===2,'Un anexo sin identificación se toma como parte de la atención de las primeras páginas');
rejectRead(fn()=>$reader->extract(['']),'Indica necesidad de OCR para escaneos sin texto');
// Cada error dice en qué página está el problema.
$pageOf=function(array $texts):array{try{(new App\ClinicalPdfReader())->extract($texts);return [null,''];}catch(App\ClinicalPdfException $e){return [$e->page,$e->getMessage()];}};
[$page,$message]=$pageOf([$text,$continuation,str_replace('001234','005678',$text)]);
expectRead($page===3 && str_starts_with($message,'Página 3: es de otro paciente') && str_contains($message,'005678'),'Señala la página de otro paciente y su documento');
[$page,$message]=$pageOf([$text,$continuation,str_replace('ABC123','OTHER456',$continuation)]);
expectRead($page===3 && str_contains($message,'OTHER456'),'Señala la página de otro ingreso');
expectRead($reader->extract([$text,'   ',$continuation])['page_count']===3,'Una página escaneada en medio del PDF se toma como anexo de la atención');
// Los datos que faltan en la página 1 se toman de las primeras páginas.
$withoutIdentity=str_replace(["Tipo documento:CC","Identificación: 001234 Nombres: ANA MARIA Apellidos: PRUEBA"],['',''],$text);
$fromPage2=$reader->extract([$withoutIdentity,$text]);
expectRead($fromPage2['document_number']==='001234' && $fromPage2['full_name']==='ANA MARIA PRUEBA','Toma documento y nombre de la página 2 si la 1 no los trae');
expectRead($reader->extract(['',$text])['document_number']==='001234','Una página 1 escaneada no impide leer los datos de la página 2');
[$page,$message]=$pageOf([str_replace('Ingreso: ABC123','',$text)]);
expectRead($page===null && str_starts_with($message,'No se encontró el número de ingreso en la página 1.') && str_contains($message,'escribirlo a mano'),'Dice qué dato falta cuando no está en ninguna página inicial');
// Datos escritos a mano: solo completan lo que falta y se validan.
$missingAll=null;try{$reader->extract(['','']);}catch(App\ClinicalPdfMissingData $e){$missingAll=$e;}
expectRead($missingAll && $missingAll->missing===['document','full_name','admission','attended_at','specialty'] && $missingAll->found===[],'Un PDF sin texto pide todos los datos');
$typed=$reader->extract(['',''],[],['document_type'=>'cc','document_number'=>'1.088.000','full_name'=>'ana prueba','admission'=>'abc 123','attended_at'=>'2026-07-10','specialty'=>'Ortopedia']);
expectRead($typed['document_number']==='1088000' && $typed['admission']==='ABC123' && $typed['attended_at']==='2026-07-10 00:00:00' && $typed['full_name']==='ANA PRUEBA','Normaliza los datos escritos a mano');
expectRead($reader->extract([$text],[],['document_type'=>'CC','document_number'=>'999','admission'=>'OTRO1'])['document_number']==='001234','Lo escrito a mano no reemplaza lo leído del PDF');
rejectRead(fn()=>App\ClinicalPdfReader::manual(['attended_at'=>date('Y-m-d',strtotime('+2 days'))]),'No acepta una fecha de atención futura escrita a mano');
rejectRead(fn()=>App\ClinicalPdfReader::manual(['document_type'=>'XX','document_number'=>'123']),'No acepta un tipo de documento desconocido');
[$page,$message]=$pageOf(['',$continuation]);
expectRead($page===null && str_contains($message,'tipo y número de documento') && str_contains($message,'primeras páginas (1 a 2)'),'Dice en qué páginas buscó el dato que falta');
// Una página leída con OCR completa datos, pero no rechaza el PDF: el OCR puede leer mal un número.
$ocrRead=$reader->extract([$text,str_replace('001234','00I234',$text)],[1]);
expectRead($ocrRead['document_number']==='001234' && $ocrRead['ocr_pages']===[2],'Una página leída con OCR no rechaza el PDF por un número mal leído');
[$page]=$pageOf(['','']);
expectRead($page===null,'Un PDF sin texto en ninguna página no señala una página concreta');
rejectRead(fn()=>$reader->extract([str_replace('10/07/2026','31/02/2026',$text)]),'Rechaza fechas inválidas');
// Órdenes y fórmulas de Indigo: el encabezado pega «Pagina N/M», la fecha y el ingreso, y deja vacías las etiquetas.
$order="Pagina 1/210/07/2026 1:00:00 p. m.ABC123\nNumero de Folio:1\nFecha Historia:Ingreso:\nTipo Documento:CCNumero: 001234\nFORMULA MEDICA\n";
$orderNext="Pagina 2/210/07/2026 1:00:00 p. m.ABC123\nNumero de Folio:1\nFecha Historia:Ingreso:\nEspecialidad:MEDICINA INTERNA\n";
expectRead($reader->extract([$text,$order,$orderNext])['page_count']===3,'Acepta la continuación de una orden que solo trae el encabezado con fecha e ingreso');
[$page,$message]=$pageOf([$text,$order,str_replace('ABC123','OTHER456',$orderNext)]);
expectRead($page===3 && str_contains($message,'OTHER456'),'En el encabezado de una orden detecta otro ingreso');
[$page,$message]=$pageOf([$text,str_replace('10/07/2026','11/07/2026',$order)]);
expectRead($page===2 && str_contains($message,'11/07/2026'),'En el encabezado de una orden detecta otra fecha aunque traiga el documento');
$path=dirname(__DIR__).'/.runtime/parser-test-'.bin2hex(random_bytes(5)).'.pdf';
try{file_put_contents($path,testPdf('000456','TEST123','NOTA'));$parsed=$reader->read($path);expectRead($parsed['document_number']==='000456' && $parsed['page_count']===1,'Extrae datos desde bytes de un PDF real de prueba');}finally{if(is_file($path))unlink($path);}
echo "\nPASS: $checks comprobaciones del lector PDF.\n";
