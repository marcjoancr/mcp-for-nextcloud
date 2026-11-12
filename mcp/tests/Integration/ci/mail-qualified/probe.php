<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Only synthetic fixture metadata is returned. Native exceptions remain private and cannot exit successfully.
$handler=static function(Throwable $e):void{fwrite(STDERR,json_encode(['failed'=>get_class($e)])."\n");exit(70);};
set_exception_handler($handler);
define('OC_CONSOLE',true);require '/app/www/public/lib/base.php';set_exception_handler($handler);
use OCP\{Server,IConfig,IDBConnection};
$apps=Server::get(\OCP\App\IAppManager::class);$apps->loadApp('mcp');$apps->loadApp('mail');set_exception_handler($handler);
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,512,JSON_THROW_ON_ERROR);$action=$argv[1]??'';
$config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);
if($config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new RuntimeException('Operator gate must be off.');
$legacy=static function()use($db):array{
    $q=$db->getQueryBuilder();$r=$q->select('m.*')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id=b.id')->innerJoin('b','mail_accounts','a','b.account_id=a.id')->where($q->expr()->eq('a.user_id',$q->createNamedParameter('mcp-it-legacy')))->orderBy('m.id')->executeQuery();
    try{return $r->fetchAll();}finally{$r->closeCursor();}
};
$hashRow=static function(array $row):string{ksort($row);return hash('sha256',json_encode(array_map(static fn($v)=>$v===null?null:(string)$v,$row),JSON_THROW_ON_ERROR));};
switch($action){
case 'legacy-before':
    if($apps->getAppVersion('mail')!=='5.12.5')throw new RuntimeException('Stock legacy seed required.');
    $rows=$legacy();if($rows===[])throw new RuntimeException('Native stock cache is empty.');
    $proof=['columns'=>array_keys($rows[0]),'rows'=>[]];foreach($rows as$r)$proof['rows'][(string)$r['id']]=$hashRow($r);
    file_put_contents('/tmp/mcp-qualified-legacy.json',json_encode($proof,JSON_THROW_ON_ERROR));chmod('/tmp/mcp-qualified-legacy.json',0600);
    $out=['stockRows'=>count($rows),'checkpointSha256'=>hash_file('sha256','/tmp/mcp-qualified-legacy.json')];break;
case 'migrate':
    if(!$apps->upgradeApp('mail'))throw new RuntimeException('Native registration failed.');
    foreach(['mcp','mail']as$app){$m=new \OC\DB\MigrationService($app,Server::get(\OC\DB\Connection::class));if(array_diff($m->getAvailableVersions(),$m->getMigratedVersions())!==[])throw new RuntimeException('Pending migration.');}
    if($apps->getAppVersion('mail')!=='5.12.5.13')throw new RuntimeException('Unexpected native build.');
    $out=['marker'=>'FULL_NATIVE_REGISTERED_ALL_MIGRATIONS_OK'];break;
case 'legacy-after':
    $proof=json_decode((string)file_get_contents('/tmp/mcp-qualified-legacy.json'),true,512,JSON_THROW_ON_ERROR);$rows=$legacy();$actual=[];
    foreach($rows as$r){if(!array_key_exists('tag_cache_generation',$r)||$r['tag_cache_generation']!==null||$r['tag_cache_profile']!==null)throw new RuntimeException('Legacy provenance was stamped.');$actual[(string)$r['id']]=$hashRow(array_intersect_key($r,array_flip($proof['columns'])));}
    if($actual!==$proof['rows'])throw new RuntimeException('Legacy stock columns changed.');
    $out=['preservedStockRows'=>count($rows),'newProvenanceNull'=>true];break;
case 'source':
    if(array_keys($input)!==['mcp_source_offer','mcp_native_source_offer'])throw new RuntimeException('Exact source configs required.');
    foreach($input as$key=>$value)$config->setSystemValue($key,$value);
    $source=Server::get(\OCA\Mcp\Release\SourceOffer::class);if($source->get()===null||$source->getNative()===null)throw new RuntimeException('Source offer unavailable.');
    $out=['mcpFiles'=>count($source->get()['files']),'nativeFiles'=>count($source->getNative()['files'])];break;
case 'downloads':
    $out=[];
    foreach($input as$name=>$record){if(!preg_match('/^[A-Za-z0-9._-]+$/D',$name))throw new RuntimeException('Unexpected source filename.');$stream=fopen('http://127.0.0.1:8765/'.$name,'rb');$h=hash_init('sha256');$bytes=hash_update_stream($h,$stream);fclose($stream);$sha=hash_final($h);if($bytes!==$record['bytes']||$sha!==$record['sha256'])throw new RuntimeException('Anonymous source download mismatch.');$out[$name]=['bytes'=>$bytes,'sha256'=>$sha];}break;
case 'inventory':
    $out=[];foreach(['mcp','mail']as$app){$root=$apps->getAppPath($app);$actual=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$f){if($f->isLink())throw new RuntimeException('Runtime link forbidden.');if($f->isFile())$actual[substr($f->getPathname(),strlen($root)+1)]=['sha256'=>hash_file('sha256',$f->getPathname()),'bytes'=>$f->getSize()];}ksort($actual);$expected=$input[$app];ksort($expected);if($actual!==$expected)throw new RuntimeException('Full installed inventory differs.');$out[$app]=['files'=>count($actual),'inventorySha256'=>hash('sha256',json_encode($actual,JSON_THROW_ON_ERROR))];}break;
case 'off':
    $out=['operatorEnabled'=>false];break;
default:throw new RuntimeException('Unknown qualification probe.');
}
echo json_encode(['ok'=>true,'result'=>$out],JSON_THROW_ON_ERROR)."\n";
