<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Mutable gap witness outside immutable executable/system configuration. */
final class CommentsActivationState {
    private static function root():string {
        $c=\OCP\Server::get(\OCP\IConfig::class);
        $data=$c->getSystemValue('datadirectory','');$instance=$c->getSystemValue('instanceid','');
        if(!is_string($data)||!str_starts_with($data,'/')||str_contains($data,"\0")||is_link($data)||!is_dir($data)||!is_string($instance)||preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$instance)!==1)throw new \RuntimeException('Forms comments activation storage is unavailable');
        $parent=rtrim($data,'/').'/appdata_'.$instance;
        if(is_link($parent))throw new \RuntimeException('Forms comments activation storage is unavailable');
        return $parent.'/mcp_forms_comments_state';
    }
    private static function identity(array $metadata):string {
        if(!is_string($metadata['activation_id']??null)||preg_match('/^[a-f0-9]{64}$/D',$metadata['activation_id'])!==1||!is_string($metadata['native_manifest']??null)||preg_match('/^[a-f0-9]{64}$/D',$metadata['native_manifest'])!==1||!is_array($metadata['hashes']??null))throw new \RuntimeException('Forms comments activation identity is unavailable');
        $hashes=$metadata['hashes'];ksort($hashes);
        return hash('sha256',json_encode([$metadata['activation_id'],$metadata['native_manifest'],$hashes],JSON_THROW_ON_ERROR));
    }
    private static function directory(string $root):void {
        clearstatcache(true,$root);
        if(is_link($root)||!is_dir($root)||fileowner($root)!==posix_geteuid()||!is_writable($root))throw new \RuntimeException('Forms comments activation storage is unavailable');
    }
    private static function read(string $root):array {
        $path=$root.'/state.json';clearstatcache(true,$path);
        if(is_link($path)||!is_file($path)||filesize($path)>4096||fileowner($path)!==posix_geteuid())throw new \RuntimeException('Forms comments activation state is unavailable');
        $state=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($state)||array_keys($state)!==['format','identity','active']||$state['format']!==1||!is_string($state['identity'])||preg_match('/^[a-f0-9]{64}$/D',$state['identity'])!==1||!is_bool($state['active']))throw new \RuntimeException('Forms comments activation state is unavailable');
        return $state;
    }
    private static function syncDirectory(string $root):void {
        $f=@fopen($root,'r');if($f===false)throw new \RuntimeException('Forms comments activation acknowledgement is unavailable');
        try{if(!fsync($f))throw new \RuntimeException('Forms comments activation acknowledgement is unavailable');}finally{fclose($f);}
    }
    private static function write(string $root,array $state):void {
        $path=$root.'/state.json';if(is_link($path))throw new \RuntimeException('Forms comments activation state is unavailable');
        $temporary=$root.'/.state-'.bin2hex(random_bytes(12));$f=@fopen($temporary,'xb');if($f===false)throw new \RuntimeException('Forms comments activation state is unavailable');
        try{$bytes=json_encode($state,JSON_THROW_ON_ERROR)."\n";if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new \RuntimeException('Forms comments activation acknowledgement is unavailable');if(!chmod($temporary,0600))throw new \RuntimeException('Forms comments activation state is unavailable');}finally{fclose($f);}
        if(!rename($temporary,$path))throw new \RuntimeException('Forms comments activation acknowledgement is unavailable');self::syncDirectory($root);
    }
    private static function locked(string $root,\Closure $work):void {
        self::directory($root);$path=$root.'/activation.lock';clearstatcache(true,$path);
        if(is_link($path)||(file_exists($path)&&(!is_file($path)||fileowner($path)!==posix_geteuid())))throw new \RuntimeException('Forms comments activation lock is unavailable');
        $f=@fopen($path,'c+b');if($f===false)throw new \RuntimeException('Forms comments activation lock is unavailable');
        try{if(!chmod($path,0600)||!flock($f,LOCK_EX))throw new \RuntimeException('Forms comments activation lock is unavailable');$work();}finally{flock($f,LOCK_UN);fclose($f);}
    }
    public static function active(array $metadata):bool {
        try{$root=self::root();self::directory($root);$state=self::read($root);return $state['active']&&hash_equals(self::identity($metadata),$state['identity']);}catch(\Throwable){return false;}
    }
    /** Only the explicit maintenance installer publishes a fresh configuration token. */
    public static function activate(array &$metadata):void {
        $root=self::root();$parent=dirname($root);
        if(!is_dir($parent)&&!@mkdir($parent,0700,true))throw new \RuntimeException('Forms comments activation storage is unavailable');
        if(!is_dir($root)&&!@mkdir($root,0700))throw new \RuntimeException('Forms comments activation storage is unavailable');
        $metadata['activation_id']=bin2hex(random_bytes(32));$identity=self::identity($metadata);
        self::locked($root,static function()use($root,$identity):void{self::write($root,['format'=>1,'identity'=>$identity,'active'=>true]);});
    }
    /** A fallback must durably retire approval before returning any stock manager. */
    public static function retire(array $metadata):void {
        $root=self::root();$path=$root.'/state.json';clearstatcache(true,$path);
        if(!isset($metadata['activation_id'])&&!file_exists($path)&&!is_link($path))return;
        self::locked($root,static function()use($root):void{$state=self::read($root);$state['active']=false;self::write($root,$state);});
    }
}
