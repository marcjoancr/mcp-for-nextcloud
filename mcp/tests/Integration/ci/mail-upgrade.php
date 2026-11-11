<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
$failureHandler=static function(Throwable $error):void {fwrite(STDERR,'UPGRADE_PROBE_FAILED '.get_class($error)."\n");exit(1);};
set_exception_handler($failureHandler);
// Dedicated disposable v9 -> v11 upgrade/recovery probe. Never run against production.
if(getenv('MCP_MAIL_UPGRADE_ACCEPTANCE')!=='1'||posix_geteuid()!==1000)throw new RuntimeException('Explicit disposable upgrade marker and service UID required.');
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
set_exception_handler($failureHandler);
use OCP\{Server,IConfig,IDBConnection};
$c=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);$apps=Server::get(\OCP\App\IAppManager::class);$apps->loadApp('mcp');$apps->loadApp('mail');
set_exception_handler($failureHandler);
if($db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES||$c->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new RuntimeException('PostgreSQL and transmission disabled required.');
$mode=$argv[1]??'';$file=$argv[2]??'';
if(!in_array($mode,['seed','snapshot','verify-v9','verify-v11'],true)||!str_starts_with($file,'/tmp/mcp-mail-upgrade-')||is_link($file))throw new RuntimeException('Closed upgrade probe input required.');
$uid='mcp-it-alice';Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));$prefix=$c->getSystemValueString('dbtableprefix','oc_');if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$prefix))throw new RuntimeException('Unsafe fixture prefix.');
function rows(string $sql,array $params=[]):array { $r=Server::get(IDBConnection::class)->executeQuery($sql,$params);try{return $r->fetchAll();}finally{$r->closeCursor();} }
function digestRows(string $table,array $columns):array {
    foreach([$table,...$columns]as$name)if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$name))throw new RuntimeException('Unsafe fixture identifier.');
    $records=rows('SELECT '.implode(',',array_map(static fn($v)=>'"'.$v.'"',$columns)).' FROM "'.$table.'"');$hashes=[];
    foreach($records as$row){ksort($row);$hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));}sort($hashes);
    return ['rows'=>count($hashes),'sha256'=>hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR))];
}
function snapshot(string $prefix):array {
    $result=[];foreach(rows('SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema() ORDER BY table_name')as$t){$name=$t['table_name'];if(!str_starts_with($name,$prefix.'mail_')&&!str_starts_with($name,$prefix.'mcp_'))continue;
        $columns=array_column(rows('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? ORDER BY column_name',[$name]),'column_name');$result[$name]=['columns'=>$columns]+digestRows($name,$columns);
    }return $result;
}
$root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$path='task7-upgrade-synthetic.txt';
if($mode==='seed'){
    if($apps->getAppVersion('mcp')!=='2.7.0'||$apps->getAppVersion('mail')!=='5.12.5.9'||file_exists($file)||$root->nodeExists($path))throw new RuntimeException('Fresh exact v9 baseline required.');
    $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$policy->setGrant($uid,'mail','read',true);
    // Create a real persistent OAuth connection through baseline consent/PKCE, then retain only DB hashes.
    $clients=Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class);
    if(!\OCA\Mcp\OAuth\ClientResolver::nativeEnabled($clients))Server::get(\OCA\Mcp\OAuth\OAuthClientsService::class)->update(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],null,true,null);
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $redirect=\OCA\Mcp\OAuth\NativeClient::REDIRECT_URIS[0];$resource='http://localhost/apps/mcp/';$tokens=Server::get(\OCA\Mcp\OAuth\TokenService::class);
    $code=$tokens->consent(new \OCA\Mcp\OAuth\AuthorizationRequest(\OCA\Mcp\OAuth\NativeClient::metadata((string)\OCA\Mcp\OAuth\ClientResolver::nativeGeneration($clients)),$redirect,'task7-upgrade',$challenge,$resource,'mcp'),$uid,['mail_list_accounts'],[]);
    if(!is_string($code))throw new RuntimeException('Synthetic baseline consent failed.');
    $issued=$tokens->exchangeCode(['code'=>$code,'code_verifier'=>$verifier,'client_id'=>\OCA\Mcp\OAuth\NativeClient::CLIENT_ID,'redirect_uri'=>$redirect,'resource'=>$resource]);if(!isset($issued['access_token']))throw new RuntimeException('Synthetic baseline exchange failed.');unset($issued,$code,$verifier);
    $data=$root->newFile($path,'Synthetic S3 upgrade preservation. No production content.');
    Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],'admin',$uid,'files',[(string)$root->getId()]);
    $account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];$manager=Server::get('OCA\\Mail\\Service\\MailManager');$box=$manager->createMailbox($account,'task7-upgrade-synthetic');
    $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);$imap=Server::get('OCA\\Mail\\IMAP\\MessageMapper');$uids=[];
    try {foreach([1,2]as$i)$uids[]=$imap->save($client,$box,"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: Upgrade $i\r\nMessage-ID: <same-upgrade@example.test>\r\n\r\nSynthetic upgrade $i",[]);Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer')->sync($account,$client,$box,Server::get(\Psr\Log\LoggerInterface::class));}finally{$client->logout();}
    $messages=Server::get('OCA\\Mail\\Db\\MessageMapper')->findByUids($box,$uids);if(count($messages)!==2)throw new RuntimeException('Two exact synthetic cache rows required.');
    $tag=$manager->createTag('Task7 upgrade history','#123456',$uid);foreach($messages as$message)$manager->tagMessage($account,$box->getName(),$message,$tag,true);
    // Preserve an explicitly unverified legacy cache row; upgrade must never invent its generation/profile.
    $legacy=(int)$messages[1]->getId();$db->executeStatement('UPDATE "'.$prefix.'mail_messages" SET tag_cache_generation=NULL,tag_cache_profile=NULL WHERE id=?',[$legacy]);
    $history=$db->getQueryBuilder();$history->insert('mail_message_tags')->setValue('imap_message_id',$history->createNamedParameter('<legacy-upgrade@example.test>'))->setValue('tag_id',$history->createNamedParameter($tag->getId()))->setValue('cached_message_id',$history->createNamedParameter(null))->executeStatement();
    $state=['schemaVersion'=>1,'baselineMcp'=>'2.7.0','baselineNative'=>'5.12.5.9','uid'=>$uid,'fileId'=>(int)$data->getId(),'fileSha256'=>hash('sha256',$data->getContent()),'mailboxId'=>(int)$box->getId(),'messageIds'=>array_map(static fn($m)=>(int)$m->getId(),$messages),'legacyMessageId'=>$legacy,'tagId'=>(int)$tag->getId(),'tables'=>snapshot($prefix)];
    file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");chmod($file,0600);
    echo json_encode(['seeded'=>true,'tables'=>count($state['tables']),'messages'=>2,'unknownGenerationPreserved'=>true,'snapshotSha256'=>hash_file('sha256',$file)],JSON_THROW_ON_ERROR)."\n";exit;
}
if($mode==='snapshot'){
    if(file_exists($file))throw new RuntimeException('Unique snapshot required.');$state=['schemaVersion'=>1,'tables'=>snapshot($prefix),'fileId'=>(int)$root->get($path)->getId(),'fileSha256'=>hash('sha256',$root->get($path)->getContent())];file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");chmod($file,0600);echo json_encode(['snapshotSha256'=>hash_file('sha256',$file),'tables'=>count($state['tables'])],JSON_THROW_ON_ERROR)."\n";exit;
}
$state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$expected=$mode==='verify-v9'?['2.7.0','5.12.5.9']:['2.8.0','5.12.5.11'];
if([$apps->getAppVersion('mcp'),$apps->getAppVersion('mail')]!==$expected)throw new RuntimeException('Exact candidate versions required.');
foreach($state['tables']as$table=>$proof){$actual=digestRows($table,$proof['columns']);if($actual['rows']!==$proof['rows']||!hash_equals($proof['sha256'],$actual['sha256']))throw new RuntimeException('Preserved old-column table differs: '.$table);}
if((int)$root->get($path)->getId()!==$state['fileId']||!hash_equals($state['fileSha256'],hash('sha256',$root->get($path)->getContent())))throw new RuntimeException('Synthetic S3 object differs.');
if(isset($state['legacyMessageId'])){$legacy=rows('SELECT tag_cache_generation,tag_cache_profile FROM "'.$prefix.'mail_messages" WHERE id=?',[$state['legacyMessageId']]);if(count($legacy)!==1||$legacy[0]['tag_cache_generation']!==null||$legacy[0]['tag_cache_profile']!==null)throw new RuntimeException('Legacy generation was invented.');}
if($mode==='verify-v11'){
    foreach(['mail_mcp_deliveries','mail_mcp_snoozes','mail_mcp_sieve','mail_mcp_body_index','mail_mcp_draft_copies']as$table){$proof=rows('SELECT count(*) AS n FROM "'.$prefix.$table.'"');if((int)$proof[0]['n']!==0)throw new RuntimeException('Upgrade populated a derived queue or store.');}
    $indexes=rows('SELECT indexdef FROM pg_indexes WHERE schemaname=current_schema() AND indexname=?',[$prefix.'mail_mcp_body_fts']);if(count($indexes)!==1||!str_contains($indexes[0]['indexdef'],'USING gin'))throw new RuntimeException('Expected PostgreSQL GIN index absent.');
}
echo json_encode(['verified'=>true,'mcp'=>$expected[0],'native'=>$expected[1],'preservedTables'=>count($state['tables']),'syntheticS3Preserved'=>true,'noLegacyGenerationBackfill'=>true,'operatorEnabled'=>false,'snapshotSha256'=>hash_file('sha256',$file)],JSON_THROW_ON_ERROR)."\n";
