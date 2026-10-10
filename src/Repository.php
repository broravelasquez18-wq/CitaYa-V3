<?php
declare(strict_types=1);
namespace App;

final class Repository
{
    /** Resultado de la última importación, para explicar al encargado qué hizo el sistema. */
    private bool $lastReused = false;
    private array $lastResolved = [];

    public function __construct(private readonly Application $app) {}

    /** Lo que el sistema leyó y hizo con un paquete recién importado. */
    public function summary(int $packageId): array
    {
        $row = $this->app->db->one('SELECT k.version,e.attended_at,e.admission,s.name specialty,p.full_name,p.document_type,p.document_number,
            (SELECT COUNT(*) FROM clinical_files f WHERE f.package_id=k.id) files,(SELECT COALESCE(SUM(f.page_count),0) FROM clinical_files f WHERE f.package_id=k.id) pages
            FROM packages k JOIN encounters e ON e.id=k.encounter_id JOIN patients p ON p.id=e.patient_id JOIN specialties s ON s.id=e.specialty_id WHERE k.id=?', [$packageId]);
        $orders = array_map(fn($type) => MedicalOrders::label($type), array_column($this->app->db->all('SELECT DISTINCT o.order_type FROM clinical_orders o JOIN clinical_files f ON f.id=o.file_id WHERE f.package_id=? ORDER BY o.order_type', [$packageId]), 'order_type'));
        return [
            'patient' => $row['full_name'], 'document' => $row['document_type'] . ' ' . $row['document_number'], 'admission' => $row['admission'],
            'date' => substr($row['attended_at'], 0, 10), 'specialty' => $row['specialty'], 'files' => (int)$row['files'], 'pages' => (int)$row['pages'],
            'version' => (int)$row['version'], 'orders' => $orders, 'reused' => $this->lastReused, 'resolved' => $this->lastResolved,
        ];
    }

    public function registerPackage(array $data, array $uploads): int
    {
        if (empty($data['complete'])) throw new \DomainException('Confirma que los archivos componen el paquete completo de una atención.');
        foreach ($uploads as $upload) if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new \DomainException('No se pudo recibir un archivo. Revisa los límites de carga de PHP.');
        return $this->importPackage(array_map(fn($upload) => ['path'=>$upload['tmp_name'],'name'=>$upload['name']], $uploads));
    }

    /** Internal/CLI entry point. HTTP callers only pass verified PHP uploads above. */
    public function importPackage(array $sources): int
    {
        if (!$sources || count($sources) > 15) throw new \DomainException('Adjunta entre 1 y 15 archivos PDF de una misma atención.');
        $reader = new ClinicalPdfReader(); $files = []; $metadata = null; $hashes = [];
        foreach ($sources as $source) {
            $current = $reader->read($source['path']);
            $hash = hash_file('sha256', $source['path']);
            if (isset($hashes[$hash])) continue;
            $hashes[$hash] = true;
            if ($metadata) {
                foreach (['document_type','document_number','admission'] as $key) if ($metadata[$key] !== $current[$key]) throw new \DomainException('Los PDF pertenecen a pacientes o ingresos diferentes. Carga cada atención por separado.');
                if (substr($metadata['attended_at'],0,10) !== substr($current['attended_at'],0,10) || self::key($metadata['specialty']) !== self::key($current['specialty']) || self::key($metadata['full_name']) !== self::key($current['full_name'])) throw new \DomainException('Los datos de los PDF no coinciden. No se creó un paquete mezclado.');
            } else { $metadata = $current; }
            $files[] = $source + ['sha256'=>$hash,'page_count'=>$current['page_count'],'orders'=>$current['orders']];
        }
        $this->app->identity($metadata);
        $paths = []; $this->lastReused = false; $this->lastResolved = [];
        try {
            $packageId = $this->app->db->transaction(function () use ($metadata,$files,&$paths) {
                $db=$this->app->db;
                // Index the patient from the document without certifying an email address.
                $db->run("INSERT IGNORE INTO patients (document_type,document_number,full_name,email,active) VALUES (?,?,?,'',1)",[$metadata['document_type'],$metadata['document_number'],$metadata['full_name']]);
                $patient=$db->one('SELECT * FROM patients WHERE document_type=? AND document_number=? FOR UPDATE',[$metadata['document_type'],$metadata['document_number']]);
                if (self::key($patient['full_name']) !== self::key($metadata['full_name'])) throw new \DomainException('La identificación coincide con un nombre distinto en el repositorio. Revisa el documento antes de continuar.');
                $db->run('INSERT IGNORE INTO specialties (name) VALUES (?)',[mb_convert_case($metadata['specialty'],MB_CASE_TITLE,'UTF-8')]);
                $specialty=$db->one('SELECT id FROM specialties WHERE name=?',[$metadata['specialty']]);
                $encounter=$db->one('SELECT * FROM encounters WHERE patient_id=? AND admission=? FOR UPDATE',[$patient['id'],$metadata['admission']]);
                if ($encounter && (substr($encounter['attended_at'],0,10)!==substr($metadata['attended_at'],0,10) || (int)$encounter['specialty_id']!==(int)$specialty['id'])) throw new \DomainException('Este ingreso ya existe con otra fecha o especialidad.');
                $encounterId=$encounter ? (int)$encounter['id'] : $db->insert('encounters',['patient_id'=>$patient['id'],'specialty_id'=>$specialty['id'],'attended_at'=>$metadata['attended_at'],'admission'=>$metadata['admission'],'folio'=>$metadata['folio'],'location'=>$metadata['location']]);
                $previous=$db->all('SELECT * FROM packages WHERE encounter_id=? ORDER BY version DESC FOR UPDATE',[$encounterId]);
                foreach($previous as $package) {
                    $existing=$db->all('SELECT id,sha256,storage_name FROM clinical_files WHERE package_id=? ORDER BY sort_order,id',[$package['id']]);
                    $existingHashes=array_column($existing,'sha256'); $newHashes=array_column($files,'sha256');
                    sort($existingHashes); sort($newHashes);
                    if ($existingHashes !== $newHashes || $package['status']==='withdrawn') continue;
                    $filesByHash=array_column($files,null,'sha256');
                    foreach($existing as $i=>$file) {
                        $path=$this->app->config['storage_path'].'/histories/'.$file['storage_name'];
                        if (!is_file($path) || !hash_equals($file['sha256'],hash_file('sha256',$path))) throw new \DomainException('El archivo guardado está incompleto o alterado. No se duplicó la historia.');
                        $db->run('UPDATE clinical_files SET page_count=? WHERE id=?',[$filesByHash[$file['sha256']]['page_count'],$file['id']]);
                    }
                    $db->run("UPDATE packages SET status='withdrawn' WHERE encounter_id=? AND id<>? AND status='available'",[$encounterId,$package['id']]);
                    $db->run("UPDATE packages SET status='available',published_at=COALESCE(published_at,?) WHERE id=?",[$this->app->now(),$package['id']]);
                    $this->app->packageFiles((int)$package['id']);
                    $this->app->event(null,'pdf_indexed','package_'.$package['id']);
                    $this->lastReused = true;
                    return (int)$package['id'];
                }
                $version=$previous ? (int)$previous[0]['version']+1 : 1;
                $packageId=$db->insert('packages',['encounter_id'=>$encounterId,'version'=>$version]);
                foreach($files as $i=>$file) {
                    $name=bin2hex(random_bytes(16)).'.pdf'; $target=$this->app->config['storage_path'].'/histories/'.$name;
                    if (!copy($file['path'],$target)) throw new \RuntimeException('No se pudo guardar el PDF.');
                    $paths[]=$target;
                    if (!hash_equals($file['sha256'],hash_file('sha256',$target))) throw new \DomainException('No se pudo conservar la integridad del PDF.');
                    $fileId=$db->insert('clinical_files',['package_id'=>$packageId,'storage_name'=>$name,'original_name'=>mb_substr(basename($file['name']),0,190),'category'=>'historia_completa','sort_order'=>$i+1,'size_bytes'=>filesize($target),'sha256'=>$file['sha256'],'page_count'=>$file['page_count']]);
                    foreach($file['orders'] as $order) $db->insert('clinical_orders',['file_id'=>$fileId,'order_type'=>$order['type'],'page'=>$order['page']]);
                }
                $db->run("UPDATE packages SET status='withdrawn' WHERE encounter_id=? AND status='available'",[$encounterId]);
                $db->run("UPDATE packages SET status='available',published_at=? WHERE id=?",[$this->app->now(),$packageId]);
                $this->app->packageFiles($packageId);
                $this->app->event(null,'pdf_indexed','package_'.$packageId);
                return $packageId;
            });
        } catch(\Throwable $error) { foreach($paths as $path) if(is_file($path)) unlink($path); throw $error; }
        // La historia ya quedó guardada: un fallo al reprocesar solicitudes pendientes no debe deshacer la carga.
        try { $this->lastResolved = $this->app->resolveWaitingRequests($packageId); }
        catch (\Throwable) { $this->app->event(null,'waiting_requests','error'); }
        return $packageId;
    }

    private static function key(string $value): string { return mb_strtoupper(ClinicalPdfReader::normalize(trim($value)),'UTF-8'); }
}
