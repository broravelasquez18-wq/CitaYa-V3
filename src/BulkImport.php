<?php
declare(strict_types=1);
namespace App;

final class BulkImport
{
    public function __construct(private readonly Application $app) {}

    private static function bytes(string $value): int
    {
        $value=trim($value);
        $factor=match(strtolower(substr($value,-1))) {'g'=>1024**3,'m'=>1024**2,'k'=>1024,default=>1};
        return (int)((float)$value*$factor);
    }

    public static function limits(): array
    {
        $post=self::bytes(ini_get('post_max_size'));
        $upload=self::bytes(ini_get('upload_max_filesize'));
        return ['files'=>min(20,max(1,(int)ini_get('max_file_uploads'))),
            'file_bytes'=>min(12*1024**2,$upload>0?$upload:PHP_INT_MAX),
            'total_bytes'=>min(32*1024**2,$post>0?max(0,$post-65536):PHP_INT_MAX)];
    }

    public function receive(array $data,array $uploads): array
    {
        if (($data['complete'] ?? '')!=='1') throw new \DomainException('Confirma que incluiste todos los documentos de cada atención.');
        if ((int)($data['expected_files'] ?? 0)!==count($uploads)) throw new \DomainException('No se recibieron todos los PDF seleccionados. Divide la carga en lotes más pequeños y vuelve a intentarlo.');
        foreach($uploads as $upload) {
            if(($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new \DomainException('No se recibió el lote completo. Revisa los límites de tamaño y vuelve a seleccionar los archivos.');
        }
        return $this->import(array_map(fn($file)=>['path'=>$file['tmp_name'],'name'=>$file['name']],$uploads),true);
    }

    /** CLI/tests may supply local paths; HTTP uses receive to validate real uploads. */
    public function import(array $sources,bool $keepFailed=false): array
    {
        $limits=self::limits();
        if(!$sources || count($sources)>$limits['files']) throw new \DomainException('Selecciona entre 1 y '.$limits['files'].' PDF por lote.');
        $total=0;
        foreach($sources as $source){
            if(!is_file($source['path']) || filesize($source['path'])>$limits['file_bytes']) throw new \DomainException('Uno de los archivos supera el límite de tamaño permitido.');
            $total+=filesize($source['path']);
        }
        if($total>$limits['total_bytes']) throw new \DomainException('El lote supera el tamaño total permitido. Divídelo en lotes más pequeños.');
        $reader=new ClinicalPdfReader(PdfOcr::fromConfig($this->app->config)); $groups=[]; $errors=[];
        // Vista previa en el resultado: los PDF que no se guardan se copian por 24 h para poder abrirlos (UploadPreview).
        $preview=new UploadPreview($this->app);
        $kept=fn(array $source,string $name)=>$keepFailed?$preview->keep($source['path'],$name):['name'=>$name];
        foreach($sources as $source){
            $name=mb_substr(basename($source['name']),0,190);
            try {
                $metadata=$reader->read($source['path']);
                $key=json_encode([$metadata['document_type'],$metadata['document_number'],$metadata['admission']]);
                if(!isset($groups[$key])) $groups[$key]=['patient'=>$metadata['full_name'],'document'=>$metadata['document_type'].' '.$metadata['document_number'],'date'=>substr($metadata['attended_at'],0,10),'admission'=>$metadata['admission'],'sources'=>[],'names'=>[]];
                $groups[$key]['sources'][]=$source;
                $groups[$key]['names'][]=$name;
            } catch(ClinicalPdfMissingData $e){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>$e->getMessage(),'missing'=>$e->missing,'found'=>$e->found];}
            catch(ClinicalPdfException $e){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>$e->getMessage(),'page'=>$e->page];}
            catch(\DomainException $e){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>$e->getMessage()];}
            catch(\Throwable){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>'No se pudo analizar este PDF. Revisa que no esté dañado.'];}
        }
        // Los PDF que no se pudieron leer no frenan al resto: se guardan las atenciones legibles y cada fallo se informa
        // con su motivo. Si el PDF fallido era un anexo de una atención guardada, hay que volver a subirlo junto con los
        // demás PDF de esa atención: subido solo, el sistema lo tomaría como una versión nueva con ese único archivo.
        // Si solo faltan datos, el motivo ya dice cuáles y que se pueden escribir a mano en el resultado.
        foreach($errors as &$error) $error['message']=$error['reason'].(isset($error['missing']) ? '' : ' Corrígelo y vuelve a subirlo junto con los demás PDF de su atención.');
        unset($error);
        $report=['file_count'=>count($sources),'saved'=>0,'reused'=>0,'failed'=>count($errors),'unreadable'=>count($errors),'blocked'=>0,'results'=>$errors];
        $repository=new Repository($this->app);
        foreach($groups as $group){
            $result=['files'=>$group['names'],'patient'=>$group['patient'],'document'=>$group['document'],'date'=>$group['date']];
            try {
                $before=(int)$this->app->db->one('SELECT COALESCE(MAX(id),0) n FROM packages')['n'];
                $id=$repository->importPackage($group['sources']);
                $reused=$id<=$before;
                $result+=['status'=>$reused?'reused':'saved','package_id'=>$id,'message'=>$reused?'Ya estaba registrada; no se duplicó.':'Historia completa registrada.','summary'=>$repository->summary($id)];
                $result['previews']=$result['summary']['previews'];
                $report[$reused?'reused':'saved']++;
            } catch(\DomainException $e){$result+=['status'=>'error','message'=>$e->getMessage(),'previews'=>array_map($kept,$group['sources'],$group['names'])];$report['failed']++;}
            catch(\Throwable){$result+=['status'=>'error','message'=>'No se pudo guardar esta atención. Vuelve a intentarlo con todos sus PDF.','previews'=>array_map($kept,$group['sources'],$group['names'])];$report['failed']++;}
            $report['results'][]=$result;
        }
        return $report;
    }

    /**
     * Carga un PDF del resultado al que le faltaban datos, con los que la persona escribió a mano ($input).
     * Solo completan lo que el PDF no trae. Si la atención ya existe, el PDF se agrega a sus archivos: cargado solo,
     * reemplazaría la historia completa por una versión con un único archivo. Devuelve la fila nueva del resultado.
     */
    public function complete(string $ref,array $input,array $session,int $adminId): array
    {
        $row=null;
        foreach($session['bulk_report']['results'] ?? [] as $result) if(!empty($result['missing']) && ($result['previews'][0]['ref'] ?? '')===$ref) $row=$result;
        $file=$row ? (new UploadPreview($this->app))->resolve($ref,$session) : null;
        if(!$row || !$file) throw new \DomainException('Ese PDF ya no está disponible para completar (se guarda 24 horas). Vuelve a subirlo.');
        $manual=array_intersect_key($input,array_flip(['document_type','document_number','full_name','admission','attended_at','specialty']));
        if(!array_filter(ClinicalPdfReader::manual($manual))) throw new \DomainException('Escribe los datos que faltan.');
        $source=['path'=>$file['path'],'name'=>$file['name'],'manual'=>$manual];
        $metadata=(new ClinicalPdfReader(PdfOcr::fromConfig($this->app->config)))->read($file['path'],$manual);
        // La atención ya registrada (mismo documento e ingreso): sus archivos van junto con este PDF.
        $sources=[];
        $package=$this->app->db->one("SELECT pk.id,pa.document_type,pa.document_number,pa.full_name,e.admission,e.attended_at,s.name specialty FROM packages pk
            JOIN encounters e ON e.id=pk.encounter_id JOIN patients pa ON pa.id=e.patient_id JOIN specialties s ON s.id=e.specialty_id
            WHERE pa.document_type=? AND pa.document_number=? AND e.admission=? AND pk.status='available' ORDER BY pk.version DESC LIMIT 1",[$metadata['document_type'],$metadata['document_number'],$metadata['admission']]);
        // Los PDF ya registrados se releen con los datos de su atención: alguno pudo cargarse con datos escritos a mano.
        $registered=$package ? ['document_type'=>$package['document_type'],'document_number'=>$package['document_number'],'full_name'=>$package['full_name'],
            'admission'=>$package['admission'],'attended_at'=>substr($package['attended_at'],0,10),'specialty'=>$package['specialty']] : [];
        if($package) foreach($this->app->db->all('SELECT storage_name,original_name FROM clinical_files WHERE package_id=? ORDER BY sort_order,id',[$package['id']]) as $existing) {
            $sources[]=['path'=>$this->app->config['storage_path'].'/histories/'.$existing['storage_name'],'name'=>$existing['original_name'],'manual'=>$registered];
        }
        $sources[]=$source;
        $repository=new Repository($this->app);
        $id=$repository->importPackage($sources);
        $typed=array_keys(array_filter(ClinicalPdfReader::manual($manual)));
        $this->app->event(null,'pdf_manual_data','package_'.$id.':admin_'.$adminId.':'.implode(',',array_intersect($typed,array_merge(...array_map(fn($field)=>$field==='document'?['document_type','document_number']:[$field],$row['missing'])))));
        $summary=$repository->summary($id);
        return ['files'=>[$file['name']],'previews'=>$summary['previews'],'status'=>'saved','package_id'=>$id,'patient'=>$metadata['full_name'],
            'document'=>$metadata['document_type'].' '.$metadata['document_number'],'date'=>substr($metadata['attended_at'],0,10),'summary'=>$summary,
            'message'=>'Cargado con datos escritos a mano ('.implode(', ',array_map(fn($field)=>ClinicalPdfReader::FIELDS[$field],$row['missing'])).').'.($package ? ' Se agregó a la atención que ya estaba registrada.' : '')];
    }
}
