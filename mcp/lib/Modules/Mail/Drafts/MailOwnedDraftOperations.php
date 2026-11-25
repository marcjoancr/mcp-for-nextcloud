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
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;

/** App-owned plain-text drafts. This class never calls native Mail draft, IMAP, or SMTP services. */
final class MailOwnedDraftOperations {
	public const READ_TOOL = 'mail_read_owned_drafts';
	public const WRITE_TOOL = 'mail_write_owned_draft';
	public const DELETE_TOOL = 'mail_delete_owned_draft';
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
			foreach ($visible as $draft) if (($draft['owned_draft_id'] ?? null) === $id) return ['draft' => $this->publicDraft($draft), 'account' => ['id' => $accountId, 'email' => (string)$account->getEmail()]];
			throw new ToolFailure('Owned Mail draft not found.');
		}
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
		if (count($drafts) > self::MAX_DRAFTS || strlen(json_encode($drafts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_PLAINTEXT_BYTES) throw new ToolFailure('Owned Mail draft storage limit reached.');
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
			if (!is_int($arguments['revision'] ?? null) || $arguments['revision'] !== (int)$existing['revision']) throw new ToolFailure('Owned Mail draft changed; read it and prepare a new plan.');
		} elseif (array_key_exists('revision', $arguments)) {
			throw new \InvalidArgumentException('revision requires owned_draft_id.');
		}
		if ($existing === null && count($snapshot['drafts']) >= self::MAX_DRAFTS) throw new ToolFailure('Owned Mail draft limit reached.');
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
		$accountId = $this->accountId($arguments);
		$this->accounts->requireAccount($uid, $accountId);
		$actor = $this->actors->capture($uid);
		$this->actors->assertCurrent($uid, $actor);
		$fence = ($this->resourceFence)($uid, self::DELETE_TOOL, $accountId);
		$fence();
		$snapshot = $this->store->load($uid, $actor);
		$this->actors->assertCurrent($uid, $actor);
		$fence();
		$id = $this->draftId($arguments['owned_draft_id'] ?? null);
		$draft = null;
		foreach ($snapshot['drafts'] as $candidate) if (($candidate['owned_draft_id'] ?? null) === $id && (int)($candidate['account_id'] ?? 0) === $accountId) { $draft = $candidate; break; }
		if ($draft === null) throw new ToolFailure('Owned Mail draft not found.');
		if (!is_int($arguments['revision'] ?? null) || $arguments['revision'] !== (int)$draft['revision']) throw new ToolFailure('Owned Mail draft changed; read it and prepare a new plan.');
		$drafts = array_values(array_filter($snapshot['drafts'], static fn (array $row): bool => ($row['owned_draft_id'] ?? null) !== $id));
		$approval = ['account_id' => $accountId, 'connection_id' => $this->connection->current(), 'actor_incarnation' => $actor,
			'owner_version' => $snapshot['version'], 'owned_draft_id' => $id, 'revision' => $draft['revision'], 'current' => $draft];
		return compact('actor', 'fence', 'snapshot', 'draft', 'drafts', 'approval');
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
		'to' => $draft['to'], 'cc' => $draft['cc'], 'bcc' => $draft['bcc'], 'updated_at' => (int)$draft['updated_at']]; }

	private function changedPlan(array $plan): array { $plan['warnings'] = [['message' => 'The owned draft or account state changed. Review this new plan before confirming.']]; return $plan; }
	private function assertOfficial(): void { if ($this->officialMode !== null && !($this->officialMode)()) throw new ToolFailure('MCP-owned Mail drafts are unavailable.'); }
}

/** Enforced before encryption and again by the persistence boundary after encryption overhead. */
