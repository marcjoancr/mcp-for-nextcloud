<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Guard;
use OCA\Forms\Mcp\Service\StorageAdmission;
class UnqualifiedStorageWrapper extends \OC\Files\Storage\Wrapper\Wrapper {}
class UnqualifiedHomeStorage extends \OC\Files\Storage\Home {}
/** Test-only interceptor delegates every lock to the real provider, then commits a scope change in another process. */
final class SinkAfterLock implements \OCP\Lock\ILockingProvider {
    public bool $revoked = false;
    public function __construct(private \OCP\Lock\ILockingProvider $inner, private ?string $key, private Closure $revoke) {}
    public function isLocked(string $path, int $type): bool {return $this->inner->isLocked($path, $type);}
    public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
        $this->inner->acquireLock($path, $type, $readablePath);
        $this->after($path, $type);
    }
    public function changeLock(string $path, int $targetType): void {
        $this->inner->changeLock($path, $targetType);
        $this->after($path, $targetType);
    }
    private function after(string $path, int $type): void {
        if (!$this->revoked && $type === self::LOCK_EXCLUSIVE && ($this->key === null || $path === $this->key)) {
            if (!$this->inner->isLocked($path, $type)) {throw new RuntimeException('Test requires a real exclusive lock');}
            ($this->revoke)(); $this->revoked = true;
        }
    }
    public function releaseLock(string $path, int $type): void {$this->inner->releaseLock($path, $type);}
    public function releaseAll(): void {$this->inner->releaseAll();}
}
function commitStorageScope(string $uid, bool $deny): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/storage-scope-writer.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['uid' => $uid, 'deny' => $deny])); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($output) !== 'scope committed') {throw new RuntimeException('Scope writer failed: ' . $error);}
}

try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');$request=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$case=$request['case'];$uid='guard-owner';Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));require __DIR__.'/origin-fixture.php';
 $container=(new \OCA\Forms\AppInfo\Application())->getContainer();$guard=$container->get(Guard::class);$id=$container->get(\OCA\Forms\Controller\ApiController::class)->newForm()->getData()['id'];$origin=formsNativeOrigin($uid,'forms_export_results',['id'=>$id]);
 $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$name='sink-'.bin2hex(random_bytes(6)).'.txt';$file=$root->newFile($name,$case==='grant_before'?'':'original');$storage=$file->getStorage();$path=$file->getInternalPath();$expectedId=$file->getId();$mount=$file->getMountPoint();$originalStorage=$mount->getStorage();
 if($case==='identity'){$stale=clone $file;$file->delete();$root->newFile($name,'replacement');$file=$stale;}
 if($case==='unknown'){$mount->wrapStorage(fn($p,$s)=>new UnqualifiedStorageWrapper(['storage'=>$s]));}
 $view=(new ReflectionProperty($file,'view'))->getValue($file);$property=new ReflectionProperty($view,'lockingProvider');$originalLocks=$property->getValue($view);$allocation=str_contains($case,'allocation');$targetPath=$allocation?$path.'-new':$path;$key='files/'.md5($storage->getId().'::'.trim($targetPath,'/'));$revoked=false;$exclusive=false;$calls=0;
 $revoke=function()use($uid,$case){if(str_starts_with($case,'scope')){commitStorageScope($uid,true);}else{$p=proc_open([PHP_BINARY,__DIR__.'/sink-grant-writer.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['uid'=>$uid,'allow'=>false]));fclose($pipes[0]);stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);}};
 $locks=new SinkAfterLock($originalLocks,$key,$revoke);if(str_starts_with($case,'scope')||str_starts_with($case,'grant')){$property->setValue($view,$locks);}
 $authorize=function()use($uid,$origin,$file,$key,$originalLocks,$case,&$exclusive,&$calls){if($case==='digest'){$stream=$file->getStorage()->fopen($file->getInternalPath(),'rb');if(!is_resource($stream))throw new RuntimeException('Preimage is unavailable');try{$hash=hash_init('sha256');$size=hash_update_stream($hash,$stream,26214401);$sha=hash_final($hash);}finally{fclose($stream);}if($size!==8||!hash_equals(hash('sha256','original'),$sha))throw new RuntimeException('Approved preimage changed');}$calls++;$exclusive=$originalLocks->isLocked($key,\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->resourceBoundary($uid,function()use($file){$access=Server::get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);$access->clearCache();$access->assertVisible($file);});};
 $payload='written';if(str_contains($case,'stream')){$payload=fopen('php://memory','w+b');fwrite($payload,'written');rewind($payload);}
 $write=function()use($case,$file,$payload,$uid,$name,$root){if(in_array($case,['etag','digest'],true)){$p=proc_open([PHP_BINARY,__DIR__.'/sink-native-writer.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['uid'=>$uid,'name'=>$name,'content'=>$case==='digest'?'changed!':'competing','mtime'=>$file->getMtime()+($case==='etag'?2:0)]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||trim($out)!=='native write committed')throw new RuntimeException('Competing native write failed: '.$err);$GLOBALS['sink_etags']=['node'=>$file->getEtag(),'cache'=>$file->getStorage()->getCache()->get($file->getInternalPath())['etag'],'size'=>$file->getStorage()->getCache()->get($file->getInternalPath())['size']];}return $file->putContent($payload);};
 $failed=false;$beforeEffect=false;$error='';if($case==='grant_before'){$revoke();$revoked=true;}try{$guard->fileBoundary($uid,$origin,$allocation?$root:$file,$allocation?$name.'-new':null,$authorize,str_starts_with($case,'noop')?static fn()=>null:($allocation?fn()=>$root->newFile($name.'-new',''):$write));}catch(Throwable $e){$failed=true;$beforeEffect=$e instanceof \OCA\Forms\Mcp\BeforeEffect;$error=$e->getMessage();}
 finally {$property->setValue($view,$originalLocks);if($case==='unknown'){$mount->wrapStorage(fn()=>$originalStorage);}if(is_resource($payload))fclose($payload);if($locks->revoked||$revoked){if(str_starts_with($case,'scope')){commitStorageScope($uid,false);}else{$p=proc_open([PHP_BINARY,__DIR__.'/sink-grant-writer.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['uid'=>$uid,'allow'=>true]));fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}}}
 echo json_encode(['ok'=>true,'failed'=>$failed,'before_effect'=>$beforeEffect,'revoked'=>$locks->revoked,'exclusive'=>$exclusive,'calls'=>$calls,'allocation_exists'=>$storage->file_exists($targetPath),'bytes'=>$storage->file_get_contents($path),'error'=>$error,'etags'=>$GLOBALS['sink_etags']??[]]);
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
