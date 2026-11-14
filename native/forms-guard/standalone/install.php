<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Run as the Nextcloud service user. Writers must be quiesced through install/validate/rollback and process turnover.
if (PHP_SAPI!=='cli') {exit(2);}
$options=getopt('', ['root:','action:','dry-run']);
$root=realpath($options['root']??'');
$action=$options['action']??'status';
if (!$root || !is_file($root.'/lib/base.php') || !in_array($action,['install','validate','rollback','status','probe','candidate'],true)) {
    fwrite(STDERR,"Usage: php install.php --root=/path/to/nextcloud --action=install|validate|rollback|status [--dry-run]\n");exit(2);
}
function child(array $command): array {
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) {throw new RuntimeException('Cannot start fresh verification process');}
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($process)!==0) {throw new RuntimeException('Fresh verification failed: '.$error.$out);}
    return json_decode($out,true,64,JSON_THROW_ON_ERROR);
}
function atomicFile(string $path,string $bytes): void {
    if (is_link($path)) {throw new RuntimeException('Refusing symbolic extension artifact');}
    $temporary=$path.'.stage-'.bin2hex(random_bytes(6));
    if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes)) {throw new RuntimeException('Cannot stage extension artifact');}
    chmod($temporary,0640);
    if (str_ends_with($path,'.php')) {
        $process=proc_open([PHP_BINARY,'-l',$temporary],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if (proc_close($process)!==0) {unlink($temporary);throw new RuntimeException('Staged extension syntax failed');}
    }
    if (!rename($temporary,$path)) {unlink($temporary);throw new RuntimeException('Cannot publish extension artifact');}
}
try {
    define('OC_CONSOLE',true);require $root.'/lib/base.php';
    $config=\OCP\Server::get(\OCP\IConfig::class);
    $owned='McpFormsServerExtension\\CommentsFactory';
    $default='OC\\Comments\\ManagerFactory';
    $selector=$config->getSystemValue('comments.managerFactory',null);
    $directory=rtrim(\OC::$configDir,'/');
    $metadataPath=$directory.'/mcp_forms_comments_install.json';
    $metadata=is_file($metadataPath)?json_decode(file_get_contents($metadataPath),true,32,JSON_THROW_ON_ERROR):null;
    if ($action==='candidate' || $action==='probe') {
        \OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
        $stock=(new \OC\Comments\ManagerFactory(\OC::$server))->getManager();
        if ($action==='candidate') {
            require_once $directory.'/mcp_forms_comments_bootstrap.php';
            $manager=(new $owned(\OC::$server))->getManager();
        } else {$manager=\OCP\Server::get(\OCP\Comments\ICommentsManager::class);}
        echo json_encode(['selector'=>$selector,'manager'=>$manager::class,'stock'=>$stock::class],JSON_THROW_ON_ERROR)."\n";exit;
    }
    $state=['selector'=>$selector,'owned'=>$selector===$owned,'installed'=>$metadata!==null,'action'=>$action];
    if ($action==='status' || isset($options['dry-run'])) {echo json_encode($state,JSON_THROW_ON_ERROR)."\n";exit;}
    if (!is_writable($directory) || $config->getSystemValueBool('config_is_read_only',false)) {throw new RuntimeException('System configuration is read-only');}
    if ($action==='rollback') {
        if ($selector!==$owned) {echo json_encode($state+['preserved'=>true])."\n";exit;}
        if (!$metadata || !array_key_exists('prior',$metadata)) {throw new RuntimeException('Original factory setting is unavailable');}
        if ($metadata['prior']===null) {$config->deleteSystemValue('comments.managerFactory');}
        else {$config->setSystemValue('comments.managerFactory',$metadata['prior']);}
        $verified=child([PHP_BINARY,__FILE__,'--root='.$root,'--action=probe']);
        if ($verified['selector']!==$metadata['prior'] || $verified['manager']!=='OC\\Comments\\Manager') {throw new RuntimeException('Factory restoration could not be verified; bootstrap retained');}
        // Retain bootstrap through worker turnover and administrator-controlled removal.
        $metadata['active']=false;atomicFile($metadataPath,json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        echo json_encode($verified+['restored'=>true,'bootstrap_retained'=>true])."\n";exit;
    }
    if (!in_array($selector,[null,$default,$owned],true)) {throw new RuntimeException('Existing custom comments factory preserved');}
    if ($selector===$owned && !$metadata) {throw new RuntimeException('Owned factory metadata is missing');}
    $artifacts=['mcp_forms_comments_bootstrap.php','mcp-forms-comments.config.php'];
    foreach ($artifacts as $name) {
        $path=$directory.'/'.$name;
        if (is_file($path) && (!$metadata || !in_array(hash_file('sha256',$path),[$metadata['hashes'][$name]??'', $metadata['staged_hashes'][$name]??''],true))) {
            throw new RuntimeException('Administrator replacement of extension artifact preserved');
        }
    }
    $metadata??=['prior'=>$selector,'hashes'=>[]];
    if ($selector!==$owned) {$metadata['prior']=$selector;}
    $metadata['active']=false;
    foreach ($artifacts as $name) {$metadata['staged_hashes'][$name]=hash_file('sha256',__DIR__.'/'.$name);}
    atomicFile($metadataPath,json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    foreach ($artifacts as $name) {atomicFile($directory.'/'.$name,file_get_contents(__DIR__.'/'.$name));}
    $candidate=child([PHP_BINARY,__FILE__,'--root='.$root,'--action=candidate']);
    if ($candidate['manager']!=='OCA\\Forms\\Mcp\\Comments\\GuardedCommentsManager' || $candidate['stock']!=='OC\\Comments\\Manager') {throw new RuntimeException('Candidate guarded manager is unavailable');}
    unset($metadata['staged_hashes']);
    foreach ($artifacts as $name) {$metadata['hashes'][$name]=hash_file('sha256',$directory.'/'.$name);}
    $metadata['active']=false;
    atomicFile($metadataPath,json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    $config->setSystemValue('comments.managerFactory',$owned);
    $verified=child([PHP_BINARY,__FILE__,'--root='.$root,'--action=probe']);
    if ($verified['selector']!==$owned || $verified['manager']!==$candidate['manager']) {
        // Revert our selector write when a later fragment prevented activation.
        if ($selector===null) {$config->deleteSystemValue('comments.managerFactory');}
        else {$config->setSystemValue('comments.managerFactory',$selector);}
        throw new RuntimeException('Effective factory activation could not be verified; prior selector restored');
    }
    \OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    // Every explicit activation/validation retires previews from any unguarded maintenance interval.
    \OCP\Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class)->transaction(static fn()=>null);
    $metadata['native_manifest']=hash_file('sha256',\OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath('forms').'/mcp-guard-installed.json');
    $metadata['active']=true;atomicFile($metadataPath,json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    echo json_encode($verified+['installed'=>true,'requires_process_turnover'=>true])."\n";
} catch (Throwable $error) {fwrite(STDERR,$error->getMessage()."\n");exit(1);}
