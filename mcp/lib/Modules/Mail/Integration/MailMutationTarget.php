<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
/** Owned cache metadata only: no IMAP preflight/client escapes into preview or callers. */
class MailMutationTarget {
    public function __construct(private MailMessageLocator $locator,private MailSettingsGateway $settings,private MailCapabilities $capabilities,private ?MailMoveTarget $moves=null,private ?MailboxMutationTarget $mailboxes=null,private ?MailTagTarget $tags=null){}
    public function snapshot(string $uid,array $args):array {
        if(($args['kind']??null)==='tag'||isset($args['tag_id'])){if($this->tags===null)throw new BeforeEffectFailure('Exact native tag target unavailable.');return $this->tags->snapshot($uid,$args);}
        if(($args['kind']??null)==='mailbox'||in_array($args['action']??null,['create_mailbox','rename_mailbox','subscribe_mailbox'],true)){if($this->mailboxes===null)throw new BeforeEffectFailure('Native folder target unavailable.');return $this->mailboxes->snapshot($uid,$args);}
        if(isset($args['action'])){if($this->moves===null)throw new BeforeEffectFailure('Native move target unavailable.');return $this->moves->snapshot($uid,$args);}
        $this->capabilities->requireSupported($uid,'flags');$located=$this->locator->locate($uid,(int)$args['message_id']);
        $message=$located['message'];$mailbox=$located['mailbox'];$account=$located['account'];
        if((int)$account->getId()!==(int)$args['account_id'])throw new BeforeEffectFailure('Message not found in the selected account.');
        $generation=MailCacheGeneration::of($mailbox);if($generation===null)throw new BeforeEffectFailure('The source cache generation is unavailable. Use native Mail.');
        $profile=$this->settings->readConnection($uid,(int)$args['account_id']);
        $r=['account_id'=>(int)$account->getId(),'message_id'=>(int)$message->getId(),'mailbox_id'=>(int)$mailbox->getId(),'mailbox'=>(string)$mailbox->getName(),
            'generation'=>$generation,'uid'=>(string)$message->getUid(),'flag'=>$args['flag'],'value'=>$args['value'],'profile_revision'=>$profile['revision'],
            'cache_revision'=>hash('sha256',json_encode([$mailbox->getSyncNewToken(),$mailbox->getSyncChangedToken(),$mailbox->getSyncVanishedToken()],JSON_THROW_ON_ERROR))];
        if(!MutationRequest::valid($r))throw new BeforeEffectFailure('The native message identity is not supported.');
        return ['request'=>$r,'before'=>$args['flag']==='seen'?(bool)$message->getFlagSeen():(bool)$message->getFlagFlagged()];
    }
}
