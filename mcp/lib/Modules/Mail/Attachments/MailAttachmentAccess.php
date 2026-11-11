<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Attachments;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailCacheGeneration;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Integration\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;

/** Bounded attachment reads against the same cached message generation Mail synchronized. */
class MailAttachmentAccess {
    private ?\OCA\Mcp\Modules\Mail\Integration\MailContentReader $content=null;
	public const MAX_ATTACHMENT_BYTES = 1048576;
	public const MAX_ATTACHMENTS = 100;

	public function __construct(private MailMessageLocator $locator, private MailServices $services, private MailBodyPreflight $preflight = new MailBodyPreflight(), ?\OCA\Mcp\Modules\Mail\Integration\MailContentReader $content=null) {$this->content=$content;}

	/** @return list<array{id:string,name:?string,mimeType:string,size:int}> */
	public function list(string $userId, int $messageId): array {
        if($this->bounded($userId)){
            $manifest=$this->content->listParts($userId,$messageId,'mail_read_attachment');$rows=[];foreach($manifest['parts'] as $p)if($p['leaf']&&($p['disposition']==='attachment'||$p['filename']!==null||$p['content_id']!==null))$rows[]=['id'=>$p['part_id'],'name'=>$p['filename'],'mimeType'=>$p['mime_type'],'size'=>$p['advertised_size'],'size_verified'=>false];return $rows;
        }
		return array_map($this->metadata(...), array_slice($this->attachments($userId, $messageId), 0, self::MAX_ATTACHMENTS));
	}

	/** @return array{id:string,name:?string,mimeType:string,size:int,content_base64:string} */
	public function read(string $userId, int $messageId, string $attachmentId): array {
        if($this->bounded($userId)){
            $r=$this->content->readPart($userId,$messageId,$attachmentId);if($r['byte_count']>self::MAX_ATTACHMENT_BYTES)throw new ToolFailure(MailMessages::attachmentTooLarge($r['byte_count'],self::MAX_ATTACHMENT_BYTES));
            return ['id'=>$attachmentId,'name'=>$r['filename'],'mimeType'=>$r['mime_type'],'size'=>$r['byte_count'],'content_base64'=>base64_encode($r['bytes'])];
        }
		if (!preg_match('/^[0-9]+(?:\.[0-9]+)*$/D', $attachmentId)) {
			throw new ToolFailure(MailMessages::attachmentNotFound());
		}
		foreach ($this->attachments($userId, $messageId) as $attachment) {
			if ((string)$attachment->getId() !== $attachmentId) {
				continue;
			}
			$content = (string)$attachment->getContent();
			if (strlen($content) > self::MAX_ATTACHMENT_BYTES) {
				throw new ToolFailure(MailMessages::attachmentTooLarge(strlen($content), self::MAX_ATTACHMENT_BYTES));
			}
			return $this->metadata($attachment) + ['content_base64' => base64_encode($content)];
		}
		throw new ToolFailure(MailMessages::attachmentNotFound());
	}

    private function bounded(string $uid):bool {
        if($this->content===null)return false;
        if($this->content->available($uid))return true;
        if($this->content->mustUseBounded())throw new ToolFailure(MailMessages::contentUnavailable());
        return false;
    }

    /** Internal direct-to-Files bytes; never base64-encode the larger private result. */
    public function readForSave(string $uid,int $message,string $part):array {
        if($this->bounded($uid))return $this->content->readPart($uid,$message,$part,'mail_save_attachment');
        $r=$this->read($uid,$message,$part);$bytes=base64_decode($r['content_base64'],true);if($bytes===false)throw new ToolFailure(MailMessages::attachmentUnavailable());
        return ['bytes'=>$bytes,'byte_count'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'part_id'=>$r['id'],'filename'=>$r['name'],'mime_type'=>$r['mimeType']];
    }
    public function assertSaveSource(string $uid,array $source):void {$this->content?->assertSource($uid,$source,'mail_save_attachment');}

	/** @return list<object> Mail Attachment objects */
	private function attachments(string $userId, int $messageId): array {
		$found = $this->locator->locate($userId, $messageId);
		$mailbox = $found['mailbox'];
		$generation = MailCacheGeneration::of($mailbox);
		if ($generation === null) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		$client = null;
		try {
			$client = $this->services->clientFactory($userId)->getClient($found['account']);
			$mailboxName = (string)$mailbox->getName();
			if ($this->preflight->uidValidity($client, $mailboxName) !== $generation) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$this->preflight->check($client, $mailboxName, (int)$found['message']->getUid(), $found['message']->getMessageId() === null ? null : (string)$found['message']->getMessageId());
			if ($this->preflight->uidValidity($client, $mailboxName) !== $generation) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$rows = $this->services->imapMessageMapper($userId)->getAttachments($client, $mailboxName, (int)$found['message']->getUid(), $userId);
			if ($this->preflight->uidValidity($client, $mailboxName) !== $generation) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			if (count($rows) > self::MAX_ATTACHMENTS) {
				throw new ToolFailure(MailMessages::tooManyAttachments(self::MAX_ATTACHMENTS));
			}
			foreach ($rows as $attachment) {
				$size = strlen((string)$attachment->getContent());
				if ($size > self::MAX_ATTACHMENT_BYTES) {
					throw new ToolFailure(MailMessages::attachmentTooLarge($size, self::MAX_ATTACHMENT_BYTES));
				}
			}
			return array_values($rows);
		} catch (ToolFailure $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ToolFailure(MailMessages::attachmentUnavailable(), 0, $e);
		} finally {
			try {
				$client?->logout();
			} catch (\Throwable) {
			}
		}
	}

	private function metadata(object $attachment): array {
		return [
			'id' => MailRows::text((string)$attachment->getId()),
			'name' => $attachment->getName() === null ? null : MailRows::text((string)$attachment->getName()),
			'mimeType' => MailRows::text((string)$attachment->getType()),
			'size' => strlen((string)$attachment->getContent()),
		];
	}
}
