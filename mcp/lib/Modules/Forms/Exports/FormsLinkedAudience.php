<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
/** Private approval fingerprint. Call only after the native default-provider/backend source admission.
 * Includes inherited shares and group members; raw tokens/passwords never leave this method.
 */
final class FormsLinkedAudience {
    public function __construct(private IRootFolder $root,private IDBConnection $db,private \Closure $time,private ?\Closure $circleAudience=null) {}
    public function snapshot(string $uid,string $path):array {
        $path=PathGuard::normalize($path);$home=$this->root->getUserFolder($uid);
        $parent=dirname(ltrim($path,'/'));$node=$parent==='.'?$home:$home->get($parent);
        if(!$node instanceof \OCP\Files\Folder)throw new \RuntimeException('Linked Forms destination parent is unavailable.');
        $ids=[];$bindings=[];
        for($depth=0;;$depth++) {
            if($depth>=100||$node->getOwner()?->getUID()!==$uid)throw new \RuntimeException('Linked Forms audience ownership is unavailable.');
            $id=(int)$node->getId();if($id<1||isset($ids[$id]))throw new \RuntimeException('Linked Forms audience ancestry is unavailable.');
            $ids[$id]=$id;$bindings[]=['id'=>$id,'path'=>$node->getPath()];
            if($id===(int)$home->getId())break;
            $node=$node->getParent();
        }
        if($home->nodeExists(ltrim($path,'/'))) {
            $file=$home->get(ltrim($path,'/'));
            if(!$file instanceof \OCP\Files\File||$file->getOwner()?->getUID()!==$uid)throw new \RuntimeException('Linked Forms output ownership changed.');
            $ids[(int)$file->getId()]=(int)$file->getId();
        }
        $q=$this->db->getQueryBuilder();
        $result=$q->select('*')->from('share')->where($q->expr()->in('file_source',$q->createNamedParameter(array_values($ids),IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('id','ASC')->setMaxResults(1001)->executeQuery();
        try{$shares=$result->fetchAll();}finally{$result->closeCursor();}
        if(count($shares)>1000)throw new \RuntimeException('Linked Forms audience exceeds its bounded share limit.');
        $groups=[];$recipients=[];$circleEvidence=[];$audienceRows=0;$now=($this->time)();
        foreach($shares as &$share) {
            $type=(int)$share['share_type'];
            if(!in_array($type,[0,1,3,7],true))throw new \RuntimeException('Linked Forms recipient provider is not qualified.');
            $expires=$share['expiration']??null;$active=$expires===null||$expires===''||(new \DateTimeImmutable($expires,new \DateTimeZone('UTC')))->getTimestamp()>$now;
            $share['mcp_effective']=$active;
            if($type===1)$groups[(string)$share['share_with']]=true;
            if($active&&in_array($type,[0,3],true)){$audienceRows++;$recipients[]=['type'=>$type,'recipient'=>$type===3?'public link':(string)$share['share_with'],'permissions'=>(int)$share['permissions'],'expires'=>$expires];}
            if($active&&$type===7){$circleId=(string)$share['share_with'];$evidence=$this->circle($uid,$circleId,(int)$share['id'],(int)$share['file_source']);$circleEvidence[]=$evidence;$audienceRows+=$evidence['recipient_count'];if($evidence['recipient_count']>0){$recipients[]=['type'=>7,'recipient'=>$circleId,'permissions'=>(int)$share['permissions'],'expires'=>$expires];}}
            if($audienceRows>1000)throw new \RuntimeException('Linked Forms audience exceeds its bounded recipient limit.');
        }
        unset($share);$members=[];$bytes=strlen(json_encode([$bindings,$shares],JSON_THROW_ON_ERROR));
        if($bytes>1048576)throw new \RuntimeException('Linked Forms audience exceeds its private byte limit.');
        foreach(array_keys($groups) as $group) {
            $q=$this->db->getQueryBuilder();
            $result=$q->select('uid')->from('group_user')->where($q->expr()->eq('gid',$q->createNamedParameter($group)))->orderBy('uid','ASC')->setMaxResults(1001)->executeQuery();
            try{$users=array_column($result->fetchAll(),'uid');}finally{$result->closeCursor();}
            if(count($users)>1000)throw new \RuntimeException('Linked Forms audience exceeds its bounded membership limit.');
            $audienceRows+=count($users);if($audienceRows>1000)throw new \RuntimeException('Linked Forms audience exceeds its bounded recipient limit.');
            $bytes+=strlen(json_encode([$group,$users],JSON_THROW_ON_ERROR));
            if($bytes>1048576)throw new \RuntimeException('Linked Forms audience exceeds its private byte limit.');
            $members[$group]=$users;
        }
        $encoded=json_encode(FormsOperationJournal::canonical(['uid'=>$uid,'path'=>$path,'bindings'=>$bindings,'shares'=>$shares,'members'=>$members,'circle_audience'=>$circleEvidence]),JSON_THROW_ON_ERROR);
        if(strlen($encoded)>1048576)throw new \RuntimeException('Linked Forms audience exceeds its private byte limit.');
        return ['digest'=>hash('sha256','forms-linked-audience-v1:'.$encoded),'recipients'=>$recipients];
    }

    private function circle(string $uid,string $circleId,int $shareId,int $fileId):array {
        if($shareId<1||$fileId<1||$circleId==='')throw new \RuntimeException('Linked Forms Circle identity is unavailable.');
        $resolver=$this->circleAudience;
        $evidence=$resolver!==null?$resolver($uid,$circleId,$shareId,$fileId):(class_exists(\OCA\Forms\Mcp\Service\CircleRuntime::class)?\OCA\Forms\Mcp\Service\CircleRuntime::linkedAudienceEvidence($uid,$circleId,$shareId,$fileId):throw new \RuntimeException('Native Circle provider is unavailable.'));
        $keys=['circle_id','parent_share_id','file_id','files_share_provider','share_type','circle_incarnation_digest','native_membership_digest','native_recipient_projection_digest','recipient_count'];
        if(!is_array($evidence)||array_keys($evidence)!==$keys||$evidence['circle_id']!==$circleId||$evidence['parent_share_id']!==$shareId||$evidence['file_id']!==$fileId
            ||$evidence['files_share_provider']!=='ocCircleShare'||$evidence['share_type']!==7||!is_int($evidence['recipient_count'])||$evidence['recipient_count']<0||$evidence['recipient_count']>1000
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$evidence['circle_incarnation_digest'])||!preg_match('/^[a-f0-9]{64}$/D',(string)$evidence['native_membership_digest'])
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$evidence['native_recipient_projection_digest']))throw new \RuntimeException('Linked Forms Circle audience evidence is malformed.');
        return $evidence;
    }
}
