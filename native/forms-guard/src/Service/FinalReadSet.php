<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact consumed native SQL inputs. Not a policy engine, lock, or reusable authority. */
final class FinalReadSet {
    private array $queries=[];
    private bool $sealed=false;
    private bool $used=false;
    private int $bytes=0;
    private int $rows=0;
    public const TABLES=['appconfig','preferences','users','groups','group_user','share','filecache','mounts','storages','circles_circle','circles_member','circles_membership','circles_remote','forms_v2_forms','forms_v2_shares','forms_mcp_file_shares','forms_mcp_folders','forms_mcp_group_history','forms_mcp_group_epoch'];
    public function __construct(private readonly \Closure $deadline,private readonly string $prefix='*PREFIX*') {}
    private function open():void {($this->deadline)();if($this->sealed||$this->used)throw new \RuntimeException('Final read snapshot escaped or repeated');}
    public function tablesFor(string $sql):array {
        // SQL comes only from pinned native readers/owned collectors. Reject a new SQL grammar/profile.
        if(!str_starts_with($sql,'SELECT ')||preg_match('/;|--|\/\*|\b(?:FOR|UNION|INTO|LOCK|OUTFILE|PROCEDURE|SLEEP|WITH)\b/i',$sql))throw new \RuntimeException('Final read query is unqualified');
        preg_match_all('/\b(?:FROM|JOIN)\s+[`"]?([a-zA-Z0-9_*]+)[`"]?/i',$sql,$matches);
        if($matches[1]===[])throw new \RuntimeException('Final read query is unqualified');
        $out=[];
        foreach($matches[1]as$table){
            $short=str_starts_with($table,'*PREFIX*')?substr($table,8):(str_starts_with($table,$this->prefix)?substr($table,strlen($this->prefix)):'');
            if(!in_array($short,self::TABLES,true))throw new \RuntimeException('Final read query is unqualified');
            $out[$short]=true;
        }
        return array_keys($out);
    }
    public function validate(string$sql,array$params,array$types):array {
        $tables=$this->tablesFor($sql);
        $scalar=static fn(mixed$v):bool=>is_null($v)||is_string($v)||is_int($v)||is_bool($v);
        foreach($params as$key=>$value){
            if(!is_int($key)&&(!is_string($key)||preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$key)!==1))throw new \RuntimeException('Final read parameter is unqualified');
            if(is_array($value)){if(!array_is_list($value)||count($value)>1000)throw new \RuntimeException('Final read parameter is unqualified');foreach($value as$v)if(!$scalar($v))throw new \RuntimeException('Final read parameter is unqualified');}
            elseif(!$scalar($value))throw new \RuntimeException('Final read parameter is unqualified');
        }
        foreach($types as$key=>$type){
            if(!array_key_exists($key,$params))throw new \RuntimeException('Final read parameter is unqualified');
            if(!is_int($type)&&!is_string($type)&&!($type instanceof \UnitEnum&&in_array($type::class,['Doctrine\\DBAL\\ParameterType','Doctrine\\DBAL\\ArrayParameterType'],true)))throw new \RuntimeException('Final read parameter type is unqualified');
        }
        return$tables;
    }
    public function add(string $sql,array $params,array $types,array $rows):void {
        $this->open();$tables=$this->validate($sql,$params,$types);
        if(!array_is_list($rows)||count($rows)>1000||count($this->queries)>=10000)throw new \RuntimeException('Final read budget exceeded');
        foreach($rows as$row){if(!is_array($row)||$row===[])throw new \RuntimeException('Final read row is malformed');foreach($row as$key=>$value){if(!is_string($key)||preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$key)!==1||(!is_null($value)&&!is_string($value)&&!is_int($value)&&!is_bool($value)))throw new \RuntimeException('Final read row is malformed');}}
        $typeData=array_map(static fn(mixed$type):mixed=>$type instanceof \UnitEnum?[$type::class,$type->name]:$type,$types);
        $bytes=strlen(json_encode([$sql,$params,$typeData,$rows],JSON_THROW_ON_ERROR));
        if(($this->bytes+=$bytes)>1048576||($this->rows+=count($rows))>10000)throw new \RuntimeException('Final read budget exceeded');
        $this->queries[]=[$sql,$params,$types,$rows,$tables];
    }
    public function seal():void {$this->open();if($this->queries===[])throw new \RuntimeException('Final read snapshot is empty');$this->sealed=true;}
    public function tables():array {$out=[];foreach($this->queries as$q)foreach($q[4]as$t)$out[$t]=true;return array_keys($out);}
    /** Native queries remain unchanged; only bind names are made disjoint in the outer UNION. */
    public function statement(string $provider):array {
        if(!$this->sealed||$this->used||!in_array($provider,['pgsql','mysql'],true))throw new \RuntimeException('Final read snapshot is unavailable');
        $branches=[];$allParams=[];$allTypes=[];
        foreach($this->queries as$i=>[$sql,$params,$types,$rows]){
            $names=[];$position=0;$seen=[];
            $sql=preg_replace_callback('/\x27(?:\x27\x27|[^\x27])*\x27|"(?:""|[^"])*"|`[^`]*`|(?<!:):[A-Za-z_][A-Za-z0-9_]*|\?/',function(array$m)use($i,$params,$types,&$names,&$position,&$seen,&$allParams,&$allTypes):string {
                $token=$m[0];if($token!=='?'&&!str_starts_with($token,':'))return $token;
                $key=$token==='?'?$position++:substr($token,1);
                if(!array_key_exists($key,$params))throw new \RuntimeException('Final read parameter is unqualified');
                if(!isset($names[$key])){$name='f'.$i.'p'.count($names);$names[$key]=$name;$allParams[$name]=$params[$key];if(array_key_exists($key,$types))$allTypes[$name]=$types[$key];}
                $seen[$key]=true;return ':'.$names[$key];
            },$sql);
            if(count($seen)!==count($params)||$sql===null)throw new \RuntimeException('Final read parameter is unqualified');
            $keys=$rows===[]?[]:array_keys($rows[0]);
            if($provider==='pgsql')$json=$keys===[]?'\'{"_present":1}\'':'row_to_json(t)::text';
            else{$parts=[];foreach($keys as$key){$parts[]="'".$key."'";$parts[]='t.`'.$key.'`';}$json=$keys===[]?'\'{"_present":1}\'':'JSON_OBJECT('.implode(',',$parts).')';}
            $alias=$provider==='pgsql'?'"row"':'`row`';
            $branches[]='SELECT '.$i.' AS q, CASE WHEN OCTET_LENGTH(j.r)>1048576 THEN NULL ELSE j.r END AS '.$alias.' FROM (SELECT '.$json.' AS r FROM ('.$sql.') t) j';
        }
        $sql=implode(' UNION ALL ',$branches).' LIMIT 10001';
        if(strlen($sql)>1048576||count($allParams)>10000)throw new \RuntimeException('Final read budget exceeded');
        return ['sql'=>$sql,'params'=>$allParams,'types'=>$allTypes];
    }
    /** Compare complete multisets: native no-order queries have no invented tie order; duplicates remain significant. */
    public static function equalRows(array $left,array $right):bool {
        $encode=static function(array$rows):array{$out=[];foreach($rows as$row){ksort($row,SORT_STRING);$out[]=json_encode($row,JSON_THROW_ON_ERROR);}sort($out,SORT_STRING);return$out;};
        return $encode($left)===$encode($right);
    }
    public function verify(iterable $rows):void {
        if(!$this->sealed||$this->used)throw new \RuntimeException('Final read snapshot escaped or repeated');
        $this->used=true;$actual=array_fill(0,count($this->queries),[]);$count=0;$bytes=0;
        foreach($rows as$row){
            ($this->deadline)();
            if(!is_array($row)||!isset($row['q'],$row['row'])||!is_string($row['row'])||!(is_int($row['q'])||(is_string($row['q'])&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$row['q'])===1))||!isset($actual[(int)$row['q']]))throw new \RuntimeException('Final read snapshot is malformed');
            if(++$count>10000||($bytes+=strlen($row['row']))>1048576)throw new \RuntimeException('Final read budget exceeded');
            try{$value=json_decode($row['row'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \RuntimeException('Final read snapshot is malformed');}
            if(!is_array($value)||array_is_list($value))throw new \RuntimeException('Final read snapshot is malformed');
            $actual[(int)$row['q']][]=$value;
        }
        foreach($this->queries as$i=>$query)if(!self::equalRows($query[3],$actual[$i])){
            $columns=[];$keys=[];foreach([...$query[3],...$actual[$i]]as$row)foreach(array_keys($row)as$key)$keys[$key]=true;
            foreach(array_keys($keys)as$key){$values=static function(array$rows)use($key):array{$out=[];foreach($rows as$row)$out[]=json_encode([array_key_exists($key,$row),$row[$key]??null],JSON_THROW_ON_ERROR);sort($out,SORT_STRING);return$out;};if($values($query[3])!==$values($actual[$i]))$columns[]=$key;}
            sort($columns,SORT_STRING);
            throw new FinalReadMismatch(['tables'=>$query[4],'columns'=>$columns,'expected_rows'=>count($query[3]),'actual_rows'=>count($actual[$i])]);
        }
        ($this->deadline)();
    }
}

/** Closed schema/count metadata only; SQL, parameters and row values never enter the exception. */
final class FinalReadMismatch extends \RuntimeException {
    public function __construct(private readonly array $diagnostic) {parent::__construct('Final read dependency changed');}
    public function details():array {return $this->diagnostic;}
}
