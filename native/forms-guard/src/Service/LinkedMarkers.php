<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCA\Forms\Db\{Form,FormMapper};
use OCA\Forms\Mcp\{BeforeEffect,Guard,SourceIdentity};
use OCP\{IDBConnection,Server};
use OCP\Files\{File,IRootFolder};

/** Native ownership markers remain authoritative for stock-writer suppression when MCP is unavailable. */
final class LinkedMarkers {
    private const LIMIT = 1048576;
    private ?array $writing = null;

    public function __construct(private IDBConnection $db,private FormsRevisionFence $fence) {}

    private static function bounded(array $value): string {
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if (strlen($json)>self::LIMIT) {throw new \RuntimeException('Linked Forms private payload exceeds limit');}
        return $json;
    }

    private function encode(array $value): string {
        return Server::get(\OCP\Security\ICrypto::class)->encrypt(self::bounded($value));
    }

    private static function identity(string $id): void {
        if (preg_match('/^[a-f0-9]{64}$/D',$id)!==1) {throw new \InvalidArgumentException('Invalid linked Forms identity');}
    }

    public static function runtime(): string {
        if (!SourceIdentity::verify()) {throw new \RuntimeException('Linked Forms native source changed');}
        return hash_file('sha256',dirname(__DIR__,3).'/mcp-guard-installed.json');
    }

    private function row(string $id): ?array {
        self::identity($id);
        $row=$this->db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$id])->fetch();
        if (!$row) {return null;}
        foreach (['form_id','file_id','version','dirty_generation','completed_generation','lease_until','attempted_at'] as $name) {
            $text=(string)$row[$name];
            if (!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$text) || strlen($text)===19 && strcmp($text,'9223372036854775805')>0) {
                throw new \RuntimeException('Linked Forms marker counter is corrupt');
            }
            $row[$name]=(int)$text;
        }
        $cipher=(string)$row['payload'];
        if (strlen($cipher)>2*self::LIMIT) {throw new \RuntimeException('Linked Forms encrypted payload exceeds limit');}
        $plain=Server::get(\OCP\Security\ICrypto::class)->decrypt($cipher);
        if (strlen($plain)>self::LIMIT) {throw new \RuntimeException('Linked Forms private payload exceeds limit');}
        $row['private']=json_decode($plain,true,64,JSON_THROW_ON_ERROR);
        if (($row['private']['association_id']??null)!==$id) {throw new \RuntimeException('Linked Forms envelope identity changed');}
        return $row;
    }

    private function current(string $id): array {
        return $this->row($id)??throw new \RuntimeException('Linked Forms association is unavailable');
    }

    private function save(array $row,array $changes,array $private): array {
        if (!$this->fence->active()) {throw new \LogicException('Linked Forms update requires its native fence');}
        if ($row['version']>=9223372036854775805) {throw new \RuntimeException('Linked Forms version exhausted');}
        $next=array_replace($row,$changes,['version'=>$row['version']+1]);
        $count=$this->db->executeStatement('UPDATE *PREFIX*forms_mcp_linked SET state = ?, version = ?, lease_token = ?, lease_until = ?, attempted_at = ?, completed_generation = ?, payload = ? WHERE association_id = ? AND version = ?',
            [$next['state'],$next['version'],$next['lease_token'],$next['lease_until'],$next['attempted_at'],$next['completed_generation'],$this->encode($private),$row['association_id'],$row['version']]);
        if ($count!==1) {throw new \RuntimeException('Linked Forms claim changed');}
        return $this->current($row['association_id']);
    }

    private function reference(array $claim,bool $exactVersion=true,bool $writing=false): array {
        $row=$this->current((string)($claim['association_id']??''));
        if (!in_array($row['state'],$writing?['writing']:['active'],true)
            || $row['lease_until']<time() || $row['lease_token']===''
            || !is_string($claim['lease_token']??null) || !hash_equals($row['lease_token'],$claim['lease_token'])
            || $exactVersion && $row['version']!==($claim['version']??null)) {
            throw new \RuntimeException('Linked Forms lease is unavailable');
        }
        return $row;
    }

    private function authority(array $row): Form {
        $private=$row['private']; $origin=$private['origin']; $uid=$origin['uid']??'';
        if (($origin['tool']??null)!=='forms_link_results_file' || !is_int($origin['connection']??null) || $origin['connection']<1
            || ($origin['references']['owner_id']??null)!==$uid || ($origin['references']['form_id']??null)!==$row['form_id']
            || ($private['original_owner_uid']??null)!==$uid || ($private['native_runtime']??null)!==self::runtime()) {
            throw new \RuntimeException('Original linked Forms approval changed');
        }
        return OriginContext::within($uid,$origin,'forms_results_metadata',['id'=>$row['form_id']],function()use($uid,$row): Form {
            Server::get(Guard::class)->assertPrincipal($uid);
            $forms=(new ScopedServices($uid,true,true))->forms();
            $form=$forms->getFormIfAllowed($row['form_id'],'edit');
            if ($form->getOwnerId()!==$uid || !in_array('results',$forms->getPermissions($form),true)
                || $form->getFileId()!==$row['file_id'] || $form->getFileFormat()!==$row['file_format']) {
                throw new \RuntimeException('Original linked Forms owner or association changed');
            }
            OriginContext::assertFresh();
            return $form;
        });
    }

    private function claimData(array $row): array {
        $form=Server::get(FormMapper::class)->findById($row['form_id']);
        return ReadPages::bounded(array_intersect_key($row['private'],array_flip(['origin','original_owner_uid','native_runtime','arguments','schema_digest','active_cycle','last_verified','last_receipt','source_revision','destination']))+[
            'association_id'=>$row['association_id'],'form_id'=>$row['form_id'],'version'=>$row['version'],
            'state'=>$row['state'],'lease_token'=>$row['lease_token'],'dirty_generation'=>$row['dirty_generation'],
            'last_completed_generation'=>$row['completed_generation'],'current_owner_uid'=>$form->getOwnerId(),
        ]);
    }

    /** Opaque fair queue. Neither decrypts approval nor resolves an actor or Files node. */
    public function pending(int $limit): array {
        if ($limit<1 || $limit>100) {throw new \InvalidArgumentException('Invalid linked Forms queue limit');}
        $query=$this->db->getQueryBuilder();
        $query->select('association_id')->from('forms_mcp_linked')->where($query->expr()->eq('state',$query->createNamedParameter('active')))
            ->andWhere($query->expr()->gt('dirty_generation','completed_generation'))
            ->andWhere($query->expr()->lt('lease_until',$query->createNamedParameter(time(),\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('attempted_at','ASC')->addOrderBy('association_id','ASC')->setMaxResults($limit);
        return array_column($query->executeQuery()->fetchAll(),'association_id');
    }

    public function claim(string $id): ?array {
        return $this->fence->transaction(function()use($id): ?array {
            $row=$this->row($id);
            if ($row===null || $row['state']!=='active' || $row['lease_until']>=time()
                || $row['dirty_generation']===$row['completed_generation'] && $row['private']['active_cycle']===null) {return null;}
            try {$this->authority($row);} catch (\Throwable) {$this->stop($row,'paused','authority_changed'); return null;}
            $row=$this->save($row,['lease_token'=>bin2hex(random_bytes(32)),'lease_until'=>time()+60,'attempted_at'=>time()],$row['private']);
            return $this->claimData($row);
        });
    }

    public function checkpoint(array $claim,array $cycle): array {
        return $this->fence->transaction(function()use($claim,$cycle): array {
            $row=$this->reference($claim); $this->authority($row);
            self::bounded($cycle); $generation=$cycle['generation']??null;
            $job=is_int($generation)?hash('sha256','forms-job-v1:'.hash('sha256','forms-linked-cycle-v1:'.$row['association_id'].':'.$generation)):'';
            if (array_keys($cycle)!==['job_id','generation'] || !is_int($generation) || $generation<1
                || $generation>$row['dirty_generation'] || $generation<=$row['completed_generation'] || ($cycle['job_id']??null)!==$job
                || $row['private']['active_cycle']!==null && $row['private']['active_cycle']!==$cycle) {
                throw new \RuntimeException('Linked Forms cycle changed');
            }
            $private=$row['private']; $private['active_cycle']=$cycle;
            return $this->claimData($this->save($row,['lease_until'=>time()+60],$private));
        });
    }

    public function release(array $claim): void {
        $this->fence->transaction(function()use($claim): void {
            $row=$this->reference($claim);
            $this->save($row,['lease_token'=>'','lease_until'=>0],$row['private']);
        });
    }

    private function stop(array $row,string $state,string $reason): void {
        $private=$row['private']; $private['pause_reason']=$reason;
        $this->save($row,['state'=>$state,'lease_token'=>'','lease_until'=>0],$private);
    }

    public function pause(array $claim,string $reason): void {
        $this->fence->transaction(function()use($claim,$reason): void {
            $row=$this->reference($claim);
            $uncertain=$reason==='delivery_uncertain';
            $this->stop($row,$uncertain?'write_uncertain':'paused',$uncertain?'delivery_uncertain':'admission_failed');
        });
    }

    public function restart(array $claim,array $cycle,callable $evidence): bool {
        return $this->fence->transaction(function()use($claim,$cycle,$evidence): bool {
            $row=$this->reference($claim); $private=$row['private'];
            $valid=false;
            try {
                $this->authority($row);
                $proof=$evidence(); self::bounded($proof);
                $key=hash('sha256','forms-linked-retire-v1:'.$row['association_id'].':'.($cycle['generation']??''));
                $payload=$proof['payload']??[];
                $valid=$private['active_cycle']===$cycle && ($proof['job_id']??null)===($cycle['job_id']??null)
                    && ($proof['state']??null)==='cancelled' && is_int($proof['version']??null) && $proof['version']>0
                    && ($payload['last_step']['key']??null)===$key
                    && ($payload['last_step']['outcome']['state']??null)==='succeeded'
                    && ($payload['last_step']['outcome']['references']['job_id']??null)===$cycle['job_id']
                    && ($payload['tool']??null)==='forms_link_results_file' && ($payload['native_runtime']??null)===$private['native_runtime']
                    && ($payload['origin']??null)===$private['origin'] && ($proof['actor_uid']??null)===$private['original_owner_uid']
                    && ($proof['sealed']??null)===true && ($proof['delivery_started']??null)===false
                    && $row['dirty_generation']>($cycle['generation']??PHP_INT_MAX);
                $metadata=Server::get(Guard::class)->deferredRead($private['origin'],'forms_results_metadata',['id'=>$row['form_id']]);
                $valid=$valid && hash_equals($private['schema_digest'],self::schemaDigest($metadata));
            } catch (\Throwable) {$valid=false;}
            if (!$valid) {$this->stop($row,'paused','cycle_retirement_unproven'); return false;}
            $private['active_cycle']=null;
            $this->save($row,['lease_token'=>'','lease_until'=>0],$private);
            return true;
        });
    }

    public function read(array $claim,string $action,array $input): array {
        self::bounded($input);
        $writing=$this->writing!==null && ($claim['association_id']??null)===$this->writing['claim']['association_id']
            && ($claim['lease_token']??null)===$this->writing['claim']['lease_token'];
        if ($writing) {$this->fence->assertSession();}
        $row=$this->reference($claim,false,$writing); $this->authority($row);
        if (($input['id']??null)!==$row['form_id']) {throw new \RuntimeException('Linked Forms source changed');}
        $result=Server::get(Guard::class)->deferredRead($row['private']['origin'],$action,$input);
        $after=$this->reference($claim,false,$writing); $this->authority($after);
        return $result;
    }

    public function association(array $origin,int $formId): array {
        $uid=$origin['uid']??'';
        return OriginContext::within($uid,$origin,'forms_link_results_file',['id'=>$formId],function()use($uid,$origin,$formId): array {
            Server::get(Guard::class)->assertPrincipal($uid);
            $forms=(new ScopedServices($uid,true,true))->forms();$form=$forms->getFormIfAllowed($formId,'edit');
            if (($origin['connection']??0)<1 || ($origin['references']['form_id']??null)!==$formId
                || ($origin['references']['owner_id']??null)!==$uid || $form->getOwnerId()!==$uid
                || !in_array('results',$forms->getPermissions($form),true)) {throw new \RuntimeException('Original linked Forms authority changed');}
            $id=$this->tuple($form);
            if ($id===null) {return [];}
            $row=$this->current($id);
            if ($row['private']['origin']!==$origin) {throw new \RuntimeException('Linked Forms origin changed');}
            $this->authority($row);
            return $this->claimData($row);
        });
    }

    public function status(string $uid,int $formId): array {
        $form=(new ScopedServices($uid,false,true))->forms()->getFormIfAllowed($formId,'all');
        if ($form->getOwnerId()!==$uid) {throw new \RuntimeException('Only the current owner can read linked Forms status');}
        $id=$this->tuple($form);
        if ($id===null) {return ['form_id'=>$formId,'state'=>$form->getFileId()===null?'unlinked':'native_ui'];}
        $row=$this->current($id);
        // Even a transferred owner sees no signed proof, private path, hash or file metadata.
        return ['form_id'=>$formId,'association_id'=>$id,'version'=>$row['version'],'state'=>$row['state'],
            'pending'=>$row['dirty_generation']>$row['completed_generation'],'last_completed_generation'=>$row['completed_generation']];
    }

    public function tuple(Form $form): ?string {
        if ($form->getFileId()===null) {return null;}
        $value=$this->db->executeQuery("SELECT association_id FROM *PREFIX*forms_mcp_linked WHERE form_id = ? AND file_id = ? AND file_format = ? AND state <> 'unlinked'",[$form->getId(),$form->getFileId(),$form->getFileFormat()])->fetchOne();
        return $value===false?null:(string)$value;
    }

    /** Called under the already-held native writer fence, before the stock job can switch session. */
    public static function suppress(int $formId): bool {
        $service=Server::get(self::class);
        if (!$service->fence->active()) {throw new \LogicException('Stock Forms admission requires native fence');}
        try {$form=Server::get(FormMapper::class)->findById($formId);} catch (\OCP\AppFramework\Db\DoesNotExistException) {return false;}
        if($form->getFileId()===null){return $service->db->executeQuery('SELECT association_id FROM *PREFIX*forms_mcp_linked WHERE form_id = ? LIMIT 1',[$formId])->fetchOne()!==false;}
        return $service->tuple($form)!==null;
    }

    public function dirty(int $formId): void {
        $this->fence->touchLinkedSource($formId);
    }

    public static function schemaMutation(string $kind,\OCP\AppFramework\Db\Entity $entity,callable $work): mixed {
        return FormsRevisionFence::run(function()use($kind,$entity,$work): mixed {
            $service=Server::get(self::class);
            $query=match($kind) {
                'Question'=>'SELECT form_id FROM *PREFIX*forms_v2_questions WHERE id = ?',
                'Option'=>'SELECT q.form_id FROM *PREFIX*forms_v2_questions q INNER JOIN *PREFIX*forms_v2_options o ON o.question_id = q.id WHERE o.id = ?',
                default=>throw new \LogicException('Unsupported linked Forms schema entity'),
            };
            $before=$entity->getId()===null?false:$service->db->executeQuery($query,[$entity->getId()])->fetchOne();
            $result=$work();
            $after=$service->db->executeQuery($query,[$result->getId()])->fetchOne();
            foreach (array_unique([$before,$after]) as $formId) {if ($formId!==false) {$service->dirty((int)$formId);}}
            return $result;
        });
    }

    public function retire(int $formId): void {
        if (!$this->fence->active()) {throw new \LogicException('Linked Forms retirement requires native fence');}
        $this->db->executeStatement("UPDATE *PREFIX*forms_mcp_linked SET state = 'unlinked', version = version + 1, lease_token = '', lease_until = 0 WHERE form_id = ? AND state <> 'unlinked'",[$formId]);
    }

    /** Retention follows exact file IDs across forms and unlink. An incomplete lookup must refuse deletion. */
    public static function containsOutput(\OCP\Files\Node $node): bool {
        $db=Server::get(IDBConnection::class);$deadline=microtime(true)+20;
        if ($node instanceof File) {
            $query=$db->getQueryBuilder();
            $query->select('association_id')->from('forms_mcp_linked')
                ->where($query->expr()->eq('file_id',$query->createNamedParameter($node->getId(),\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->setMaxResults(1);
            $retained=$query->executeQuery()->fetchOne()!==false;
            if (microtime(true)>$deadline) {throw new \RuntimeException('Linked Forms retention lookup exceeded its deadline');}
            return $retained;
        }
        if (!$node instanceof \OCP\Files\Folder) {throw new \RuntimeException('Linked Forms cleanup node is unavailable');}
        $after='';
        do {
            if (microtime(true)>$deadline) {throw new \RuntimeException('Linked Forms retention lookup exceeded its deadline');}
            $query=$db->getQueryBuilder();
            $query->select('association_id','file_id')->from('forms_mcp_linked')
                ->where($query->expr()->gt('association_id',$query->createNamedParameter($after)))
                ->orderBy('association_id','ASC')->setMaxResults(100);
            $rows=$query->executeQuery()->fetchAll();
            foreach ($rows as $row) {
                if (microtime(true)>$deadline) {throw new \RuntimeException('Linked Forms retention lookup exceeded its deadline');}
                $fileId=filter_var($row['file_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($fileId===false) {throw new \RuntimeException('Linked Forms retained file identity is unavailable');}
                $after=$row['association_id'];
                if ($node->getById($fileId)!==[]) {return true;}
            }
        } while (count($rows)===100);
        if (microtime(true)>$deadline) {throw new \RuntimeException('Linked Forms retention lookup exceeded its deadline');}
        return false;
    }

    public function formMutation(Form $form,string $method,callable $work): mixed {
        return $this->fence->transaction(function()use($form,$method,$work): mixed {
            $before=Server::get(FormMapper::class)->findById($form->getId());
            if($method!=='delete'&&$before->getOwnerId()!==$form->getOwnerId()&&($this->tuple($before)!==null||(OriginContext::active()&&OriginContext::current()['action']==='forms_transfer'))){$form->setFileId(null);$form->setFileFormat(null);}
            $result=$method!=='delete'&&$before->getOwnerId()!==$form->getOwnerId()?NativeHistoryTransition::run($before,$form,$work):$work();BulkEpoch::touch($form->getId());
            $after=$method==='delete'?null:Server::get(FormMapper::class)->findById($form->getId());
            if ($after===null || $before->getFileId()!==$after->getFileId() || $before->getFileFormat()!==$after->getFileFormat()) {
                $this->retire($form->getId());
            } elseif ($before->getOwnerId()!==$after->getOwnerId()) {
                $this->db->executeStatement("UPDATE *PREFIX*forms_mcp_linked SET state = 'paused', version = version + 1, lease_token = '', lease_until = 0 WHERE form_id = ? AND state = 'active'",[$form->getId()]);
            } elseif ($before->getTitle()!==$after->getTitle() || $before->getIsAnonymous()!==$after->getIsAnonymous()) {
                $this->dirty($form->getId());
            }
            return $result;
        });
    }

    public static function schemaDigest(array $metadata): string {
        $canonical=function(mixed $value)use(&$canonical): mixed {
            if (!is_array($value)) {return $value;}
            if (!array_is_list($value)) {ksort($value);}
            foreach ($value as $key=>$child) {$value[$key]=$canonical($child);}
            return $value;
        };
        return hash('sha256','forms-linked-schema-v1:'.json_encode($canonical(array_intersect_key($metadata,array_flip(['form','questions','timeZone']))),JSON_THROW_ON_ERROR));
    }

    private function verifyFile(string $uid,array $receipt,?int $expectedId=null): array {
        self::bounded($receipt);
        $id=$receipt['file_id']??$receipt['fileId']??null; $path=$receipt['path']??null;
        $size=$receipt['size']??$receipt['bytes']??null;
        if (!is_int($id) || $id<1 || $expectedId!==null && $id!==$expectedId || !is_string($path) || $path==='' || !is_int($size) || $size<0 || $size>26214400
            || !is_string($receipt['etag']??null) || !is_string($receipt['sha256']??null) || preg_match('/^[a-f0-9]{64}$/D',$receipt['sha256'])!==1) {
            throw new \RuntimeException('Linked Forms file verification receipt is incomplete');
        }
        $root=Server::get(IRootFolder::class)->getUserFolder($uid); $file=$root->get($path);
        if (!$file instanceof File || (int)$file->getId()!==$id || (string)$file->getEtag()!==$receipt['etag'] || (int)$file->getSize()!==$size || !$file->isReadable()) {
            throw new \RuntimeException('Linked Forms verified file changed');
        }
        StorageAdmission::node($file); StorageAdmission::path($file->getPath());
        $stream=$file->fopen('rb');
        if (!is_resource($stream)) {throw new \RuntimeException('Linked Forms file cannot be verified');}
        try {$hash=hash_init('sha256'); $read=hash_update_stream($hash,$stream,26214401); $sha=hash_final($hash);} finally {fclose($stream);}
        if ($read!==$size || !hash_equals($receipt['sha256'],$sha)) {throw new \RuntimeException('Linked Forms verified bytes changed');}
        return ['file_id'=>$id,'path'=>$path,'etag'=>$receipt['etag'],'sha256'=>$sha,'size'=>$size]+array_intersect_key($receipt,array_flip(['backup']));
    }

    private function assertUnmanaged(int $fileId): void {
        foreach (['forms_v2_answers','forms_v2_uploaded_files','forms_mcp_artifacts'] as $table) {
            if ($this->db->executeQuery('SELECT file_id FROM *PREFIX*'.$table.' WHERE file_id = ?',[$fileId])->fetchOne()!==false) {
                throw new \RuntimeException('A managed Forms attachment cannot become a linked output');
            }
        }
    }

    public function activate(string $uid,array $input,callable $write): array {
        $form=(new ScopedServices($uid))->forms()->getFormIfAllowed((int)$input['id'],'edit');
        if ($form->getOwnerId()!==$uid || $form->getFileId()!==null) {throw new BeforeEffect('Linked Forms requires an unlinked owned form');}
        $scope=new ScopedServices($uid); $scope->forms()->obtainFormLock($form);
        $origin=OriginContext::current()['origin']; $approval=$input['_mcp_link_approval']??[]; self::bounded($approval);
        $arguments=$approval['arguments']??[]; $format=$arguments['format']??null;
        if (($origin['connection']??0)<1 || ($origin['references']['owner_id']??null)!==$uid || ($arguments['id']??null)!==$form->getId()
            || !in_array($format,['csv','ods','xlsx'],true) || ($approval['native_runtime']??null)!==self::runtime()) {
            throw new BeforeEffect('Linked Forms approval is incomplete');
        }
        $metadata=Server::get(Guard::class)->deferredRead($origin,'forms_results_metadata',['id'=>$form->getId()]);
        if (($approval['source_revision']??null)!==$metadata['export_revision'] || ($approval['schema_digest']??null)!==self::schemaDigest($metadata)) {
            throw new BeforeEffect('Linked Forms source approval changed');
        }
        if (($approval['destination']['overwrite']??false)===true) {$this->assertUnmanaged((int)($approval['destination']['file_id']??0));}
        $receipt=$write(); $verified=$this->verifyFile($uid,$receipt);$this->assertUnmanaged($verified['file_id']);
        OriginContext::assertFresh();
        $after=Server::get(Guard::class)->deferredRead($origin,'forms_results_metadata',['id'=>$form->getId()]);
        $fresh=Server::get(FormMapper::class)->findById($form->getId());
        if ($after['export_revision']!==$metadata['export_revision'] || $fresh->getFileId()!==null || $fresh->getOwnerId()!==$uid) {
            throw new \RuntimeException('Linked Forms changed during initial delivery');
        }
        $destination=$approval['destination']??[];
        if (($destination['path']??null)!==$verified['path'] || ($destination['overwrite']??false) && ($destination['file_id']??null)!==$verified['file_id']) {
            throw new \RuntimeException('Linked Forms verified destination differs from approval');
        }
        $id=bin2hex(random_bytes(32));
        $private=['association_id'=>$id,'origin'=>$origin,'original_owner_uid'=>$uid,'arguments'=>$arguments,'native_runtime'=>$approval['native_runtime'],
            'schema_digest'=>$approval['schema_digest'],'source_revision'=>$metadata['export_revision'],'destination'=>$destination,'last_verified'=>$verified,'active_cycle'=>null];
        $form->setFileId($verified['file_id']); $form->setFileFormat($format); Server::get(FormMapper::class)->update($form);
        $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_linked (association_id,form_id,file_id,file_format,state,version,dirty_generation,completed_generation,lease_token,lease_until,attempted_at,payload) VALUES (?,?,?,?,?,1,1,1,?,0,0,?)',
            [$id,$form->getId(),$verified['file_id'],$format,'active','',$this->encode($private)]);
        return ['references'=>['form_id'=>$form->getId(),'file_id'=>$verified['file_id'],'association_id'=>$id,'linked_export'=>$id,'source_revision'=>$metadata['export_revision']],
            'files_phase'=>'saved_verified','data'=>['file'=>$verified,'association_id'=>$id]];
    }

    public function unlink(string $uid,array $input): array {
        $form=(new ScopedServices($uid))->forms()->getFormIfAllowed((int)$input['id'],'edit');
        if ($form->getOwnerId()!==$uid) {throw new BeforeEffect('Only the current owner can unlink Forms results');}
        (new ScopedServices($uid))->forms()->obtainFormLock($form);
        $approved=$input['_mcp_unlink_approval']??[];
        if (($approved['file_id']??null)!==$form->getFileId() || ($approved['file_format']??null)!==$form->getFileFormat()) {throw new BeforeEffect('Linked Forms tuple changed');}
        $id=$this->tuple($form);
        if ($id!==null) {
            $row=$this->current($id);
            if (($approved['association_id']??null)!==$id || ($approved['version']??null)!==$row['version']) {throw new BeforeEffect('Linked Forms marker changed');}
        } elseif (($approved['native_ui']??null)!==true || isset($approved['association_id'])) {throw new BeforeEffect('Native UI unlink requires its exact separate approval');}
        $fileId=$form->getFileId(); $form->setFileId(null); $form->setFileFormat(null); Server::get(FormMapper::class)->update($form); $this->retire($form->getId());
        return ['references'=>['form_id'=>$form->getId(),'file_id'=>$fileId,'association_id'=>$id],'files_phase'=>'preserved','data'=>['state'=>'unlinked']];
    }

    private function source(array $row,string $revision): void {
        $this->authority($row);
        $metadata=Server::get(Guard::class)->deferredRead($row['private']['origin'],'forms_results_metadata',['id'=>$row['form_id']]);
        if ($metadata['export_revision']!==$revision || self::schemaDigest($metadata)!==$row['private']['schema_digest']) {throw new \RuntimeException('Linked Forms source changed');}
    }

    /** Recovery destinations use the canonical original output for the writing-cycle preimage assertion. */
    public function assertWritingOutput(array $origin): void {
        $context=$this->writing??throw new \RuntimeException('Linked Forms write is outside its native cycle');
        $this->fence->assertSession();
        $row=$this->reference($context['claim'],true,true);
        if ($row['private']['origin']!==$origin) {throw new \RuntimeException('Linked Forms recovery origin changed');}
        $root=Server::get(IRootFolder::class)->getUserFolder($row['private']['original_owner_uid']);
        $node=$root->get($row['private']['last_verified']['path']);
        if (!$node instanceof File) {throw new \RuntimeException('Linked Forms original output is unavailable');}
        StorageAdmission::node($node);
        $this->assertWriting($origin,$node);
    }

    /** Refreshed at preflight and the actual content call after its native storage lock and Versions listeners. */
    public function assertWriting(array $origin,File $node): void {
        $context=$this->writing??throw new \RuntimeException('Linked Forms write is outside its native cycle');
        $this->fence->assertSession(); $row=$this->reference($context['claim'],true,true);
        if ($row['private']['origin']!==$origin || (int)$node->getId()!==$row['file_id']) {throw new \RuntimeException('Linked Forms sink changed');}
        $this->source($row,$context['revision']);
        $last=$row['private']['last_verified'];$root=Server::get(IRootFolder::class)->getUserFolder($row['private']['original_owner_uid']);
        if (trim((string)$root->getRelativePath($node->getPath()),'/')!==trim($last['path'],'/') || (string)$node->getEtag()!==$last['etag']) {
            throw new \RuntimeException('Linked Forms output moved or changed');
        }
        // Read the admitted storage directly because the final callback already holds its exclusive lock.
        $stream=$node->getStorage()->fopen($node->getInternalPath(),'rb');
        if (!is_resource($stream)) {throw new \RuntimeException('Linked Forms preimage is unavailable');}
        try {$hash=hash_init('sha256');$size=hash_update_stream($hash,$stream,26214401);$sha=hash_final($hash);} finally {fclose($stream);}
        if ($size!==$last['size'] || !hash_equals($last['sha256'],$sha)) {throw new \RuntimeException('Linked Forms preimage changed');}
    }

    public function sync(array $origin,string $id,int $version,string $revision,string $key,array $destination,callable $write): array {
        self::bounded($destination);
        DeferredFileContext::assert($origin['uid']??'');
        return $this->fence->withLock(function()use($origin,$id,$version,$revision,$key,$destination,$write): array {
            $claim=['association_id'=>$id,'version'=>$version,'lease_token'=>$destination['lease_token']??null];
            $row=$this->fence->transaction(function()use($claim,$origin,$revision,$key,$destination): array {
                $row=$this->reference($claim); $private=$row['private'];
                if ($private['origin']!==$origin || $private['active_cycle']!==($destination['active_cycle']??null) || $private['active_cycle']===null
                    || $key!==hash('sha256','forms-linked-write-v1:'.$row['association_id'].':'.$private['active_cycle']['generation'])) {throw new BeforeEffect('Linked Forms cycle changed');}
                $this->source($row,$revision);
                if ($this->db->executeQuery('SELECT operation_key FROM *PREFIX*forms_mcp_operations WHERE operation_key = ?',[$key])->fetchOne()!==false) {
                    throw new BeforeEffect('Linked Forms cycle already has a durable receipt');
                }
                $this->verifyFile($private['original_owner_uid'],$private['last_verified'],$row['file_id']);
                $private['write_key']=$key; $private['write_revision']=$revision;
                return $this->save($row,['state'=>'writing','lease_until'=>time()+60],$private);
            });
            $this->writing=['claim'=>['association_id'=>$id,'version'=>$row['version'],'lease_token'=>$row['lease_token']],'revision'=>$revision];
            try {
                return $this->fence->transaction(function()use($row,$write,$revision,$key): array {
                    $current=$this->reference($this->writing['claim'],true,true); $this->source($current,$revision);
                    $receipt=$write(); $verified=$this->verifyFile($current['private']['original_owner_uid'],$receipt,$current['file_id']);
                    $this->source($current,$revision);
                    $current=$this->reference($this->writing['claim'],true,true); $private=$current['private'];
                    $generation=$private['active_cycle']['generation'];
                    $actor=OriginContext::within($private['original_owner_uid'],$private['origin'],'forms_link_results_file',['id'=>$current['form_id']],
                        fn()=>OriginContext::identity($private['original_owner_uid']));
                    $receipt=['state'=>'succeeded','operation_key'=>$key,'revision'=>$this->fence->revision(),
                        'references'=>['association_id'=>$current['association_id'],'form_id'=>$current['form_id'],'file_id'=>$current['file_id'],'generation'=>$generation,'source_revision'=>$revision],
                        'phases'=>['database'=>'committed','files'=>'saved_verified','events'=>[]]];
                    $fingerprint=hash_hmac('sha256',self::bounded([$actor,$current['association_id'],$generation,$revision,$verified['sha256']]),Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
                    $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_operations (operation_key,actor_hash,fingerprint,receipt) VALUES (?,?,?,?)',[$key,$actor,$fingerprint,self::bounded($receipt)]);
                    $private['last_verified']=$verified; $private['source_revision']=$revision; $private['active_cycle']=null;
                    $private['last_receipt']=['state'=>'saved_verified','operation_key'=>$key,'file'=>$verified];
                    $this->save($current,['state'=>'active','completed_generation'=>$generation,'lease_token'=>'','lease_until'=>0],$private);
                    return $verified+['state'=>'saved_verified','operation_key'=>$key];
                });
            } catch (\Throwable $error) {
                // If COMMIT succeeded but its acknowledgement was lost, retain the proven completed receipt.
                $this->fence->transaction(function()use($id,$key): void {
                    $current=$this->current($id);
                    if ($current['state']==='writing' && ($current['private']['write_key']??null)===$key) {$this->stop($current,'write_uncertain','delivery_uncertain');}
                });
                throw $error;
            } finally {$this->writing=null;}
        });
    }
}
