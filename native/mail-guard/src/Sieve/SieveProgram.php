<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sieve;
use OCA\Mail\Service\MailFilter\FilterBuilder;
use OCA\Mail\Service\OutOfOffice\{OutOfOfficeParser,OutOfOfficeState};
use OCA\Mail\IMAP\ImapFlag;
/** JSON markers are hints only. Each executable block must equal the native regeneration. */
final class SieveProgram {
    private const MARKERS=['filters'=>"### Nextcloud Mail: Filters ### DON'T EDIT ###",'absence'=>"### Nextcloud Mail: Vacation Responder ### DON'T EDIT ###"];
    public function __construct(private FilterBuilder $filters,private OutOfOfficeParser $absence,private ImapFlag $flags){}
    private function fields(#[\SensitiveParameter] array $v,#[\SensitiveParameter] array $required,#[\SensitiveParameter] array $optional=[]):void {if(array_diff($required,array_keys($v))!==[]||array_diff(array_keys($v),array_merge($required,$optional))!==[])throw new SieveFailure();}
    private function text(#[\SensitiveParameter] mixed $v,int $max,bool $lines=false,bool $subject=false):string {
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>$max||preg_match($lines?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$v)||str_contains($v,'### Nextcloud Mail:')||str_contains($subject?str_replace('${subject}','',$v):$v,'${'))throw new SieveFailure();return $v;
    }
    public function address(#[\SensitiveParameter] mixed $v):string {$v=$this->text($v,254);if(preg_match('/^[a-zA-Z0-9.!#$%&\x27*+\/= ?^_`{|}~-]+@[a-zA-Z0-9.-]+$/D',str_replace(' ','',$v))!==1||str_contains($v,' ')||!filter_var($v,FILTER_VALIDATE_EMAIL))throw new SieveFailure();return $v;}
    public function validateFilters(#[\SensitiveParameter] array $filters):array {
        if(!array_is_list($filters)||count($filters)>50)throw new SieveFailure();
        foreach($filters as$f){if(!is_array($f))throw new SieveFailure();$this->fields($f,['name','enable','priority','operator','tests','actions']);$this->text($f['name'],255);
            if(!is_bool($f['enable'])||!is_int($f['priority'])||$f['priority']<0||$f['priority']>100000||!in_array($f['operator'],['allof','anyof'],true))throw new SieveFailure();
            foreach(['tests','actions']as$kind)if(!is_array($f[$kind])||!array_is_list($f[$kind])||count($f[$kind])<1||count($f[$kind])>10)throw new SieveFailure();
            foreach($f['tests']as$t){if(!is_array($t))throw new SieveFailure();$this->fields($t,['field','operator','values']);if(!in_array($t['field'],['subject','to','from'],true)||!in_array($t['operator'],['is','contains','matches'],true)||!is_array($t['values'])||!array_is_list($t['values'])||count($t['values'])<1||count($t['values'])>10)throw new SieveFailure();foreach($t['values']as$v)$this->text($v,256);}
            foreach($f['actions']as$a){if(!is_array($a))throw new SieveFailure();$type=$a['type']??'';$field=match($type){'fileinto'=>'mailbox','addflag'=>'flag','redirect'=>'recipient','keep','stop'=>null,default=>throw new SieveFailure()};$this->fields($a,$field===null?['type']:['type',$field]);
                if($type==='fileinto'){$this->text($a['mailbox'],255);if($a['mailbox']==='')throw new SieveFailure();}
                if($type==='redirect')$this->address($a['recipient']);
                if($type==='addflag'){$flag=$this->text($a['flag'],63);if(str_starts_with($flag,'\\')){if(!in_array($flag,['\\Seen','\\Flagged'],true))throw new SieveFailure();}else{if($flag===''||strlen(mb_convert_encoding(str_replace(' ','_',$flag),'UTF7-IMAP','UTF-8'))>63||!\OCA\Mail\IMAP\TagKeywords::atom($this->flags->create($flag))||$this->flags->create($flag)==='placeholder_for_invalid_label')throw new SieveFailure();}}
            }
        }if(strlen(json_encode($filters,JSON_THROW_ON_ERROR))>131072)throw new SieveFailure();return $filters;
    }
    public function validateAbsence(#[\SensitiveParameter] array $a):array {
        $this->fields($a,['version','enabled','subject','message'],['start','end']);if($a['version']!==1||!is_bool($a['enabled']))throw new SieveFailure();$this->text($a['subject'],255,false,true);$this->text($a['message'],20000,true,true);
        foreach(['start','end']as$k)if(isset($a[$k])){if(!is_string($a[$k])||preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:\d\d)$/D',$a[$k])!==1)throw new SieveFailure();$d=new \DateTimeImmutable($a[$k]);if($d->format('Y-m-d\TH:i:s')!==substr($a[$k],0,19))throw new SieveFailure();}
        if($a['enabled']&&(!isset($a['start'],$a['end'])||new \DateTimeImmutable($a['end'])<=new \DateTimeImmutable($a['start'])))throw new SieveFailure();
        if(!$a['enabled']){unset($a['start'],$a['end']);}return $a;
    }
    private function parts(#[\SensitiveParameter] string $script):array {
        if(strlen($script)>SieveBudget::SCRIPT||str_contains($script,"\0"))throw new SieveFailure();$parts=['filters'=>[],'absence'=>[],'untouched'=>''];$offset=0;
        $pattern='/^(### Nextcloud Mail: (?:Filters|Vacation Responder) ### DON\x27T EDIT ###)\r?\n/m';
        while(preg_match($pattern,$script,$m,PREG_OFFSET_CAPTURE,$offset)===1){$start=$m[0][1];$marker=$m[1][0];$kind=array_search($marker,self::MARKERS,true);$after=$start+strlen($m[0][0]);$endPattern='/^'.preg_quote($marker,'/').'(?=\r?\n|$)/m';
            if(preg_match($endPattern,$script,$end,PREG_OFFSET_CAPTURE,$after)!==1)throw new SieveFailure();$stop=$end[0][1]+strlen($marker);$block=substr($script,$start,$stop-$start);if(count($parts[$kind])>=2||substr_count($block,'### Nextcloud Mail:')!==2)throw new SieveFailure();$parts[$kind][]=str_replace("\r\n","\n",$block);$parts['untouched'].=substr($script,$offset,$start-$offset);$offset=$stop;
        }$parts['untouched'].=substr($script,$offset);return $parts;
    }
    private function comments(#[\SensitiveParameter] string $s):bool {if(str_contains($s,'### Nextcloud Mail:')||str_contains($s,'# FILTER:')||str_contains($s,'# DATA:'))return false;return preg_match('~\A(?:\s+|\#[^\r\n]*(?:\r\n|\n|$)|/\*[^*]*(?:\*(?!/)[^*]*)*\*/)*\z~D',$s)===1;}
    private function blocks(#[\SensitiveParameter] array $filters,#[\SensitiveParameter] ?array $absence,#[\SensitiveParameter] array $recipients):array {
        foreach($recipients as$a)$this->address($a);if(count($recipients)>100)throw new SieveFailure();
        $f=$this->parts($this->filters->buildSieveScript($filters,''))['filters'];$a=$absence===null?[]:$this->parts($this->absence->buildSieveScript(OutOfOfficeState::fromJson($absence),'',$recipients))['absence'];return ['filters'=>$f,'absence'=>$a];
    }
    public function parse(#[\SensitiveParameter] string $script,#[\SensitiveParameter] array $recipients):array {
        try{$p=$this->parts($script);$filters=[];$absence=null;
            foreach(['filters'=>'# FILTER: ','absence'=>'# DATA: ']as$kind=>$marker){$found=0;foreach($p[$kind]as$block){foreach(explode("\n",$block)as$line)if(str_starts_with($line,$marker)){if(++$found!==1)throw new SieveFailure();$data=json_decode(substr($line,strlen($marker)),true,16,JSON_THROW_ON_ERROR);if(!is_array($data))throw new SieveFailure();if($kind==='filters')$filters=$this->validateFilters($data);else $absence=$this->validateAbsence($data);}}if($p[$kind]!==[]&&$found!==1)throw new SieveFailure();}
            $b=$this->blocks($filters,$absence,$recipients);if($p['filters']!==$b['filters']||$p['absence']!==$b['absence']||!$this->comments($p['untouched']))throw new SieveFailure();
            return ['filters'=>$filters,'absence'=>$absence,'opaque'=>false,'untouched'=>$p['untouched']];
        }catch(\Throwable){return ['filters'=>null,'absence'=>null,'opaque'=>true,'untouched'=>''];}
    }
    public function build(#[\SensitiveParameter] array $old,#[\SensitiveParameter] ?array $filters,#[\SensitiveParameter] ?array $absence,#[\SensitiveParameter] array $recipients,#[\SensitiveParameter] array $extensions,bool $transmission):array {
        if($old['opaque'])throw new SieveFailure();$filters=$this->validateFilters($filters??$old['filters']);$absence=$absence===null?$old['absence']:$this->validateAbsence($absence);$b=$this->blocks($filters,$absence,$recipients);$headers=[];$body=[];$emits=$absence['enabled']??false;$required=[];
        foreach($filters as$f)if($f['enable'])foreach($f['actions']as$a){if($a['type']==='redirect')$emits=true;}
        if($emits&&!$transmission)throw new SieveFailure();
        foreach($b as$group)foreach($group as$block){if(str_contains($block,"\nrequire ")){$headers[]=$block;preg_match_all('/"([a-z0-9]+)"/',$block,$m);foreach($m[1]as$e)$required[$e]=true;}else $body[]=$block;}
        if(array_diff(array_keys($required),$extensions)!==[])throw new SieveFailure();
        $script=implode("\r\n",array_map(static fn($v)=>str_replace("\n","\r\n",$v),$headers))."\r\n".$old['untouched']."\r\n".implode("\r\n",array_map(static fn($v)=>str_replace("\n","\r\n",$v),$body))."\r\n";
        if(strlen($script)>SieveBudget::SCRIPT||$this->parse($script,$recipients)['opaque'])throw new SieveFailure();return ['script'=>$script,'filters'=>$filters,'absence'=>$absence,'emits'=>$emits,'extensions'=>array_keys($required)];
    }
}
