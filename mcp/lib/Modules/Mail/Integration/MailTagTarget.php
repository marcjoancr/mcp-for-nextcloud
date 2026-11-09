<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Mutations\{MutationRequest,TagMutationRequest};
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
/** Read-only owned exact cache and tag metadata; preview never contacts IMAP or synchronizes. */
class MailTagTarget {
    public function __construct(private MailMessageLocator $locator,private MailSettingsGateway $settings,private MailCapabilities $capabilities,private MailServices $native){}
    public function snapshot(string $uid,array $args):array {
        $this->capabilities->requireSupported($uid,'tags');$located=$this->locator->locate($uid,(int)$args['message_id']);$m=$located['message'];$b=$located['mailbox'];$account=$located['account'];
        if((int)$account->getId()!==(int)$args['account_id'])throw new BeforeEffectFailure('Message not found in the selected account.');
        $g=MailCacheGeneration::of($b);$exact=$this->native->exactTags($uid);try{$exact->requireStorage();}catch(\Throwable){throw new BeforeEffectFailure('The exact native tag storage is not qualified. Use native Mail.');}
        if($g===null||$m->getTagCacheGeneration()!==$g||!$exact->verified($uid,(int)$m->getId()))throw new BeforeEffectFailure('The exact tag cache needs synchronization in native Mail. This preview does not synchronize.');
        try{$tag=$this->native->tagMapper($uid)->getTagForUser((int)$args['tag_id'],$uid);}catch(\Throwable){throw new BeforeEffectFailure('Mail tag not found.');}
        if($tag->getUserId()!==$uid||(int)$tag->getId()!==(int)$args['tag_id']||!TagMutationRequest::keyword($tag->getImapLabel()))throw new BeforeEffectFailure('This owned tag is not a supported canonical keyword. Use native Mail.');
        $before=$exact->has($uid,(int)$m->getId(),(int)$tag->getId());$profile=$this->settings->readConnection($uid,(int)$args['account_id']);
        $r=['kind'=>'tag','account_id'=>(int)$account->getId(),'message_id'=>(int)$m->getId(),'mailbox_id'=>(int)$b->getId(),'mailbox'=>(string)$b->getName(),'generation'=>$g,'uid'=>(string)$m->getUid(),'profile_revision'=>$profile['revision'],
            'cache_revision'=>hash('sha256',json_encode([$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()],JSON_THROW_ON_ERROR)),
            'tag_id'=>(int)$tag->getId(),'imap_label'=>$tag->getImapLabel(),'tag_revision'=>self::revision($tag),
            'tag_cache_revision'=>hash('sha256',json_encode(['generation'=>$g,'tag_id'=>(int)$tag->getId(),'value'=>$before],JSON_THROW_ON_ERROR)),'value'=>$args['value']];
        if(!MutationRequest::valid($r))throw new BeforeEffectFailure('The exact native tag target is not supported.');
        return ['request'=>$r,'before'=>$before,'tag'=>['id'=>(int)$tag->getId(),'name'=>MailRows::text((string)$tag->getDisplayName(),128),'color'=>$tag->getColor()]];
    }
    private static function revision(object $t):string {return hash('sha256',json_encode(['id'=>(int)$t->getId(),'owner'=>$t->getUserId(),'name'=>$t->getDisplayName(),'label'=>$t->getImapLabel(),'color'=>$t->getColor(),'default'=>$t->getIsDefaultTag()],JSON_THROW_ON_ERROR));}
}
