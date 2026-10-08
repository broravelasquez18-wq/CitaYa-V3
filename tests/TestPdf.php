<?php
declare(strict_types=1);
// Synthetic, text-based PDF fixture. No real patient data.
function testPdf(string $number, string $admission, string $suffix = '', array $extraLines = []): string
{
    $lines=['CEDIM - PRUEBA FICTICIA','Ingreso: '.$admission,'Fecha historia:'.date('d/m/Y').' 08:00:00 a. m.',
        'Identificacion: '.$number.' Nombres: Paciente ficticio Apellidos: de prueba de carga',
        'Tipo documento:CC Numero: '.$number,'Especialidad:ORTOPEDIA Y TRAUMATOLOGIA','DOCUMENTO SIN VALIDEZ MEDICA '.$suffix,...$extraLines];
    $stream="BT /F1 11 Tf 40 780 Td ";
    foreach($lines as $i=>$line) $stream.=($i ? '0 -24 Td ' : '').'('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line).') Tj ';
    $stream.='ET';
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($stream).">>\nstream\n$stream\nendstream"];
    $pdf="%PDF-1.4\n";$offsets=[];
    foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n$object\nendobj\n";}
    $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach($offsets as $offset)$pdf.=sprintf("%010d 00000 n \n",$offset);
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
