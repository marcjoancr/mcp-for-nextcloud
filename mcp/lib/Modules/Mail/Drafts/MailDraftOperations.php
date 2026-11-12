<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Drafts;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCP\AppFramework\Db\DoesNotExistException;

/** Owner-scoped access to the Mail app's local, unsent drafts. */
final class MailDraftOperations {
	public const WRITE_TOOL = 'mail_write_draft';
	public const DELETE_TOOL = 'mail_delete_draft';
	public const MAX_DRAFTS = 50;
	public const MAX_BODY_CHARS = 20000;
	public const MAX_RECIPIENTS = 50;
	public const MAX_VISIBLE_RECIPIENTS = 3 * self::MAX_RECIPIENTS;

	public function __construct(private MailServices $services, private MailGateway $accounts, private PlanState $states,
        private ?\OCA\Mcp\Modules\Mail\Composition\ReplyComposer $composer = null,
        private ?\OCA\Mcp\Modules\Mail\Files\MailFilesSource $files = null,
        private ?\OCA\Mcp\Mcp\Operations\OperationJournal $journal = null,
        private ?\OCA\Mcp\Modules\Mail\Composition\ForwardComposer $forward = null,
        private ?\OCA\Mcp\Modules\Mail\Integration\NativeDraftLinks $editor = null,
        private ?\OCA\Mcp\Modules\Mail\Integration\NativeDraftWrites $writeScope = null,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $freshAccess=null,
        private ?\OCA\Mcp\Modules\Mail\Composition\MessageComposer $messageComposer=null) {}

	/** @return array<string,mixed> a bounded list or one locally stored draft */
	public function read(array $arguments, string $userId): array {
        $allowedAccounts=$this->accounts->allowedAccounts($userId);
        if(isset($arguments['draft_id'])&&(isset($arguments['account_id'])||isset($arguments['subject_contains'])))throw new \InvalidArgumentException('Draft list filters cannot be combined with draft_id.');
        if(isset($arguments['account_id'])&&$this->accounts->requireAccount($userId,(int)$arguments['account_id'])===null)throw new ToolFailure(MailMessages::accountNotFound());
		$service = $this->services->draftsService($userId);
		if (isset($arguments['draft_id'])) {
			$draft = $this->draft($service, $userId, (int)$arguments['draft_id']);
			$account = $this->accounts->requireAccount($userId, (int)$draft->getAccountId());
			if ($account === null) {
				throw new ToolFailure(MailMessages::draftNotFound());
			}
            $result = ['draft' => $this->row($draft, true), 'account' => ['id' => (int)$account->getId(), 'email' => (string)$account->getEmail()]];
            $link=$this->editor?->forDraft((int)$draft->getId(),$draft);if($link!==null)$result['native_edit_url']=$link;
            return $result;
		}

        if($allowedAccounts!==null||$this->services->scopedMetadataAvailable()){
            $limit=min(self::MAX_DRAFTS,max(1,(int)($arguments['limit']??20)));$offset=max(0,(int)($arguments['offset']??0));
            $page=$this->services->boundedMetadata($userId)->draftIds($userId,$this->services->localDraftType($userId),$arguments['account_id']??null,$arguments['subject_contains']??null,$limit,$offset,$allowedAccounts);
            $rows=[];foreach($page['ids'] as $id){$draft=$this->draft($service,$userId,$id);$account=$this->accounts->requireAccount($userId,(int)$draft->getAccountId());$rows[]=$this->row($draft,false)+['account'=>['id'=>(int)$account->getId(),'email'=>(string)$account->getEmail()]];}
            return ['drafts'=>$rows,'count'=>count($rows),'hasMore'=>$page['has_more'],'limit'=>$limit,'offset'=>$offset,'next_offset'=>$page['has_more']?$offset+count($rows):null];
        }
		$all = $this->services->localMessageMapper($userId)->getAllForUser($userId, $this->services->localDraftType($userId));
		usort($all, static fn (object $a, object $b): int => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$a->getId() <=> (int)$b->getId()));
		$limit = min(self::MAX_DRAFTS, max(1, (int)($arguments['limit'] ?? 20)));
		$offset = max(0, (int)($arguments['offset'] ?? 0));
		$visible = [];
		foreach ($all as $draft) {
			$account = $this->accounts->ownedAccount($userId, (int)$draft->getAccountId());
			if ($account !== null && (!isset($arguments['account_id'])||(int)$draft->getAccountId()===(int)$arguments['account_id']) && (!isset($arguments['subject_contains'])||mb_stripos((string)$draft->getSubject(),$arguments['subject_contains'])!==false)) {
				$visible[] = ['draft' => $draft, 'account' => $account];
			}
		}
		$rows = [];
		foreach (array_slice($visible, $offset, $limit) as $item) {
			$rows[] = $this->row($item['draft'], false) + ['account' => ['id' => (int)$item['account']->getId(), 'email' => (string)$item['account']->getEmail()]];
		}
		$nextOffset = $offset + count($rows);
		return ['drafts' => $rows, 'count' => count($rows), 'hasMore' => count($visible) > $nextOffset,
			'limit' => $limit, 'offset' => $offset, 'next_offset' => count($visible) > $nextOffset ? $nextOffset : null];
	}

	/** The preview of creating or updating one local draft. */
	public function previewWrite(array $arguments, string $userId): array {
		$prepared = $this->prepareWrite($arguments, $userId);
		return [
			'action' => $prepared['draft'] === null ? 'create' : 'update',
			'account' => ['id' => $prepared['accountId'], 'email' => (string)$prepared['account']->getEmail()],
			'draft_id' => $prepared['draft'] === null ? null : (int)$prepared['draft']->getId(),
			'before' => $prepared['draft'] === null ? null : $this->row($prepared['draft'], true),
			'after' => \OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields($prepared['fields']),
			PlanState::ARGUMENT => $this->states->of(self::WRITE_TOOL, $userId, $this->writeState($prepared)),
			'message' => MailMessages::draftPlan(),
            'warnings' => ($prepared['fields']['draft_only']??false) ? [['message'=>MailMessages::draftOnlyEditor()]] : array_merge($prepared['fields']['in_reply_to'] === null ? [] : [['message' => 'This reply stays local in Mail to preserve its thread; IMAP auto-sync is held. Editing it in the native UI may change that state.']],$prepared['fields']['alias_id'] === null ? [] : [['message'=>'Native Mail may use the account sender when synchronizing this alias draft. Review the sender in Mail.']]),
		];
	}

	/** Writes only a local draft, after the plan still matches current Mail state. */
	public function write(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$prepared = isset($arguments['forward_message_id'])?\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->prepareWrite($arguments,$userId)):$this->prepareWrite($arguments, $userId);
		if (!PlanState::matches($this->states->of(self::WRITE_TOOL, $userId, $this->writeState($prepared)), $arguments)) {
			$plan = $this->previewWrite($arguments, $userId);
			throw new PlanChanged($plan + ['warnings' => [['message' => MailMessages::draftPlanChanged()]]]);
		}
        $effect=fn():array=>$this->writePrepared($arguments,$userId,$prepared);
        return $this->writeScope===null?$effect():$this->writeScope->run($userId,$effect);
    }

    private function writePrepared(array $arguments,string $userId,array $prepared):array {
        $this->freshAccess?->assertActive($userId);$this->accounts->requireAccount($userId,$prepared['accountId']);
        if (isset($arguments['repair_of_operation_id'])) $this->journal?->claimRepair($userId, $arguments['repair_of_operation_id']);
		$fields = $prepared['fields'];
        $added = $prepared['add_manifest'] === [] ? [] : $this->files->stage($userId, $prepared['add_manifest'], $prepared['reusable'], $prepared['draft'] === null ? null : (int)$prepared['draft']->getId());
        $fileResources=[];foreach($added as $i=>$a)$fileResources[]=['attachment_id'=>$a['id'],'file_id'=>$prepared['add_manifest'][$i]['file_id'],'sha256'=>$prepared['add_manifest'][$i]['sha256']];
        $forwardAdded=$prepared['forward_manifest']===[]?[]:$this->forward->stage($userId,$prepared['accountId'],$prepared['forward_manifest'],$prepared['reusable'],$fileResources);
        $added=array_merge($added,$forwardAdded);
        $attachments = array_values(array_column(array_merge(array_map(static fn(array $a): array => ['type' => 'local', 'id' => $a['id']], $prepared['retained']), $added), null, 'id'));
		$draft = $prepared['draft'] ?? $this->services->newLocalDraft($userId);
		$draft->setType($this->services->localDraftType($userId));
		$draft->setAccountId($prepared['accountId']);
		$draft->setSubject($fields['subject']);
		$draft->setBodyPlain($fields['body_plain']);
		$draft->setBodyHtml($fields['body_html']);
		$draft->setHtml($fields['is_html']);
		$draft->setEditorBody($fields['is_html'] ? $fields['body_html'] : $fields['body_plain']);
		$draft->setUpdatedAt(time());
        if (is_callable([$draft, 'setInReplyToMessageId'])) $draft->setInReplyToMessageId($fields['in_reply_to']);
        if (is_callable([$draft, 'setAliasId'])) $draft->setAliasId($fields['alias_id']);
        // Preserve the parent against Mail's lossy draft-to-IMAP auto-flush.
        if ($fields['in_reply_to'] !== null) $draft->setFailed(true);
        if(($fields['draft_only']??false)===true){$draft->setMcpDraftOnly(true);$draft->setFailed(true);}
        if(isset($fields['forward_source']))$this->forward->verify($userId,$prepared['accountId'],$fields['forward_source']);
		$service = $this->services->draftsService($userId);
        $this->freshAccess?->assertActive($userId);
        $this->journal?->checkpoint(['native_write_started' => true]);
        try {
            $saved = $prepared['draft'] === null
                ? $service->saveMessage($prepared['account'], $draft, $fields['to'], $fields['cc'], $fields['bcc'], $attachments)
                : $service->updateMessage($prepared['account'], $draft, $fields['to'], $fields['cc'], $fields['bcc'], $attachments);
        } catch (\Throwable $e) {
            if ($draft->getId() !== null) $this->journal?->checkpoint(['draft_id' => (int)$draft->getId()]);
            throw $e;
        }
        $this->journal?->checkpoint(['draft_id' => (int)$saved->getId()]);
        $actualIds = array_map(static fn(object $a): int => (int)$a->getId(), (array)($saved->getAttachments() ?? []));
        $expectedIds = array_column($attachments, 'id');
        sort($actualIds); sort($expectedIds);
        if ($actualIds !== $expectedIds) throw new ToolFailure('Draft attachment associations could not be verified.');
		return ['draft' => $this->row($saved, true), 'message' => MailMessages::draftSaved()];
	    }

	public function previewDelete(array $arguments, string $userId): array {
		$prepared = $this->prepareDelete($arguments, $userId);
		return [
			'action' => 'delete', 'draft' => $this->row($prepared['draft'], true),
			PlanState::ARGUMENT => $this->states->of(self::DELETE_TOOL, $userId, $this->deleteState($prepared)),
			'message' => MailMessages::draftDeletePlan(),
		];
	}

	public function delete(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$prepared = $this->prepareDelete($arguments, $userId);
		if (!PlanState::matches($this->states->of(self::DELETE_TOOL, $userId, $this->deleteState($prepared)), $arguments)) {
			$plan = $this->previewDelete($arguments, $userId);
			throw new PlanChanged($plan + ['warnings' => [['message' => MailMessages::draftPlanChanged()]]]);
		}
        $effect=function()use($prepared,$userId):array {
            $this->freshAccess?->assertActive($userId);$this->accounts->requireAccount($userId,(int)$prepared['draft']->getAccountId());
            $this->services->draftsService($userId)->deleteMessage($userId,$prepared['draft']);
            return ['deleted_draft_id'=>(int)$prepared['draft']->getId(),'message'=>MailMessages::draftDeleted()];
        };
        return $this->writeScope===null?$effect():$this->writeScope->run($userId,$effect);
	}

	/** @return array{account:object,accountId:int,draft:object|null,fields:array<string,mixed>} */
	private function prepareWrite(array $arguments, string $userId): array {
		$accountId = (int)$arguments['account_id'];
		$account = $this->accounts->requireAccount($userId, $accountId);
		if ($account === null) {
			throw new ToolFailure(MailMessages::accountNotFound());
		}
		$draft = isset($arguments['draft_id']) ? $this->draft($this->services->draftsService($userId), $userId, (int)$arguments['draft_id']) : null;
		if ($draft !== null && (int)$draft->getAccountId() !== $accountId) {
			throw new ToolFailure(MailMessages::draftNotFound());
		}

        if ($draft !== null) {
            try { \OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields(['body_plain'=>$draft->getBodyPlain()??'', 'body_html'=>$draft->getBodyHtml()??'']); }
            catch (ToolFailure) { throw new ToolFailure(MailMessages::draftTooLarge(self::MAX_BODY_CHARS)); }
        }
		$currentRecipients = $draft === null ? ['to' => [], 'cc' => [], 'bcc' => []] : $this->recipientFields($draft);
		$fields = [
			'subject' => (string)($arguments['subject'] ?? $draft?->getSubject() ?? ''),
			'body_plain' => (string)(array_key_exists('body_plain', $arguments) ? $arguments['body_plain'] : ($draft?->getBodyPlain() ?? '')),
			'body_html' => (string)(array_key_exists('body_html', $arguments) ? $arguments['body_html'] : ($draft?->getBodyHtml() ?? '')),
			'is_html' => (bool)(array_key_exists('is_html', $arguments) ? $arguments['is_html'] : ($draft?->isHtml() ?? false)),
			'to' => array_key_exists('to', $arguments) ? $this->recipients($arguments['to']) : $currentRecipients['to'],
			'cc' => array_key_exists('cc', $arguments) ? $this->recipients($arguments['cc']) : $currentRecipients['cc'],
			'bcc' => array_key_exists('bcc', $arguments) ? $this->recipients($arguments['bcc']) : $currentRecipients['bcc'],
		];
        $composition = $arguments['composition'] ?? 'user_preferences';
        if (!in_array($composition, ['user_preferences', 'complete_body'], true)) throw new \InvalidArgumentException('Unsupported composition mode.');
        if ($draft !== null && array_key_exists('composition', $arguments) && $composition !== 'complete_body') throw new \InvalidArgumentException('Existing draft bodies must be edited explicitly.');
        $compose = $draft === null && $composition === 'user_preferences' && $this->messageComposer !== null;
        $quote = null;
        $preferences = null;
        $forward_manifest=[];
        if(isset($arguments['forward_attachment_ids'])&&!isset($arguments['forward_message_id']))throw new \InvalidArgumentException('Forward MIME parts require a forward source.');
        if(isset($arguments['forward_message_id'])){
            if($draft!==null||isset($arguments['reply_to_message_id'])||isset($arguments['reply_mode'])||$this->forward===null)throw new \InvalidArgumentException('Forwarding is for new drafts and cannot be combined with a reply or draft update.');
            $f=$this->forward->prepare($userId,$accountId,(int)$arguments['forward_message_id'],$arguments['forward_attachment_ids']??[],!$compose && (array_key_exists('body_plain',$arguments)||array_key_exists('body_html',$arguments)));
            $forward_manifest=$f['attachments'];$fields['forward_source']=$f['source'];if($f['body_plain']!==null){if($compose)$quote=['text'=>$f['body_plain']];else{$fields['body_plain']=$f['body_plain'];$fields['body_html']='';$fields['is_html']=false;}}
        }
        if (preg_match('/[\r\n\x00]/', $fields['subject'])) throw new \InvalidArgumentException('Invalid mail subject.');
        $alias = array_key_exists('alias_id', $arguments) ? $arguments['alias_id'] : self::native($draft, 'getAliasId');
        $identity = $this->composer?->identities($userId, $account, $alias);
        if ($identity === null && (isset($arguments['alias_id']) || isset($arguments['reply_to_message_id']))) throw new ToolFailure('Mail composition is unavailable.');
        $fields['alias_id'] = $alias;
        $fields['sender'] = $identity['sender'] ?? ['email' => (string)$account->getEmail(), 'alias_id' => $alias];
        $fields['in_reply_to'] = self::native($draft, 'getInReplyToMessageId');
        if (isset($arguments['reply_to_message_id'])) {
            $reply = $this->composer->reply($userId, $accountId, (int)$arguments['reply_to_message_id'], ($arguments['reply_mode'] ?? 'reply') === 'reply_all', $identity['own'], $compose);
            $fields['in_reply_to'] = $reply['in_reply_to'];
            $quote = $reply['quote'];
            foreach (['to', 'cc', 'bcc'] as $field) if (!array_key_exists($field, $arguments)) $fields[$field] = $reply[$field];
        }
        if ($compose) {
            $preferences = $this->messageComposer->preferences($userId, $accountId, $alias);
            $fields = $this->messageComposer->compose($fields, $arguments, $preferences, $quote);
        }
        foreach (['to', 'cc', 'bcc'] as $field) if (count($fields[$field]) > self::MAX_RECIPIENTS) throw new ToolFailure(MailMessages::draftTooManyRecipients(self::MAX_RECIPIENTS));
        $existing = $this->attachmentManifest($userId, $draft);
        $remove = array_map('intval', $arguments['remove_attachment_ids'] ?? []);
        if (array_diff($remove, array_column($existing, 'id')) !== []) throw new ToolFailure('Draft attachment not found.');
        if ($remove !== []) {
            if ($this->files === null) throw new ToolFailure('Files attachment operations are unavailable.');
            $this->files->authorize($userId);
        }
        $retained = array_values(array_filter($existing, static fn(array $a): bool => !in_array($a['id'], $remove, true)));
        $paths = $arguments['add_files'] ?? [];
        if ($paths !== [] && $this->files === null) throw new ToolFailure('Files attachment operations are unavailable.');
        $add_manifest = $paths === [] ? [] : $this->files->preview($userId, $paths);
        $reusable = [];
        if (isset($arguments['repair_of_operation_id'])) {
            if ($this->journal === null) throw new ToolFailure('Operation repair is unavailable.');
            $repair = $this->journal->repairResources($userId, $arguments['repair_of_operation_id']);
            if (isset($repair['draft_id']) && (int)$repair['draft_id'] !== ($draft === null ? null : (int)$draft->getId())) throw new ToolFailure('Repair requires the observed draft.');
            $reusable = $repair['staged'] ?? [];
        }
        $newAttachments=array_values(array_filter($add_manifest, static function(array $source) use ($retained,$reusable): bool {
            foreach ($reusable as $snapshot) {
                if ($snapshot['file_id']!==$source['file_id'] || $snapshot['sha256']!==$source['sha256']) continue;
                foreach ($retained as $existing) {
                    if ($existing['id']===$snapshot['attachment_id'] && $existing['sha256']===$source['sha256']
                        && $existing['name']===$source['name'] && $existing['mimeType']===$source['mime_type']) return false;
                }
            }
            return true;
        }));
        if (count($retained)+count($newAttachments)+count($forward_manifest)>10 || array_sum(array_column($retained,'size'))+array_sum(array_column($newAttachments,'size'))+array_sum(array_column($forward_manifest,'size'))>26214400) throw new ToolFailure('Draft attachment limit exceeded.');
        $fields['attachments']=array_merge($retained,$newAttachments,$forward_manifest);
        if($this->editor?->available()){
            if($draft!==null&&(self::native($draft,'getSendAt')!==null||self::native($draft,'getSmimeSign')||self::native($draft,'getSmimeEncrypt')||self::native($draft,'isPgpMime')))throw new ToolFailure('Use native Mail for scheduled or cryptographic drafts.');
            $fields['draft_only']=true;
        }
        \OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields($fields);
        return compact('account', 'accountId', 'draft', 'fields', 'retained', 'add_manifest', 'reusable', 'forward_manifest', 'preferences');
	}

	/** @return array{draft:object,account:object} */
	private function prepareDelete(array $arguments, string $userId): array {
		$draft = $this->draft($this->services->draftsService($userId), $userId, (int)$arguments['draft_id']);
		$account = $this->accounts->requireAccount($userId, (int)$draft->getAccountId());
		if ($account === null) {
			throw new ToolFailure(MailMessages::draftNotFound());
		}
		return compact('draft', 'account');
	}

	private function draft(object $service, string $userId, int $id): object {
        $this->accounts->requireDraft($userId,$id);
		try {
			return $service->getMessage($id, $userId);
		} catch (DoesNotExistException) {
			throw new ToolFailure(MailMessages::draftNotFound());
		}
	}

	private function writeState(array $prepared): array {
		return ['accountId' => $prepared['accountId'], 'draftId' => $prepared['draft'] === null ? null : (int)$prepared['draft']->getId(),
			'before' => $prepared['draft'] === null ? null : $this->snapshot($prepared['draft']), 'after' => $prepared['fields'], 'sources' => $prepared['add_manifest'], 'composition_preferences' => $prepared['preferences']];
	}

	private function deleteState(array $prepared): array {
		return ['draftId' => (int)$prepared['draft']->getId(), 'accountId' => (int)$prepared['draft']->getAccountId(), 'before' => $this->snapshot($prepared['draft'])];
	}

	private function snapshot(object $draft): array {
		return ['updatedAt' => $draft->getUpdatedAt(), 'subject' => $draft->getSubject(), 'bodyPlain' => $draft->getBodyPlain(),
			'bodyHtml' => $draft->getBodyHtml(), 'parent' => self::native($draft, 'getInReplyToMessageId'), 'alias' => self::native($draft, 'getAliasId'),
            'failed' => self::native($draft, 'isFailed'), 'attachments' => $this->attachmentRows($draft), 'recipientFingerprint' => $this->recipientFingerprint($draft)];
	}

	private function row(object $draft, bool $includeBody): array {
		$row = ['id' => (int)$draft->getId(), 'account_id' => (int)$draft->getAccountId(), 'subject' => MailRows::text((string)$draft->getSubject()),
			'is_html' => (bool)$draft->isHtml(), 'updated_at' => $draft->getUpdatedAt(),
            'in_reply_to' => self::native($draft, 'getInReplyToMessageId'), 'alias_id' => self::native($draft, 'getAliasId'),
            'sync_held' => self::native($draft, 'getInReplyToMessageId') !== null && self::native($draft, 'isFailed') === true, 'recipients' => $this->recipientRows($draft),
			'recipientCount' => count((array)($draft->getRecipients() ?? [])),
			'attachments' => $this->attachmentRows($draft),
			'recipientsTruncated' => count((array)($draft->getRecipients() ?? [])) > self::MAX_VISIBLE_RECIPIENTS,
			'attachmentsTruncated' => count((array)($draft->getAttachments() ?? [])) > 100];
		if ($includeBody) {
			$plain = (string)($draft->getBodyPlain() ?? '');
			$html = (string)($draft->getBodyHtml() ?? '');
            try { $row += \OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields(['body_plain'=>$plain,'body_html'=>$html]); $row['bodyTruncated']=false; }
            catch (ToolFailure) { $row['body_plain']=mb_substr($plain,0,self::MAX_BODY_CHARS);$row['body_html']=mb_substr($html,0,self::MAX_BODY_CHARS);$row['bodyTruncated']=true; }
		}
		return $row;
	}

	private function recipientRows(object $draft): array {
		$rows = [];
		foreach (array_slice((array)($draft->getRecipients() ?? []), 0, self::MAX_VISIBLE_RECIPIENTS) as $recipient) {
			$field = match ((int)$recipient->getType()) { 1 => 'to', 2 => 'cc', 3 => 'bcc', default => null };
			if ($field !== null) {
				$rows[$field][] = ['label' => MailRows::text((string)$recipient->getLabel()), 'email' => MailRows::text((string)$recipient->getEmail())];
			}
		}
		return ['to' => $rows['to'] ?? [], 'cc' => $rows['cc'] ?? [], 'bcc' => $rows['bcc'] ?? []];
	}

	/** @return array{to:list<array{label:string,email:string}>,cc:list<array{label:string,email:string}>,bcc:list<array{label:string,email:string}>} exact native recipient values for a mutation plan */
	private function recipientFields(object $draft): array {
		$rows = ['to' => [], 'cc' => [], 'bcc' => []];
		foreach ((array)($draft->getRecipients() ?? []) as $recipient) {
			$field = match ((int)$recipient->getType()) { 1 => 'to', 2 => 'cc', 3 => 'bcc', default => null };
			if ($field === null) {
				continue;
			}
			if (count($rows[$field]) >= self::MAX_RECIPIENTS) {
				throw new ToolFailure(MailMessages::draftTooManyRecipients(self::MAX_RECIPIENTS));
			}
			$rows[$field][] = ['label' => (string)$recipient->getLabel(), 'email' => (string)$recipient->getEmail()];
		}
		return array_map(static function (array $items): array {
			return array_slice($items, 0, self::MAX_RECIPIENTS);
		}, $rows);
	}

	private function recipientFingerprint(object $draft): string {
		$rows = ['to' => [], 'cc' => [], 'bcc' => []];
		foreach ((array)($draft->getRecipients() ?? []) as $recipient) {
			$field = match ((int)$recipient->getType()) { 1 => 'to', 2 => 'cc', 3 => 'bcc', default => null };
			if ($field !== null) {
				$rows[$field][] = ['label' => (string)$recipient->getLabel(), 'email' => (string)$recipient->getEmail()];
			}
		}
		return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function attachmentRows(object $draft): array {
		return array_slice(array_map(static fn (object $a): array => ['id' => self::native($a, 'getId'), 'name' => MailRows::text((string)$a->getFileName()), 'mimeType' => MailRows::text((string)$a->getMimeType())], (array)($draft->getAttachments() ?? [])), 0, 100);
	}

    private static function native(?object $value, string $method): mixed {
        if ($value === null || !is_callable([$value, $method])) return null;
        return match ($method) {
            'getId' => $value->getId(), 'getAliasId' => $value->getAliasId(),
            'getSendAt' => $value->getSendAt(), 'getSmimeSign' => $value->getSmimeSign(),
            'getSmimeEncrypt' => $value->getSmimeEncrypt(), 'isPgpMime' => $value->isPgpMime(),
            'getInReplyToMessageId' => $value->getInReplyToMessageId(), 'isFailed' => $value->isFailed(),
            default => throw new \LogicException('Unsupported native draft field.'),
        };
    }

    private function attachmentManifest(string $uid, ?object $draft): array {
        $out = []; $size = 0;
        foreach ((array)($draft?->getAttachments() ?? []) as $a) {
            $id = self::native($a, 'getId');
            if (!is_int($id) || $id < 1) throw new ToolFailure(MailMessages::draftAttachmentsCannotEdit());
            [, $file] = $this->services->attachmentService($uid)->getAttachment($uid, $id);
            $size += (int)$file->getSize();
            if (count($out) >= 10 || $size > 26214400) throw new ToolFailure('Draft attachment limit exceeded.');
            $bytes = $file->getContent();
            if (strlen($bytes) !== (int)$file->getSize() || strlen($bytes) > 26214400) throw new ToolFailure('Draft attachment limit exceeded.');
            $out[] = ['id' => $id, 'name' => (string)$a->getFileName(), 'mimeType' => (string)$a->getMimeType(), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        return $out;
    }

	/** @return list<array{label?:string,email:string}> */
	private function recipients(array $items): array {
		foreach ($items as $item) { if (!filter_var(trim((string)$item['email']), FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', (string)$item['email'] . (string)($item['label'] ?? ''))) throw new \InvalidArgumentException('Invalid mail recipient.'); }
        return array_values(array_map(static fn (array $item): array => ['email' => trim((string)$item['email'])] + (isset($item['label']) ? ['label' => trim((string)$item['label'])] : []), $items));
	}
}
