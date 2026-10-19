<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$base = 'http://localhost/CitaYaV2/';
$count = 0;
function expect(bool $ok, string $name): void { global $count; if (!$ok) throw new RuntimeException('FAIL: '.$name); $count++; echo "OK $name\n"; }
function requestUrl(string $url, ?array $post = null, ?string $cookie = null): array {
    $handle=curl_init($url); curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10]);
    if($post!==null) {curl_setopt($handle,CURLOPT_POST,true);curl_setopt($handle,CURLOPT_POSTFIELDS,http_build_query($post));}
    if($cookie)curl_setopt($handle,CURLOPT_COOKIE,$cookie);
    $raw=curl_exec($handle); if($raw===false)throw new RuntimeException(curl_error($handle));
    $size=curl_getinfo($handle,CURLINFO_HEADER_SIZE);$status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
    return [$status,substr($raw,0,$size),substr($raw,$size)];
}
[$status,$headers,$body]=requestUrl($base.'public/');
expect($status===200,'Formulario responde');
expect(str_contains($body,'Alergología'),'Catálogo incluye especialidad del segundo ejemplo');
expect(str_contains($headers,'Content-Security-Policy:'),'Política de contenido presente');
expect(str_contains($headers,'no-store'),'No almacena páginas clínicas en caché');
expect(str_contains(strtolower($headers),'httponly') && str_contains(strtolower($headers),'samesite=strict'),'Cookie de sesión protegida');
preg_match('/Set-Cookie: ([^;]+)/i',$headers,$cookieMatch);$cookie=$cookieMatch[1];
[$badStatus,,$badBody]=requestUrl($base.'public/',['action'=>'request','csrf'=>'invalid'],$cookie);
expect(str_contains($badBody,'sesión del formulario venció'),'Rechaza POST sin CSRF válido');
foreach(['config/local.php','storage/outbox/1.eml','.runtime/first-login.txt','database/schema.sql','vendor/autoload.php','src/bootstrap.php','bin/setup.php','tests/run.php'] as $path) {
    [$status]=requestUrl($base.$path);expect(in_array($status,[403,404],true),'Acceso directo bloqueado: '.$path);
}
foreach(['admin','analytics'] as $page) {
    [$status,$headers]=requestUrl($base.'public/?page='.$page);expect($status===302 && str_contains($headers,'admin-login'),'Exige acceso interno: '.$page);
}
[$status,$headers]=requestUrl($base.'public/?page=appointments');expect($status===302 && str_contains($headers,'home'),'Atenciones requieren una solicitud de esta sesión');
echo "\nPASS: $count comprobaciones HTTP.\n";
