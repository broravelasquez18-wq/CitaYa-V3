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
        $reader=new ClinicalPdfReader(); $groups=[]; $errors=[];
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
            } catch(ClinicalPdfException $e){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>$e->getMessage(),'page'=>$e->page];}
            catch(\DomainException $e){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>$e->getMessage()];}
            catch(\Throwable){$errors[]=['files'=>[$name],'previews'=>[$kept($source,$name)],'status'=>'error','reason'=>'No se pudo analizar este PDF. Revisa que no esté dañado.'];}
        }
        // Los PDF que no se pudieron leer no frenan al resto: se guardan las atenciones legibles y cada fallo se informa
        // con su motivo. Si el PDF fallido era un anexo de una atención guardada, hay que volver a subirlo junto con los
        // demás PDF de esa atención: subido solo, el sistema lo tomaría como una versión nueva con ese único archivo.
        foreach($errors as &$error) $error['message']=$error['reason'].' Corrígelo y vuelve a subirlo junto con los demás PDF de su atención.';
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
}
