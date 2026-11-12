<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Accounts;
use OCA\Mcp\Modules\Mail\Integration\{MailSettingsGateway,MailServices};
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
class MailIdentities {
    public function __construct(private MailSettingsGateway $accounts,private MailServices $native){}
    public function read(array $args,string $uid):array {
        $id=(int)$args['account_id'];$a=$this->accounts->account($uid,$id);$aliases=$this->native->aliasesService($uid)->findAll($id,$uid);if(count($aliases)>1000)throw new ToolFailure('Too many aliases for bounded sender discovery.');
        usort($aliases,static fn(object $a,object $b):int=>(int)$a->getId()<=>(int)$b->getId());$rows=[['account_id'=>$id,'alias_id'=>null,'name'=>MailRows::text((string)$a->getName()),'email'=>MailRows::text((string)$a->getEmail())]];
        foreach($aliases as $alias)if((int)$alias->getAccountId()===$id)$rows[]=['account_id'=>$id,'alias_id'=>(int)$alias->getId(),'name'=>MailRows::text((string)$alias->getName()),'email'=>MailRows::text((string)$alias->getAlias())];
        $limit=min(100,max(1,(int)($args['limit']??50)));$offset=max(0,(int)($args['offset']??0));$page=array_slice($rows,$offset,$limit);$next=$offset+count($page);return ['identities'=>$page,'count'=>count($page),'hasMore'=>$next<count($rows),'next_offset'=>$next<count($rows)?$next:null,'untrusted_mail_data'=>true];
    }
}
