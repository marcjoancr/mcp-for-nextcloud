<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

use OCP\IDBConnection;

/** Native Circles share mutations, constrained to one managed row and one active lease. */
final class WriteQueryBuilder extends \OCA\Circles\Db\CoreQueryBuilder {
    private bool $consumed=false;
    public function __construct(
        private readonly Lease $lease,
        private readonly string $action,
        private readonly int $targetId,
        private readonly string $circleId,
        private readonly string $ownerUid,
        private readonly int $fileId,
        private readonly \OCP\Share\IShare $writeShare,
        private readonly bool $create,
        private readonly \Closure $assertFresh,
        private readonly \Closure $beforeWrite,
        private readonly ?array $binding=null,
        private readonly ?\Closure $childProof=null,
        private readonly ?ShareWriteBoundary $transferCapability=null,
    ) {
        if(!in_array($action,['insert','insert-child','update','update-child','delete'],true)||$targetId<0||$circleId===''||$fileId<1
            ||($ownerUid!==$lease->actorUid()&&$transferCapability===null)){throw new \RuntimeException('Native Circle write intent is malformed');}
        if($action==='insert-child'){
            if($targetId<1||$binding===null||$childProof===null||array_keys($binding)!==['operation','source_parent_id','source_child_id','source_file_id','target_parent_id','parent_type','parent_share_with','source_share_with','recipient_uid','target_file_id']
                ||$binding['operation']!=='transfer-recipient'||$binding['source_parent_id']<1||$binding['source_child_id']<1||$binding['target_parent_id']!==$targetId
                ||$binding['source_file_id']<1||$binding['parent_type']!==7||$binding['parent_share_with']===''||$binding['source_share_with']!==$circleId||$binding['recipient_uid']===''||$binding['target_file_id']!==$fileId)throw new \RuntimeException('Native Circle child-create capability is malformed');
        }elseif($action==='update-child'){
            if(!is_array($binding)||array_keys($binding)!==['child_id']||!is_int($binding['child_id'])||$binding['child_id']<1||$childProof!==null||$targetId<1)throw new \RuntimeException('Native Circle child-update identity is malformed');
        }elseif($binding!==null||$childProof!==null){throw new \RuntimeException('Native Circle child-create proof was supplied to another write');}
        parent::__construct();
        if(\OCP\Server::get(IDBConnection::class)!==$lease->db()){throw new \RuntimeException('Native Circle write database is outside its lease');}
    }

    public function executeQuery(?IDBConnection $connection=null): \OCP\DB\IResult {throw new \RuntimeException('Circle write request cannot read');}
    public function executeStatement(?IDBConnection $connection=null): int {
        if($this->consumed){throw new \RuntimeException('Native Circle write intent was already consumed');}
        $this->consumed=true;$this->lease->assertNoMetadataWrite();$this->lease->checkpoint();($this->assertFresh)();
        if($connection!==null&&$connection!==$this->lease->db()){throw new \RuntimeException('Native Circle write database changed');}
        if($this->action!=='insert'){
            $this->andWhere($this->expr()->eq('share_type',$this->createNamedParameter(7)));
            $this->andWhere($this->expr()->eq('share_with',$this->createNamedParameter($this->circleId)));
            $this->andWhere($this->expr()->eq('file_source',$this->createNamedParameter($this->fileId)));
            $this->andWhere($this->expr()->eq('uid_owner',$this->createNamedParameter($this->ownerUid)));
        }
        if($this->action==='update-child'){$this->andWhere($this->expr()->eq('id',$this->createNamedParameter($this->binding['child_id'])));$this->andWhere($this->expr()->eq('parent',$this->createNamedParameter($this->targetId)));}
        $sql=$this->getSQL();$type=$this->getType();$params=$this->getParameters();
        $table='(?:"|`)?[^\s"`]*share(?:"|`)?';
        $allowed=match($this->action){
            'insert','insert-child'=>$type===\Doctrine\DBAL\Query\QueryBuilder::INSERT&&preg_match('/^\s*INSERT\s+INTO\s+'.$table.'\s*\(/i',$sql)===1,
            'update','update-child'=>$type===\Doctrine\DBAL\Query\QueryBuilder::UPDATE&&preg_match('/^\s*UPDATE\s+'.$table.'\s+SET\s/i',$sql)===1,
            'delete'=>$type===\Doctrine\DBAL\Query\QueryBuilder::DELETE&&preg_match('/^\s*DELETE\s+FROM\s+'.$table.'\s+WHERE\s/i',$sql)===1,
        };
        $insert=in_array($this->action,['insert','insert-child'],true)?$this->insertValues($sql,$params):[];
        $rowValues=in_array($this->action,['insert','insert-child'],true)
            ? (($insert['share_type']??null)===7&&($insert['share_with']??null)===$this->circleId&&($insert['uid_owner']??null)===$this->ownerUid&&($insert['file_source']??null)===$this->fileId&&($this->action!=='insert-child'||($insert['parent']??null)===$this->targetId))
            : ($this->hasBinding($sql,$params,'share_type',7)&&$this->hasBinding($sql,$params,'share_with',$this->circleId)&&$this->hasBinding($sql,$params,'file_source',$this->fileId)&&$this->hasBinding($sql,$params,'uid_owner',$this->ownerUid));
        if(!$allowed||!$rowValues){
            throw new \RuntimeException('Native Circle write SQL is outside the pinned share row');
        }
        $identity=match($this->action){'insert'=>$this->targetId===0,'insert-child'=>$this->targetId>0,'update'=>$this->hasBinding($sql,$params,'id',$this->targetId),'update-child'=>$this->hasBinding($sql,$params,'id',$this->binding['child_id'])&&$this->hasBinding($sql,$params,'parent',$this->targetId),'delete'=>$this->hasBinding($sql,$params,'id',$this->targetId)&&$this->hasBinding($sql,$params,'parent',$this->targetId)};
        if(!$identity){throw new \RuntimeException('Native Circle write SQL lost its exact row identity');}
        if($this->action==='delete'&&!str_contains($sql,' OR ')){
            throw new \RuntimeException('Native Circle delete must bind the exact parent and child rows');
        }
        ($this->assertFresh)();($this->beforeWrite)($this->writeShare,$this->create,$this->binding);
        if($this->childProof!==null){($this->childProof)();}
        if($this->transferCapability!==null){$this->transferCapability->consume($this->lease->actorUid(),$this->action,$this->writeShare,$this->create,$this->binding);}
        $this->lease->checkpoint();($this->assertFresh)();
        $this->lease->assertNoMetadataWrite();
        return parent::executeStatement($this->lease->db());
    }

    private function hasBinding(string $sql,array $parameters,string $column,mixed $expected):bool {
        if(!preg_match_all('/\b'.preg_quote($column,'/').'\s*=\s*(:[A-Za-z0-9_]+|\?)/i',$sql,$matches)){return false;}
        $found=false;foreach($matches[1] as $placeholder){$key=ltrim($placeholder,':');if(array_key_exists($key,$parameters)){$value=$parameters[$key];}elseif(array_key_exists($placeholder,$parameters)){$value=$parameters[$placeholder];}else{return false;}if($value!==$expected)return false;$found=true;}
        return $found;
    }
    private function insertValues(string $sql,array $parameters):array {
        if(!preg_match('/^\s*INSERT\s+INTO\s+[^\s(]+\s*\((.*?)\)\s*VALUES\s*\((.*)\)\s*$/is',$sql,$match)){return[];}
        $columns=array_map(static fn(string $v):string=>strtolower(trim($v," \t\n\r\0\x0B`\"")),explode(',',$match[1]));
        $values=[];$token='';$depth=0;foreach(str_split($match[2]) as $char){if($char===','&&$depth===0){$values[]=trim($token);$token='';continue;}if($char==='(')$depth++;if($char===')')$depth--;$token.=$char;}if(trim($token)!=='')$values[]=trim($token);
        if(count($columns)!==count($values))return[];$mapped=[];
        foreach($columns as $i=>$column){if(preg_match('/^(:[A-Za-z0-9_]+|\?)$/',$values[$i],$parameter)){$key=ltrim($parameter[1],':');$mapped[$column]=$parameters[$key]??$parameters[$parameter[1]]??null;}}
        return$mapped;
    }
}
