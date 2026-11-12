<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Mailboxes\MailboxTree;
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
/** Cache-only preview/worker binding; protocol remains inside the qualified optional native dependency. */
class MailboxMutationTarget {
    public function __construct(private MailServices $native,private MailSettingsGateway $settings,private MailCapabilities $capabilities){}
    public function snapshot(string $uid,array $args):array {
        $this->capabilities->requireSupported($uid,$args['action']);$id=(int)$args['account_id'];$profile=$this->settings->readConnection($uid,$id);$service=$this->native->guardedMailboxes($uid);$m=$service->metadata($uid,$id);
        if(($args['kind']??null)==='mailbox'){$d=$args['delimiter'];$p=strrpos($args['new_name'],$d);$args['name']=$p===false?$args['new_name']:substr($args['new_name'],$p+1);if($args['action']==='create_mailbox'&&$args['mailbox_id']>0)$args['parent_mailbox_id']=$args['mailbox_id'];}
        $r=['kind'=>'mailbox','account_id'=>$id]+MailboxTree::prepare($m,$args)+['inventory_revision'=>$service->revision($m),'profile_revision'=>$profile['revision']];
        if(!MutationRequest::valid($r)||strlen(json_encode($r,JSON_THROW_ON_ERROR))>60000)throw new BeforeEffectFailure('Folder request exceeds the supported boundary.');return ['request'=>$r];
    }
}
