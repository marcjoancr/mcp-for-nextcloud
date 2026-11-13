<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
if(getenv('MCP_FORMS_SPOOL_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $db=Server::get(\OCP\IDBConnection::class);$spool=Server::get(\OCA\Mcp\Modules\Forms\Exports\FormsSpool::class);$spool->cleanup();
    $store=Server::get(\OCA\Mcp\Db\FormsJobsMapper::class);$uid='guard-owner';
    // Backend integration only: synthetic source and identity; HTTP authorization has its separate journey.
    $engine=new \OCA\Mcp\Modules\Forms\Exports\FormsExportJobs($store,$spool,new \OCA\Mcp\Modules\Forms\Exports\FormsExportRenderer(),static fn(array $origin):array=>$origin,static fn():int=>time());
    $native=static function(string $action,array $args)use($uid):array {
        if($action==='snapshot')return ['revision'=>'1','form'=>['id'=>4,'ownerId'=>$uid]];
        if($action==='forms_results_metadata')return ['form'=>['id'=>4,'ownerId'=>$uid,'isAnonymous'=>false],'questions'=>[['id'=>1,'type'=>'short','text'=>'Synthetic private question']],'submissionCount'=>205];
        $start=(int)($args['after_id']??0);$end=min(205,$start+(int)$args['limit']);$rows=[];
        for($i=$start+1;$i<=$end;$i++)$rows[]=['id'=>$i,'timestamp'=>1000,'answers'=>[['questionId'=>1,'text'=>'cache-value-'.$i]]];
        return ['submissions'=>$rows,'hasMore'=>$end<205,'nextId'=>$end];
    };
    $stage='summary admission';$args=['id'=>4];$snapshot=$engine->preview($uid,'forms_summarize_results',$args,['revision'=>'1'],$native);
    $id=$engine->start($uid,'forms_summarize_results',$args,$snapshot,bin2hex(random_bytes(32)),['uid'=>$uid],$native)['references']['job_id'];
    for($i=0;$i<3;$i++) {
        $stage='summary step '.$i;$version=$store->get($id)['version'];$key='native-summary-step-'.$i;
        $status=$engine->step($uid,$id,$native,null,$key,$version);
    }
    if(!$status['complete']||$status['done']!==205)throw new RuntimeException('Native checkpoint collection was incomplete.');
    $result=$engine->result($uid,$id,0,100);if(count($result['results'])!==100||!$result['hasMore'])throw new RuntimeException('Native summary pagination failed.');
    $job=$store->get($id);$serialized=json_encode($job['payload'],JSON_THROW_ON_ERROR);
    if(isset($job['payload']['summary'])||str_contains($serialized,'cache-value-205'))throw new RuntimeException('Raw summary answer buckets remained in SQL.');
    $cipher=$db->executeQuery('SELECT payload FROM *PREFIX*mcp_forms_jobs WHERE job_id = ?',[$id])->fetchOne();
    $ledger=$db->executeQuery('SELECT used_bytes,sql_bytes,reserved_bytes FROM *PREFIX*mcp_forms_spool WHERE job_id = ?',[$id])->fetch();
    $blobBytes=(int)$db->executeQuery('SELECT COALESCE(SUM(cipher_bytes),0) FROM *PREFIX*mcp_forms_spool_parts WHERE job_id = ?',[$id])->fetchOne();
    if((int)$ledger['sql_bytes']!==strlen($cipher)||(int)$ledger['used_bytes']!==$blobBytes+strlen($cipher))throw new RuntimeException('Native SQL/blob ciphertext accounting mismatch.');
    $stage='summary expiry';$db->executeStatement('UPDATE *PREFIX*mcp_forms_spool SET expires_at = ? WHERE job_id = ?',[time()-1,$id]);$spool->cleanup();
    $proof=$engine->recover($uid,$id,$key,$version);
    if(($proof['state']??null)!=='succeeded')throw new RuntimeException('Expired native cache hid durable step evidence.');
    $expired=$store->get($id)['payload'];
    foreach(['metadata','summary','summary_artifact','parts','artifact','file_ids'] as $field)if(array_key_exists($field,$expired))throw new RuntimeException('Generated SQL cache content survived expiry.');
    $refused=false;try{$engine->result($uid,$id,0,100);}catch(RuntimeException){$refused=true;}
    if(!$refused)throw new RuntimeException('Expired native summary content was returned.');
    $stage='cancelled reservation';
    $cancelArgs=['id'=>4,'format'=>'csv'];
    $cancelSnapshot=$engine->preview($uid,'forms_download_results',$cancelArgs,['revision'=>'1'],$native);
    $cancelId=$engine->start($uid,'forms_download_results',$cancelArgs,$cancelSnapshot,bin2hex(random_bytes(32)),['uid'=>$uid],$native)['references']['job_id'];
    $engine->step($uid,$cancelId,$native);
    $engine->cancel($uid,$cancelId);
    $cancelLease=$db->executeQuery('SELECT state,reserved_bytes,used_bytes FROM *PREFIX*mcp_forms_spool WHERE job_id = ?',[$cancelId])->fetch();
    if($cancelLease['state']!=='sealed'||(int)$cancelLease['reserved_bytes']>=134217728||(int)$cancelLease['reserved_bytes']<(int)$cancelLease['used_bytes'])throw new RuntimeException('Cancelled read-only job retained its unused reservation.');
    $db->executeStatement('UPDATE *PREFIX*mcp_forms_spool SET expires_at = ? WHERE job_id = ?',[time()-1,$cancelId]);$spool->cleanup();
    echo json_encode(['ok'=>true,'checks'=>['rows'=>205,'summary_blob'=>true,'sql_metered'=>true,'sql_cleanup'=>true,'evidence_after_expiry'=>true,'expired_content_refused'=>true,'cancelled_reservation_released'=>true]],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}
