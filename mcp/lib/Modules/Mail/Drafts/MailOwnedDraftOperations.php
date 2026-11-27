<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Drafts;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Contracts\MailOwnedDraftStore;
use OCA\Mcp\Modules\Mail\Contracts\MailOwnedDraftSender;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;

/** App-owned plain-text drafts and their explicit one-attempt send state. */
final class MailOwnedDraftOperations {
	public const READ_TOOL = 'mail_read_owned_drafts';
	public const WRITE_TOOL = 'mail_write_owned_draft';
	public const DELETE_TOOL = 'mail_delete_owned_draft';
	public const SEND_TOOL = 'mail_send_owned_draft';
	public const MAX_DRAFTS = 50;
	public const MAX_PLAINTEXT_BYTES = 900_000;
	public const MAX_BODY_CHARS = 20000;
	public const MAX_SUBJECT_CHARS = 1000;
	public const MAX_RECIPIENTS = 50;

	/** @param \Closure(string,string,int):\Closure $resourceFence fresh current-operation/account fence
	 *  @param \Closure():bool|null $officialMode dynamic fail-closed gate
	 */
	public function __construct(
		private MailOwnedDraftStore $store,
		private ActorIdentity $actors,
		private MailGateway $accounts,
		private PlanState $states,
		private ITimeFactory $clock,
		private ConnectionContext $connection,
		private \Closure $resourceFence,
		private ?\Closure $officialMode = null,
		private ?MailOwnedDraftSender $sender = null,
	) {}

	/** @return array<string,mixed> */
	public function read(array $arguments, string $uid): array {
		$this->assertOfficial();
		$accountId = $this->accountId($arguments);
		$account = $this->accounts->requireAccount($uid, $accountId);
		$actor = $this->actors->capture($uid);
		$this->actors->assertCurrent($uid, $actor);
		$fence = ($this->resourceFence)($uid, self::READ_TOOL, $accountId);
		$fence();
		$snapshot = $this->store->load($uid, $actor);
		$this->actors->assertCurrent($uid, $actor);
		$fence();
		$visible = array_values(array_filter($snapshot['drafts'], static fn (array $draft): bool => (int)($draft['account_id'] ?? 0) === $accountId));
		if (isset($arguments['owned_draft_id'])) {
			$id = $this->draftId($arguments['owned_draft_id']);
			foreach ($visible as $draft) {
				if (($draft['owned_draft_id'] ?? null) === $id) {
					return $this->draftResult($draft, $accountId, $account);
				}
			}
			$attempt = $this->store->findAttempt($uid, $actor, $accountId, $id);
			if ($attempt === null) {
				throw new ToolFailure('Owned Mail draft not found.');
			}
			$this->actors->assertCurrent($uid, $actor);
			$fence();
			return $this->draftResult($attempt, $accountId, $account);
		}
		$visible = $this->activeDrafts($visible);
		$limit = min(self::MAX_DRAFTS, max(1, (int)($arguments['limit'] ?? 20)));
		$offset = max(0, (int)($arguments['offset'] ?? 0));
		$page = array_slice($visible, $offset, $limit);
		$hasMore = count($visible) > $offset + count($page);
		return ['drafts' => array_map($this->publicDraft(...), $page), 'count' => count($page), 'limit' => $limit,
			'offset' => $offset, 'hasMore' => $hasMore, 'next_offset' => $hasMore ? $offset + count($page) : null];
	}

	/** @return array<string,mixed> */
	public function previewWrite(array $arguments, string $uid): array {
		$prepared = $this->prepareWrite($arguments, $uid);
		return ['action' => $prepared['existing'] === null ? 'create' : 'update',
			'account' => ['id' => $prepared['account_id'], 'email' => (string)$prepared['account']->getEmail()],
			'owned_draft_id' => $prepared['existing']['owned_draft_id'] ?? null,
			'before' => $prepared['existing'] === null ? null : $this->draftIdentity($prepared['existing']),
			'after' => $prepared['after'], PlanState::ARGUMENT => $this->states->of(self::WRITE_TOOL, $uid, $prepared['approval']),
			'message' => 'This stores a private MCP-owned plain-text draft. It does not create a native Mail draft or send email.'];
	}

	/** @return array<string,mixed> */
	public function write(array $arguments, string $uid): array {
		$this->assertOfficial();
		PlanState::require($arguments);
		$prepared = $this->prepareWrite($arguments, $uid);
		if (!PlanState::matches($this->states->of(self::WRITE_TOOL, $uid, $prepared['approval']), $arguments)) {
			throw new PlanChanged($this->changedPlan($this->previewWrite($arguments, $uid)));
		}
		$this->actors->assertCurrent($uid, $prepared['actor']);
		$prepared['fence']();
		$this->assertOfficial();
		$draft = $prepared['after'];
		$draft['owned_draft_id'] ??= bin2hex(random_bytes(16));
		$draft['updated_at'] = $this->clock->getTime();
		$drafts = $prepared['snapshot']['drafts'];
		if ($prepared['existing'] === null) $drafts[] = $draft;
		else foreach ($drafts as $index => $row) if (($row['owned_draft_id'] ?? null) === $draft['owned_draft_id']) { $drafts[$index] = $draft; break; }
		$active = $this->activeDrafts($drafts);
		if (count($active) > self::MAX_DRAFTS || strlen(json_encode($active, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_PLAINTEXT_BYTES) throw new ToolFailure('Owned Mail draft storage limit reached.');
		if (!$this->store->compareAndSwap($uid, $prepared['actor'], $prepared['snapshot']['version'], $drafts, $draft['updated_at'])) {
			throw new PlanChanged($this->changedPlan($this->previewWrite($arguments, $uid)));
		}
		$this->actors->assertCurrent($uid, $prepared['actor']);
		$prepared['fence']();
		$this->assertOfficial();
		return ['draft' => $draft, 'message' => 'MCP-owned local draft saved. It is not available to native Mail delivery.'];
	}

	/** @return array<string,mixed> */
	public function previewDelete(array $arguments, string $uid): array {
		$prepared = $this->prepareDelete($arguments, $uid);
		return ['action' => 'delete', 'draft' => $this->draftIdentity($prepared['draft']),
			PlanState::ARGUMENT => $this->states->of(self::DELETE_TOOL, $uid, $prepared['approval']),
			'message' => 'This permanently removes one MCP-owned local draft. No native Mail or remote mailbox is changed.'];
	}

	/** @return array<string,mixed> */
	public function delete(array $arguments, string $uid): array {
		$this->assertOfficial();
		PlanState::require($arguments);
		$prepared = $this->prepareDelete($arguments, $uid);
		if (!PlanState::matches($this->states->of(self::DELETE_TOOL, $uid, $prepared['approval']), $arguments)) {
			throw new PlanChanged($this->changedPlan($this->previewDelete($arguments, $uid)));
		}
		$this->actors->assertCurrent($uid, $prepared['actor']);
		$prepared['fence']();
		$this->assertOfficial();
		if (!$this->store->compareAndSwap($uid, $prepared['actor'], $prepared['snapshot']['version'], $prepared['drafts'], $this->clock->getTime())) {
			throw new PlanChanged($this->changedPlan($this->previewDelete($arguments, $uid)));
		}
		$this->actors->assertCurrent($uid, $prepared['actor']);
		$prepared['fence']();
		$this->assertOfficial();
		return ['deleted_owned_draft_id' => $prepared['draft']['owned_draft_id'], 'message' => 'MCP-owned local draft deleted.'];
	}

	/** @return array<string,mixed> */
	public function previewSend(array $arguments, string $uid): array {
		$prepared = $this->prepareSend($arguments, $uid);
		$draft = $prepared['draft'];
		if (isset($draft['send_state'])) {
			return $this->sendReceipt($draft, $draft['send_state']);
		}
		return [
			'account' => ['id' => $draft['account_id'], 'name' => $prepared['identity']['name'], 'email' => $prepared['identity']['email']],
			'draft' => $this->publicDraft($draft),
			PlanState::ARGUMENT => $prepared['plan'],
			'message' => 'Approve one immediate SMTP attempt for this exact draft. An uncertain attempt cannot be retried.',
		];
	}

	/** @return array<string,mixed> */
	public function send(array $arguments, string $uid): array {
		PlanState::require($arguments);
		$prepared = $this->prepareSend($arguments, $uid);
		$draft = $prepared['draft'];
		if (isset($draft['send_state'])) {
			return $this->sendReceipt($draft, $draft['send_state']);
		}
		if (!PlanState::matches($prepared['plan'], $arguments)) {
			throw new PlanChanged($this->previewSend($arguments, $uid));
		}

		$sender = $this->requireSender();
		$authorize = function () use ($uid, $draft, $prepared): void {
			$sender = $this->requireSender();
			$this->actors->assertCurrent($uid, $prepared['actor']);
			$prepared['fence']();
			$account = $this->accounts->requireAccount($uid, $draft['account_id']);
			if ($sender->accountIdentity($uid, $account) !== $prepared['identity']) {
				throw new ToolFailure('The sending account changed.');
			}
		};
		$authorize();
		$claimed = $prepared['snapshot']['drafts'];
		$claimed[$prepared['index']]['send_state'] = 'uncertain';
		// The receipt and the released draft slot commit together before SMTP.
		if (!$this->store->compareAndSwap($uid, $prepared['actor'], $prepared['snapshot']['version'], $claimed, $this->clock->getTime())) {
			$fresh = $this->previewSend($arguments, $uid);
			if (isset($fresh['send_state'])) {
				return $fresh;
			}
			throw new PlanChanged($fresh);
		}

		try {
			$outcome = $sender->send($uid, $prepared['account'], $draft, $authorize);
			if ($outcome !== 'uncertain' && !$this->store->completeAttempt(
				$uid, $prepared['actor'], $draft['account_id'], $draft['owned_draft_id'], $draft['revision'], $outcome
			)) {
				$outcome = 'uncertain';
			}
		} catch (\Throwable) {
			$outcome = 'uncertain';
		}
		$this->actors->assertCurrent($uid, $prepared['actor']);
		$prepared['fence']();
		return $this->sendReceipt($draft, $outcome);
	}

	private function prepareSend(array $arguments, string $uid): array {
		$sender = $this->requireSender();
		$prepared = $this->existingDraft($arguments, $uid, self::SEND_TOOL);
		$draft = $prepared['draft'];
		if (isset($draft['send_state'])) {
			return $prepared;
		}
		if ($draft['to'] === [] && $draft['cc'] === [] && $draft['bcc'] === []) {
			throw new ToolFailure('At least one recipient is required to send.');
		}
		foreach ([...$draft['to'], ...$draft['cc'], ...$draft['bcc']] as $recipient) {
			if (filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) === false) {
				throw new ToolFailure('Sending requires valid individual email addresses.');
			}
		}
		$identity = $sender->accountIdentity($uid, $prepared['account']);
		$plan = $this->states->of(self::SEND_TOOL, $uid, [
			'connection_id' => $this->connection->current(),
			'actor_incarnation' => $prepared['actor'],
			'sender' => $identity,
			'draft' => $draft,
		]);
		return $prepared + compact('identity', 'plan');
	}

	private function requireSender(): MailOwnedDraftSender {
		$this->assertOfficial();
		if ($this->connection->current() <= 0 || $this->sender === null || !$this->sender->available()) {
			throw new ToolFailure('Official Mail sending requires an eligible OAuth connection and a qualified, enabled runtime.');
		}
		return $this->sender;
	}

	private function sendReceipt(array $draft, string $outcome): array {
		return $this->draftIdentity($draft) + [
			'send_state' => $outcome,
			'message' => match ($outcome) {
				'accepted' => 'SMTP accepted the message and Mail saved a copy in Sent. Acceptance does not prove delivery.',
				'accepted_copy_unconfirmed' => 'SMTP accepted the message. Check Sent before taking further action.',
				default => 'The send outcome is uncertain. Verify it in Mail before taking further action. This draft cannot be sent again.',
			},
		];
	}

	private function prepareWrite(array $arguments, string $uid): array {
		$this->assertOfficial();
		$accountId = $this->accountId($arguments);
		$account = $this->accounts->requireAccount($uid, $accountId);
		$actor = $this->actors->capture($uid);
		$this->actors->assertCurrent($uid, $actor);
		$fence = ($this->resourceFence)($uid, self::WRITE_TOOL, $accountId);
		$fence();
		$snapshot = $this->store->load($uid, $actor);
		$this->actors->assertCurrent($uid, $actor);
		$fence();
		$existing = null;
		$id = isset($arguments['owned_draft_id']) ? $this->draftId($arguments['owned_draft_id']) : null;
		if ($id !== null) {
			foreach ($snapshot['drafts'] as $draft) if (($draft['owned_draft_id'] ?? null) === $id && (int)($draft['account_id'] ?? 0) === $accountId) { $existing = $draft; break; }
			if ($existing === null) throw new ToolFailure('Owned Mail draft not found.');
			if (isset($existing['send_state'])) throw new ToolFailure('A draft with a send attempt cannot be edited.');
			if (!is_int($arguments['revision'] ?? null) || $arguments['revision'] !== (int)$existing['revision']) throw new ToolFailure('Owned Mail draft changed; read it and prepare a new plan.');
		} elseif (array_key_exists('revision', $arguments)) {
			throw new \InvalidArgumentException('revision requires owned_draft_id.');
		}
		if ($existing === null && count($this->activeDrafts($snapshot['drafts'])) >= self::MAX_DRAFTS) throw new ToolFailure('Owned Mail draft limit reached.');
		$fields = $this->fields($arguments);
		$after = ['owned_draft_id' => $id, 'account_id' => $accountId,
			'revision' => $existing === null ? 1 : (int)$existing['revision'] + 1, ...$fields];
		$approval = ['account_id' => $accountId, 'connection_id' => $this->connection->current(), 'actor_incarnation' => $actor,
			'owner_version' => $snapshot['version'], 'owned_draft_id' => $id, 'revision' => $existing['revision'] ?? null,
			'current' => $existing, 'after' => $after];
		return compact('accountId', 'account', 'actor', 'fence', 'snapshot', 'existing', 'after', 'approval') + ['account_id' => $accountId];
	}

	private function prepareDelete(array $arguments, string $uid): array {
		$this->assertOfficial();
		$prepared = $this->existingDraft($arguments, $uid, self::DELETE_TOOL);
		['actor' => $actor, 'fence' => $fence, 'snapshot' => $snapshot, 'draft' => $draft, 'index' => $index] = $prepared;
		if (isset($draft['send_state'])) {
			throw new ToolFailure('An attempted draft is retained in the send history.');
		}
		$drafts = $snapshot['drafts'];
		array_splice($drafts, $index, 1);
		$approval = ['account_id' => $draft['account_id'], 'connection_id' => $this->connection->current(), 'actor_incarnation' => $actor,
			'owner_version' => $snapshot['version'], 'owned_draft_id' => $draft['owned_draft_id'], 'revision' => $draft['revision'], 'current' => $draft];
		return compact('actor', 'fence', 'snapshot', 'draft', 'drafts', 'approval');
	}

	private function existingDraft(array $arguments, string $uid, string $tool): array {
		$accountId = $this->accountId($arguments);
		$account = $this->accounts->requireAccount($uid, $accountId);
		$actor = $this->actors->capture($uid);
		$this->actors->assertCurrent($uid, $actor);
		$fence = ($this->resourceFence)($uid, $tool, $accountId);
		$fence();
		$snapshot = $this->store->load($uid, $actor);
		$this->actors->assertCurrent($uid, $actor);
		$fence();
		$id = $this->draftId($arguments['owned_draft_id'] ?? null);
		foreach ($snapshot['drafts'] as $index => $draft) {
			if (($draft['owned_draft_id'] ?? null) !== $id || (int)$draft['account_id'] !== $accountId) {
				continue;
			}
			if (!is_int($arguments['revision'] ?? null) || $arguments['revision'] !== (int)$draft['revision']) {
				throw new ToolFailure('Owned Mail draft changed; read it and prepare a new plan.');
			}
			return compact('account', 'actor', 'fence', 'snapshot', 'draft', 'index');
		}
		if ($tool === self::SEND_TOOL) {
			$draft = $this->store->findAttempt($uid, $actor, $accountId, $id);
			if ($draft !== null && ($arguments['revision'] ?? null) === $draft['revision']) {
				$this->actors->assertCurrent($uid, $actor);
				$fence();
				return compact('draft');
			}
		}
		throw new ToolFailure('Owned Mail draft not found.');
	}

	private function draftResult(array $draft, int $accountId, object $account): array {
		return ['draft' => $this->publicDraft($draft), 'account' => ['id' => $accountId, 'email' => (string)$account->getEmail()]];
	}

	private function activeDrafts(array $drafts): array {
		return array_values(array_filter($drafts, static fn (array $draft): bool => !isset($draft['send_state'])));
	}

	private function fields(array $arguments): array {
		$subject = $arguments['subject'] ?? null;
		$body = $arguments['body_plain'] ?? null;
		if (!is_string($subject) || mb_strlen($subject, 'UTF-8') > self::MAX_SUBJECT_CHARS || preg_match('/[\r\n\x00]/', $subject)) throw new \InvalidArgumentException('Invalid draft subject.');
		if (!is_string($body) || mb_strlen($body, 'UTF-8') > self::MAX_BODY_CHARS) throw new \InvalidArgumentException('Invalid draft body.');
		return ['subject' => $subject, 'body_plain' => $body,
			'to' => $this->recipients($arguments['to'] ?? []), 'cc' => $this->recipients($arguments['cc'] ?? []), 'bcc' => $this->recipients($arguments['bcc'] ?? [])];
	}

	private function recipients(mixed $items): array {
		if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_RECIPIENTS) throw new \InvalidArgumentException('Invalid draft recipients.');
		$out = [];
		foreach ($items as $item) {
			if (!is_array($item) || !is_string($item['email'] ?? null) || trim($item['email']) === '' || strlen($item['email']) > 500 || preg_match('/[\r\n\x00]/', $item['email'])) throw new \InvalidArgumentException('Invalid draft recipient.');
			$label = $item['label'] ?? '';
			if (!is_string($label) || strlen($label) > 500 || preg_match('/[\r\n\x00]/', $label)) throw new \InvalidArgumentException('Invalid draft recipient.');
			$out[] = ['email' => trim($item['email']), 'label' => $label];
		}
		return $out;
	}

	private function accountId(array $arguments): int {
		$id = $arguments['account_id'] ?? null;
		if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('A positive account_id is required.');
		return $id;
	}

	private function draftId(mixed $value): string {
		if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) throw new \InvalidArgumentException('Invalid owned_draft_id.');
		return $value;
	}

	/** Mutation grants authorize the requested effect without revealing existing draft content. */
	private function draftIdentity(array $draft): array {
		return ['owned_draft_id' => $draft['owned_draft_id'], 'account_id' => (int)$draft['account_id'], 'revision' => (int)$draft['revision']];
	}

	private function publicDraft(array $draft): array { return ['owned_draft_id' => $draft['owned_draft_id'], 'account_id' => (int)$draft['account_id'],
		'revision' => (int)$draft['revision'], 'subject' => (string)$draft['subject'], 'body_plain' => (string)$draft['body_plain'],
		'to' => $draft['to'], 'cc' => $draft['cc'], 'bcc' => $draft['bcc'], 'updated_at' => (int)$draft['updated_at']]
			+ (isset($draft['send_state']) ? ['send_state' => $draft['send_state']] : []); }

	private function changedPlan(array $plan): array { $plan['warnings'] = [['message' => 'The owned draft or account state changed. Review this new plan before confirming.']]; return $plan; }
	private function assertOfficial(): void { if ($this->officialMode !== null && !($this->officialMode)()) throw new ToolFailure('MCP-owned Mail drafts are unavailable.'); }
}
