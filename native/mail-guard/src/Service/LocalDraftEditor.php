<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCP\AppFramework\Db\DoesNotExistException;

/** Owner-only native editor entry; no conversion, credential or outgoing operation. */
final class LocalDraftEditor {
    public function __construct(private DraftsService $drafts,private AccountService $accounts,private AliasesService $aliases,private \OCA\Mail\Service\Attachment\AttachmentService $attachmentService){}
    public function read(string $uid,int $id):array {
        $draft=$this->drafts->getMessage($id,$uid);$account=$this->accounts->find($uid,$draft->getAccountId());
        if(!$draft->getMcpDraftOnly()||$draft->getSendAt()!==null||$draft->getSmimeSign()||$draft->getSmimeEncrypt()||$draft->isPgpMime())throw new DoesNotExistException('This draft is not eligible for the bounded local editor.');
        if($draft->getAliasId()!==null){$alias=$this->aliases->find($draft->getAliasId(),$uid);if($alias->getAccountId()!==$account->getId())throw new DoesNotExistException('Draft identity unavailable.');}
        foreach([$draft->getBodyPlain(),$draft->getBodyHtml(),$draft->getEditorBody()] as $body)if($body!==null&&mb_strlen($body)>20000)throw new DoesNotExistException('Draft body exceeds the bounded editor.');
        $row=$draft->jsonSerialize();foreach(['to','cc','bcc'] as $key)if(count($row[$key])>50)throw new DoesNotExistException('Draft recipients exceed the bounded editor.');
        $attachments=$draft->getAttachments()??[];$size=0;
        foreach($attachments as $attachment){[, $file]=$this->attachmentService->getAttachment($uid,$attachment->getId());$bytes=$file->getSize();if(!is_int($bytes)||$bytes<0)throw new DoesNotExistException('Draft attachment size unavailable.');$size+=$bytes;}
        if(count($attachments)>10||$size>26214400)throw new DoesNotExistException('Draft attachments exceed the bounded editor.');
        $data=array_intersect_key($row,array_flip(['id','type','accountId','aliasId','subject','bodyPlain','bodyHtml','editorBody','isHtml','inReplyToMessageId','attachments','to','cc','bcc','smimeCertificateId','smimeSign','smimeEncrypt','isPgpMime','requestMdn','isAiGenerated']));
        if(strlen(json_encode($data,JSON_THROW_ON_ERROR))>524288)throw new DoesNotExistException('Draft editor payload exceeds its bound.');
        return $data;
    }
}
