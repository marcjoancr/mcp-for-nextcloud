<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
/** Owned cache metadata only. Role resolution never contacts IMAP or creates a destination. */
class MailMoveTarget {
    public function __construct(private MailMessageLocator $locator,private MailMessageGateway $messages,private MailSettingsGateway $settings,private MailCapabilities $capabilities,private PlanState $states){}
    public function snapshot(string $uid,array $args):array {
        $this->capabilities->requireSupported($uid,'move');
        if(!in_array($args['action']??null,['move','archive','trash','restore'],true))throw new \InvalidArgumentException('Choose a supported move action.');
        $l=$this->locator->locate($uid,(int)$args['message_id']);$message=$l['message'];$source=$l['mailbox'];$account=$l['account'];$a=$account->getMailAccount();
        if((int)$account->getId()!==(int)$args['account_id'])throw new BeforeEffectFailure('Message not found in the selected account.');
        $roles=['archive'=>$a->getArchiveMailboxId(),'trash'=>$a->getTrashMailboxId()];
        $id=match($args['action']){'archive'=>$roles['archive'],'trash'=>$roles['trash'],default=>$args['destination_mailbox_id']??null};
        if(!is_int($id)||$id<1)throw new BeforeEffectFailure('An existing destination folder is required. Configure Mail first.');
        $dest=$this->messages->mailbox($uid,$id);
        if($dest===null||(int)$dest->getAccountId()!==(int)$account->getId()||$id===(int)$source->getId()||$dest->getName()===$source->getName()||$source->getSelectable()!==true||$dest->getSelectable()!==true)throw new BeforeEffectFailure('Choose distinct selectable folders in the same owned account.');
        if($args['action']==='restore'&&((int)$source->getId()!==$roles['trash']||$id===$roles['trash']))throw new BeforeEffectFailure('Restore requires a message in the configured Trash and an explicit destination.');
        $sg=MailCacheGeneration::of($source);$dg=MailCacheGeneration::of($dest);
        if($sg===null||$dg===null)throw new BeforeEffectFailure('Both folders need verified native cache generations. Use native Mail.');
        $profile=$this->settings->profileRevision($uid,(int)$args['account_id'],(int)$source->getId());
        $revision=static fn(object $b):string=>hash('sha256',json_encode([$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()],JSON_THROW_ON_ERROR));
        $r=['account_id'=>(int)$account->getId(),'message_id'=>(int)$message->getId(),'mailbox_id'=>(int)$source->getId(),'mailbox'=>(string)$source->getName(),'generation'=>$sg,'uid'=>(string)$message->getUid(),
            'profile_revision'=>$profile,'cache_revision'=>$revision($source),'action'=>$args['action'],'destination_mailbox_id'=>$id,'destination_mailbox'=>(string)$dest->getName(),'destination_generation'=>$dg,
            'destination_cache_revision'=>$revision($dest),'roles_revision'=>$this->states->of('mail_move_roles',$uid,$roles),'requires_trash_hook'=>$id===$roles['trash']];
        if(!MutationRequest::valid($r))throw new BeforeEffectFailure('The native MOVE identity is not supported.');
        return ['request'=>$r];
    }
}
