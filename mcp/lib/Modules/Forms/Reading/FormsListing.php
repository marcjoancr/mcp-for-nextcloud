<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reading;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Only the native-authorized inventory enters this projection; cursors bind its complete current digest. */
final class FormsListing {
    public function __construct(private PlanState $proof,private ActorIdentity $actors,private ConnectionContext $connection) {}
    public function page(string $uid,array $args,array $authorized):array {
        $filters=['kind'=>$args['kind']??'all','query'=>$args['query']??'','state'=>$args['state']??null,'sort'=>$args['sort']??'lastUpdated','direction'=>$args['direction']??'desc'];
        $rows=[];
        foreach($authorized as $row) {
            if(($row['permissions']??[])===[])continue;
            if($filters['kind']==='owned'&&($row['ownerId']??null)!==$uid)continue;
            if($filters['kind']==='shared'&&($row['ownerId']??null)===$uid)continue;
            if($filters['state']!==null&&($row['state']??null)!==$filters['state'])continue;
            if($filters['query']!==''&&!str_contains(mb_strtolower($row['title']??''),mb_strtolower($filters['query'])))continue;
            $rows[$row['id']]=array_intersect_key($row,array_flip(['id','title','ownerId','state','created','lastUpdated','permissions']));
        }
        ksort($rows);
        if(strlen(json_encode($rows,JSON_THROW_ON_ERROR))>1048576)throw new ToolFailure('The authorized Forms inventory exceeds the byte budget. Narrow the query or state filter.');
        $digest=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));$context=[$this->actors->capture($uid),$this->connection->current(),$filters,$digest];$position=0;
        if(isset($args['cursor'])) {
            $parts=explode('.',$args['cursor']);$payload=count($parts)===2?base64_decode(strtr($parts[0],'-_','+/'),true):false;
            $decoded=is_string($payload)?json_decode($payload,true):null;
            if(!is_array($decoded)||count($decoded)!==2||!is_int($decoded[0])||$decoded[0]<0||!is_string($decoded[1])
                ||!hash_equals($digest,$decoded[1])||!hash_equals($this->proof->of('forms_listing_cursor_v1',$uid,[$context,$parts[0]]),$parts[1]))throw new ToolFailure('Forms listing changed or its cursor is unavailable. Start a new filtered listing.');
            $position=$decoded[0];
        }
        $rows=array_values($rows);$sort=$filters['sort'];$direction=$filters['direction']==='asc'?1:-1;
        usort($rows,static function(array $a,array $b)use($sort,$direction):int {
            $av=$a[$sort]??0;$bv=$b[$sort]??0;
            $comparison=$sort==='title'?strcmp(mb_strtolower((string)$av),mb_strtolower((string)$bv)):($av<=>$bv);
            return ($comparison?:($a['id']<=>$b['id']))*$direction;
        });
        $page=array_slice($rows,$position,min(100,max(1,$args['limit']??50)));$next=$position+count($page);$more=$next<count($rows);$cursor=null;
        if($more) {
            $payload=rtrim(strtr(base64_encode(json_encode([$next,$digest],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
            $cursor=$payload.'.'.$this->proof->of('forms_listing_cursor_v1',$uid,[$context,$payload]);
        }
        return ['forms'=>$page,'hasMore'=>$more,'nextCursor'=>$cursor];
    }
}
