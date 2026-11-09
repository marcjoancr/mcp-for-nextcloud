<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tags;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Native user-owned tag discovery only; Message-ID-based native assignment remains unsupported. */
class MailTags {
    public function __construct(private MailServices $native){}
    public function read(array $args,string $uid):array {
        $tags=$this->native->tagMapper($uid)->getAllTagsForUser($uid);if(count($tags)>1000)throw new ToolFailure('Too many tags for bounded discovery.');$tags=array_values(array_filter($tags,static fn(object $tag):bool=>$tag->getUserId()===$uid));usort($tags,static fn(object $a,object $b):int=>(int)$a->getId()<=>(int)$b->getId());
        $limit=min(100,max(1,(int)($args['limit']??50)));$offset=max(0,(int)($args['offset']??0));$rows=[];foreach(array_slice($tags,$offset,$limit) as $tag)$rows[]=['tag_id'=>(int)$tag->getId(),'name'=>MailRows::text($tag->getDisplayName()),'color'=>$tag->getColor()===null?null:MailRows::text($tag->getColor())];$next=$offset+count($rows);return ['tags'=>$rows,'count'=>count($rows),'hasMore'=>$next<count($tags),'next_offset'=>$next<count($tags)?$next:null,'untrusted_mail_data'=>true];
    }
}
