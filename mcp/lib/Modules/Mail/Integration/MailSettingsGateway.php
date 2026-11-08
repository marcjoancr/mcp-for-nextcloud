<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCP\IAppConfig;

/** Only explicit non-secret projections cross this boundary; native entities never become tool results. */
class MailSettingsGateway {
    public function __construct(private MailGateway $accounts, private MailServices $native, private PlanState $states, private IAppConfig $config) {}

    public function account(string $uid,int $id): object {
        $account=$this->accounts->ownedAccount($uid,$id);
        if($account===null || !is_callable([$account,'getMailAccount'])) throw new ToolFailure(MailMessages::accountNotFound());
        return $account;
    }
    public function readSettings(string $uid,int $id): array {
        $a=$this->account($uid,$id)->getMailAccount();
        $prefs=['editor_mode'=>self::enum((string)$a->getEditorMode(),['plaintext','html']),'signature_above_quote'=>(bool)$a->isSignatureAboveQuote(), 'show_subscribed_only'=>(bool)$a->getShowSubscribedOnly()];
        $row=['account_id'=>$id,'settings'=>$prefs];
        return $row+['revision'=>$this->states->of('mail_settings_revision',$uid,$row)];
    }
    public function readConnection(string $uid,int $id): array {
        $a=$this->account($uid,$id)->getMailAccount();
        $row=['account_id'=>$id,'name'=>self::text((string)$a->getName(),255),'email'=>self::text((string)$a->getEmail(),500),
            'imap'=>['host'=>self::text((string)$a->getInboundHost(),255),'port'=>(int)$a->getInboundPort(),'tls'=>self::enum((string)$a->getInboundSslMode(),['ssl','tls','none','']),'user'=>self::text((string)$a->getInboundUser(),500)],
            'smtp'=>['host'=>self::text((string)$a->getOutboundHost(),255),'port'=>(int)$a->getOutboundPort(),'tls'=>self::enum((string)$a->getOutboundSslMode(),['ssl','tls','none','']),'user'=>self::text((string)$a->getOutboundUser(),500)],
            'auth_method'=>self::enum((string)$a->getAuthMethod(),['password','xoauth2']),'editable'=>$a->getProvisioningId()===null];
        return $row+['revision'=>$this->states->of('mail_connection_revision',$uid,$row)];
    }
    public function readSignature(string $uid,int $id,?int $aliasId): array {
        $a=$this->signatureEntity($uid,$id,$aliasId);
        $signature=$a->getSignature();
        if($signature!==null && mb_strlen($signature)>20000) throw new ToolFailure('The native signature is too large to review completely. Edit it in Mail.');
        $row=['account_id'=>$id,'alias_id'=>$aliasId,'signature'=>$signature,'untrusted_mail_data'=>true];
        return $row+['revision'=>$this->states->of('mail_signature_revision',$uid,$row)];
    }
    public function writeSettings(string $uid,int $id,array $changes): array {
        $a=$this->account($uid,$id)->getMailAccount();
        if(array_key_exists('editor_mode',$changes))$a->setEditorMode($changes['editor_mode']);
        if(array_key_exists('signature_above_quote',$changes))$a->setSignatureAboveQuote($changes['signature_above_quote']);
        if(array_key_exists('show_subscribed_only',$changes))$a->setShowSubscribedOnly($changes['show_subscribed_only']);
        // Native Entity tracks changed fields: never rebuild the account or copy its authentication state.
        $this->native->accountService($uid)->update($a);
        return $this->readSettings($uid,$id);
    }
    public function writeSignature(string $uid,int $id,?int $aliasId,?string $signature): array {
        $this->signatureEntity($uid,$id,$aliasId);
        if($aliasId===null)$this->native->accountService($uid)->updateSignature($id,$uid,$signature);
        else $this->native->aliasesService($uid)->updateSignature($uid,$aliasId,$signature);
        return $this->readSignature($uid,$id,$aliasId);
    }
    private function signatureEntity(string $uid,int $id,?int $aliasId): object {
        $account=$this->account($uid,$id);
        if($aliasId===null)return $account->getMailAccount();
        try{$alias=$this->native->aliasesService($uid)->find($aliasId,$uid);}
        catch(\Throwable){throw new ToolFailure('Mail identity not found.');}
        if((int)$alias->getAccountId()!==$id)throw new ToolFailure('Mail identity not found.');
        return $alias;
    }
    public const MAILBOX_ROLES=['drafts_mailbox_id'=>'DraftsMailboxId','sent_mailbox_id'=>'SentMailboxId','trash_mailbox_id'=>'TrashMailboxId','archive_mailbox_id'=>'ArchiveMailboxId'];
    public function mailboxRoles(string $uid,int $id):array {
        $e=$this->account($uid,$id)->getMailAccount();
        return ['drafts_mailbox_id'=>$e->getDraftsMailboxId(),'sent_mailbox_id'=>$e->getSentMailboxId(),'trash_mailbox_id'=>$e->getTrashMailboxId(),'archive_mailbox_id'=>$e->getArchiveMailboxId()];
    }
    public function writeMailboxRoles(string $uid,int $id,array $changes):array {
        if($changes===[]||array_diff(array_keys($changes),array_keys(self::MAILBOX_ROLES))!==[])throw new \InvalidArgumentException('Choose supported special-folder roles.');
        $e=$this->account($uid,$id)->getMailAccount();
        foreach($changes as $key=>$value){if(!is_int($value)||$value<1)throw new \InvalidArgumentException('A role requires an owned existing folder.');match($key){'drafts_mailbox_id'=>$e->setDraftsMailboxId($value),'sent_mailbox_id'=>$e->setSentMailboxId($value),'trash_mailbox_id'=>$e->setTrashMailboxId($value),'archive_mailbox_id'=>$e->setArchiveMailboxId($value)};}
        $this->native->accountService($uid)->update($e);
        return $this->mailboxRoles($uid,$id);
    }
    public function creationAllowed(): bool{return $this->config->getValueBool('mail','allow_new_mail_accounts',true);}
    public function ownedIds(string $uid): array {
        $ids=array_column($this->accounts->accounts($uid),'id');sort($ids);
        if(count($ids)>1000)throw new ToolFailure('Too many accounts for a bounded setup workflow.');
        return $ids;
    }
    public function authorizationAvailable(string $uid,int $id): bool {
        $a=$this->account($uid,$id)->getMailAccount();
        return method_exists($a,'canAuthenticateImap') && $a->canAuthenticateImap();
    }
    private static function enum(string $value,array $allowed): string {
        if(!in_array($value,$allowed,true))throw new ToolFailure('Unsupported native Mail configuration. Review it in Mail.');
        return $value;
    }
    public static function text(string $value,int $limit): string {
        if(mb_strlen($value)>$limit || preg_match('/[\x00-\x1f\x7f]/u',$value))throw new ToolFailure('Native Mail contains unsupported configuration text.');
        return $value;
    }
}
