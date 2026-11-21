<?php
/* UNAPPLIED R2 proposal. SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class SourceGate {
    private SourceReadFrame $reads;
    private ?array $verifiedInputs=null;
    private ?array $readInputs=null;
    public function __construct() {$this->reads=new SourceReadFrame();}
    /** Only the closed DTO reader may defer source rehashing; provider/effect leases never enter this lifetime. */
    public function verifiedRead(string $uid,\Closure $read):mixed {
        $caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]??[];
        if(($caller['class']??null)!==Lease::class||($caller['function']??null)!=='readData')throw new \RuntimeException('Circle source read issuer is unqualified');
        return $this->reads->within(fn()=>$this->assert($uid),function()use($read):mixed {
            $this->readInputs=$this->verifiedInputs;
            try{return $read();}finally{$this->readInputs=null;}
        });
    }
    public function assert(string $uid): void {
        $core=new \OCA\Forms\Mcp\Service\FreshCore($uid);
        if(!$core->apps()->isEnabledForUser('circles',$core->user())||$core->appConfig()->getValueString('circles','installed_version')!=='35.0.0'){throw new \RuntimeException('Circle sources are not qualified');}
        $root=realpath($core->apps()->getAppPath('circles'));
        if(!is_string($root)){throw new \RuntimeException('Circle source path is unavailable');}
        $map=json_decode(file_get_contents(dirname(__DIR__,2).'/CircleSources.json'),true,32,JSON_THROW_ON_ERROR);
        $additional=json_decode(file_get_contents(__DIR__.'/AdditionalExecutableSources.json'),true,32,JSON_THROW_ON_ERROR);
        if(array_intersect_key($map,$additional)!==[]){throw new \RuntimeException('Circle executable source maps overlap');}
        $map+=$additional;
        $inputs=[$uid,$root,$map];
        if($this->reads->active()){
            if($inputs!==$this->readInputs)throw new \RuntimeException('Circle source read binding changed');
        }else{SourceTree::assertExact($root,$map);}
        foreach(StaticDefaults::EXPECTED as $class=>$properties){
            foreach($properties as $name=>$expected){if((new \ReflectionProperty($class,$name))->getValue()!==$expected){throw new \RuntimeException('Native Circle static query/model defaults changed');}}
        }
        foreach(['CoreQueryBuilder'=>'Db','Circle'=>'Model','Member'=>'Model'] as $short=>$area){
            foreach(StaticDefaults::EXPECTED['OCA\\Circles\\'.$area.'\\'.$short] as $name=>$expected){if((new \ReflectionProperty(__NAMESPACE__.'\\'.$short,$name))->getValue()!==$expected){throw new \RuntimeException('Owned Circle static defaults changed');}}
        }
        $classes=json_decode(file_get_contents(__DIR__.'/NativeClasses.json'),true,32,JSON_THROW_ON_ERROR);
        foreach($classes as $class=>$path){
            if(!isset($map[$path])||realpath((new \ReflectionClass($class))->getFileName())!==realpath($root.'/'.$path)){throw new \RuntimeException('Circle native class resolves outside qualified source');}
        }
        if(!$this->reads->active())$this->verifiedInputs=$inputs;
    }
}

/** Instance-local, synchronous source lifetime. Full verification surrounds every unpublished pure read. */
final class SourceReadFrame {
    private bool $active=false;
    public function active():bool {return $this->active;}
    public function within(\Closure $verify,\Closure $read):mixed {
        if($this->active)throw new \RuntimeException('Circle source read cannot nest');
        $verify();$this->active=true;
        try{return $read();}finally{$this->active=false;$verify();}
    }
}
