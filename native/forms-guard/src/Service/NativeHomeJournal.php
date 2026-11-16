<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Durable, private evidence on an inherited regular FD; never an instruction to replay an effect. */
final class NativeHomeJournal {
    private const LIMIT=65536;
    private const RECORDS=128;
    private mixed $stream;
    private string $chain='';
    private int $sequence=0;
    private int $bytes=0;
    private int $effects=0;
    private array $pending=[];
    private bool $failed=false;
    private bool $sealed=false;
    private array $identity;
    private function __construct() {}
    private static function binding(array $envelope):string {
        $phase=$envelope['home_phase']??null;$target=$envelope['target_uid']??null;
        if(($envelope['protocol']??null)!==1||!is_array($phase)||!is_string($envelope['job_id']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$envelope['job_id'])||!is_string($target)
            ||!preg_match('/^[A-Za-z0-9_.@-]{1,255}$/D',$target)||in_array($target,['.','..'],true)
            ||($envelope['files_path']??null)!=='/'.$target.'/files'||($phase['target_uid']??null)!==$target
            ||!is_string($phase['nonce']??null)||!preg_match('/^[a-f0-9]{64}$/D',$phase['nonce']))
            throw new \RuntimeException('Native Home journal binding is unavailable');
        ksort($phase,SORT_STRING);
        return hash('sha256',json_encode([$envelope['job_id'],$target,$envelope['files_path'],$phase],JSON_THROW_ON_ERROR));
    }
    private static function stat(mixed $stream):array {
        if(!is_resource($stream)||get_resource_type($stream)!=='stream')throw new \RuntimeException('Native Home journal FD is unavailable');
        $stat=fstat($stream);
        if(!is_array($stat)||($stat['mode']&0170000)!==0100000||($stat['mode']&0777)!==0600||$stat['nlink']!==1
            ||(function_exists('posix_geteuid')&&$stat['uid']!==posix_geteuid()))throw new \RuntimeException('Native Home journal FD is not private');
        return $stat;
    }
    public static function open(mixed $stream,array $envelope):self {
        $stat=self::stat($stream);if($stat['size']!==0)throw new \RuntimeException('Native Home journal already exists');
        $journal=new self();$journal->stream=$stream;$journal->identity=[$stat['dev'],$stat['ino']];
        $journal->append(['type'=>'header','binding'=>self::binding($envelope)]);return $journal;
    }
    private function append(array $record):void {
        $stat=self::stat($this->stream);
        if($this->sealed||[$stat['dev'],$stat['ino']]!==$this->identity||$stat['size']!==$this->bytes||$this->sequence>=self::RECORDS)
            throw new \RuntimeException('Native Home journal state changed');
        $record=['seq'=>$this->sequence]+$record;
        $chain=hash('sha256',$this->chain.json_encode($record,JSON_THROW_ON_ERROR));
        $line=json_encode($record+['chain'=>$chain],JSON_THROW_ON_ERROR)."\n";
        if(strlen($line)>2048||$this->bytes+strlen($line)>self::LIMIT-512)throw new \RuntimeException('Native Home journal budget exceeded');
        try {
            if(fseek($this->stream,0,SEEK_END)!==0)throw new \RuntimeException();
            $offset=0;while($offset<strlen($line)){$written=fwrite($this->stream,substr($line,$offset));if($written===false||$written===0)throw new \RuntimeException();$offset+=$written;}
            if(!fflush($this->stream)||!fsync($this->stream))throw new \RuntimeException();
        } catch(\Throwable $error) {$this->failed=true;throw new \RuntimeException('Native Home journal acknowledgement is uncertain',0,$error);}
        $this->chain=$chain;$this->bytes+=strlen($line);$this->sequence++;
    }
    private static function path(string $path):void {
        if(strlen($path)>1024||str_starts_with($path,'/')||str_contains($path,"\0")||str_contains($path,'\\')||str_contains($path,'//')
            ||in_array('.',explode('/',$path),true)||in_array('..',explode('/',$path),true)
            ||($path!==''&&$path!=='files'&&!str_starts_with($path,'files/')))throw new \RuntimeException('Native Home journal path escaped');
    }
    private static function digest(?string $digest):void {if($digest!==null&&!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new \RuntimeException('Native Home journal digest is unavailable');}
    public function intent(string $operation,string $relativePath,?string $digest=null):int {
        if($this->failed||$this->sealed)throw new \RuntimeException('Native Home journal is closed');
        if(!in_array($operation,['mkdir','touch','write','move','unlink','rmdir','scan','cache'],true))throw new \RuntimeException('Native Home operation is unavailable');
        self::path($relativePath);self::digest($digest);$token=$this->sequence;
        // Reserve acknowledgement and terminal entries before admitting the effect.
        if($this->sequence>self::RECORDS-3||$this->bytes>self::LIMIT-512-4096)throw new \RuntimeException('Native Home journal budget exceeded');
        $this->append(['type'=>'intent','token'=>$token,'operation'=>$operation,'path'=>$relativePath,'digest'=>$digest]);$this->pending[$token]=true;return $token;
    }
    public function complete(int $token,?string $digest=null):void {
        if($this->failed||!isset($this->pending[$token]))throw new \RuntimeException('Native Home acknowledgement is unavailable');
        self::digest($digest);$this->append(['type'=>'complete','token'=>$token,'digest'=>$digest]);unset($this->pending[$token]);$this->effects++;
    }
    public function fail(int $token):void {
        if(!isset($this->pending[$token]))throw new \RuntimeException('Native Home failure token is unavailable');
        $this->failed=true;$this->append(['type'=>'failed','token'=>$token]);
    }
    public function assertComplete():array {
        if($this->failed||$this->pending!==[])throw new \RuntimeException('Native Home effects need recovery');
        return ['effects'=>$this->effects,'chain'=>$this->chain,'bytes'=>$this->bytes];
    }
    public function seal(string $state):array {
        if(!in_array($state,['committed','rolled_back','uncertain'],true))throw new \RuntimeException('Native Home terminal state is unavailable');
        if($state==='committed')$this->assertComplete();
        $this->append(['type'=>'seal','state'=>$state]);$this->sealed=true;
        return ['state'=>$state,'effects'=>$this->effects,'chain'=>$this->chain,'bytes'=>$this->bytes];
    }
    /** Call only after the child has exited; unresolved intents always remain uncertain. */
    public static function read(mixed $stream,array $envelope):array {
        $stat=self::stat($stream);if($stat['size']<1||$stat['size']>self::LIMIT||fseek($stream,0)!==0)throw new \RuntimeException('Native Home receipt is unavailable');
        $raw=stream_get_contents($stream,self::LIMIT+1);if(!is_string($raw)||strlen($raw)!==$stat['size']||!str_ends_with($raw,"\n"))throw new \RuntimeException('Native Home receipt is incomplete');
        $lines=explode("\n",substr($raw,0,-1));if(count($lines)>self::RECORDS)throw new \RuntimeException('Native Home receipt budget exceeded');
        $chain='';$pending=[];$completed=[];$failed=false;$effects=0;$state='uncertain';$sealed=false;
        foreach($lines as $sequence=>$line){
            if(strlen($line)>2048||$sealed)throw new \RuntimeException('Native Home receipt grammar differs');
            try{$row=json_decode($line,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Native Home receipt grammar differs');}
            if(!is_array($row)||($row['seq']??null)!==$sequence||!is_string($row['chain']??null))throw new \RuntimeException('Native Home receipt sequence differs');
            $expected=$row['chain'];unset($row['chain']);$chain=hash('sha256',$chain.json_encode($row,JSON_THROW_ON_ERROR));
            if(!hash_equals($chain,$expected))throw new \RuntimeException('Native Home receipt chain differs');
            $type=$row['type']??null;
            if($sequence===0){if($type!=='header'||($row['binding']??null)!==self::binding($envelope)||count($row)!==3)throw new \RuntimeException('Native Home receipt binding differs');continue;}
            if($type==='intent'){
                if($failed||count($row)!==6||($row['token']??null)!==$sequence||!is_string($row['path']??null)||!in_array($row['operation']??null,['mkdir','touch','write','move','unlink','rmdir','scan','cache'],true))throw new \RuntimeException('Native Home receipt intent differs');
                self::path($row['path']);self::digest($row['digest']??null);$pending[$sequence]=$row;
            }elseif($type==='complete'||$type==='failed'){
                $token=$row['token']??null;if(!is_int($token)||!isset($pending[$token])||($type==='complete'&&($failed||count($row)!==4))||($type==='failed'&&count($row)!==3))throw new \RuntimeException('Native Home receipt acknowledgement differs');
                if($type==='complete'){self::digest($row['digest']??null);$completed[]=$pending[$token]+['after_digest'=>$row['digest']??null];unset($pending[$token]);$effects++;}else{$failed=true;}
            }elseif($type==='seal'){
                $state=$row['state']??null;if(count($row)!==3||!in_array($state,['committed','rolled_back','uncertain'],true)||($state==='committed'&&($failed||$pending!==[])))throw new \RuntimeException('Native Home receipt terminal state differs');$sealed=true;
            }else throw new \RuntimeException('Native Home receipt record differs');
        }
        return ['state'=>$state,'effects'=>$effects,'pending'=>array_values($pending),'completed'=>$completed,'failed'=>$failed,'sealed'=>$sealed,'chain'=>$chain,'bytes'=>strlen($raw)];
    }
}
