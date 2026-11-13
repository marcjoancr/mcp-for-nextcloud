<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Db\FormsSpoolMapper;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
/** Encrypted generated cache only. Recovery copies and user Files never enter this namespace. */
final class FormsPrivateSpool implements FormsSpool {
    private const MAX_PLAIN=26214400;
    private const MAX_CIPHER=52432896;
    public function __construct(private IAppData $data,private ICrypto $crypto,private FormsSpoolMapper $budget,private ILockingProvider $locks) {}
    public function reserve(string $job,string $uid):void {$this->budget->reserve($job,$uid);}
    public function assertActive(string $job,string $uid):void {$this->budget->assertActive($job,$uid);}
    public function seal(string $job):void {$this->budget->seal($job);}
    private function locked(string $job,callable $work):mixed {
        if(preg_match('/^[a-f0-9]{64}$/D',$job)!==1)throw new \InvalidArgumentException('Invalid Forms artifact identity.');
        $key=hash('sha256','mcp/forms-spool/v2:'.$job);$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try{return $work();}finally{$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    }
    private function folder(string $job,bool $create):ISimpleFolder {
        $name='forms-results-v2-'.$job;
        try{return $this->data->getFolder($name);}catch(NotFoundException $error){if(!$create)throw $error;}
        try{return $this->data->newFolder($name);}catch(\Throwable){return $this->data->getFolder($name);}
    }
    public function put(string $job,string $content):array {
        if(strlen($content)>self::MAX_PLAIN)throw new \RuntimeException('Forms artifact exceeds its byte quota.');
        return $this->locked($job,function()use($job,$content):array {
            $this->budget->assertActive($job);
            $part=['key'=>hash('sha256',$content),'bytes'=>strlen($content)];$encrypted=$this->crypto->encrypt($content);
            if(strlen($encrypted)>self::MAX_CIPHER)throw new \RuntimeException('Forms encrypted artifact exceeds its byte quota.');
            // Charge commits before any allocation. An unacknowledged charge prevents IO and is never refunded speculatively.
            $this->budget->charge($job,$part['key'],strlen($encrypted));$this->budget->assertActive($job);
            try {$folder=$this->folder($job,false);}catch(NotFoundException){$folder=null;}
            $token=null;
            if($folder===null||!$folder->fileExists($part['key'])) {
                $token=$this->budget->beginWrite($job);
                $folder??=$this->folder($job,true);$folder->newFile($part['key'],$encrypted);
            }
            if($this->decode($job,$part)!==$content)throw new \RuntimeException('Forms artifact verification failed.');
            if($token!==null)$this->budget->finishWrite($job,$token);
            $this->budget->assertActive($job);return $part;
        });
    }
    public function get(string $job,array $part):string {
        return $this->locked($job,function()use($job,$part):string {
            $this->budget->assertActive($job);$content=$this->decode($job,$part);$this->budget->assertActive($job);return $content;
        });
    }
    private function decode(string $job,array $part):string {
        if(preg_match('/^[a-f0-9]{64}$/D',$part['key']??'')!==1||!is_int($part['bytes']??null)||$part['bytes']<0||$part['bytes']>self::MAX_PLAIN)
            throw new \RuntimeException('Forms artifact is unavailable.');
        $expected=$this->budget->cipherBytes($job,$part['key']);$file=$this->folder($job,false)->getFile($part['key']);
        if($expected<1||$expected>self::MAX_CIPHER||$file->getSize()!==$expected)throw new \RuntimeException('Forms ciphertext reservation changed.');
        $encrypted=$file->getContent();
        if(strlen($encrypted)!==$expected)throw new \RuntimeException('Forms ciphertext reservation changed.');
        $content=$this->crypto->decrypt($encrypted);
        if(strlen($content)!==$part['bytes']||!hash_equals($part['key'],hash('sha256',$content)))throw new \RuntimeException('Forms artifact verification failed.');
        return $content;
    }
    /** Only the current durable collector may reclaim generated blobs outside its accepted checkpoint. */
    public function retain(string $job,array $keys,\Closure $currentClaim):bool {
        return $this->locked($job,function()use($job,$keys,$currentClaim):bool {
            $started=microtime(true);$this->budget->assertActive($job);$currentClaim();
            foreach($this->budget->unreferenced($job,$keys,100) as $key) {
                if(microtime(true)-$started>20)return false;
                $currentClaim();$token=$this->budget->beginWrite($job,$currentClaim);
                try {
                    $folder=$this->folder($job,false);
                    if($folder->fileExists($key))$folder->getFile($key)->delete();
                    if($folder->fileExists($key))throw new \RuntimeException('Forms obsolete checkpoint deletion was not verified.');
                } catch(NotFoundException) {}
                $this->budget->discarded($job,$key,$token);
            }
            return $this->budget->unreferenced($job,$keys,1)===[];
        });
    }
    /** At most100 known files/10 jobs/20 seconds. Empty namespace folders and expiry tombstones intentionally remain. */
    public function cleanup():void {
        $started=microtime(true);$remaining=100;
        foreach($this->budget->expiring(10) as $job) {
            if($remaining===0||microtime(true)-$started>20)return;
            try {
                $this->locked($job,function()use($job,$started,&$remaining):void {
                    if(!$this->budget->retire($job))return;
                    foreach($this->budget->parts($job,$remaining) as $key) {
                        if($remaining===0||microtime(true)-$started>20)return;
                        try {
                            $folder=$this->folder($job,false);
                            if($folder->fileExists($key))$folder->getFile($key)->delete();
                            if($folder->fileExists($key))throw new \RuntimeException('Forms cache deletion was not verified.');
                        } catch(NotFoundException) {}
                        $this->budget->forget($job,$key);$remaining--;
                    }
                    if($this->budget->parts($job,1)===[])$this->budget->finish($job);
                });
            } catch(\Throwable) {
                // Quota and exact identities remain retained for bounded idempotent cleanup, never an untracked recursive delete.
            }
        }
    }
}
