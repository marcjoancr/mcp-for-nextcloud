<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Db;

use OCA\Mcp\Modules\Mail\Contracts\MailOwnedDraftStore;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/** One encrypted owner snapshot makes draft-count/byte quotas and revision updates atomic. */
final class MailOwnedDraftsMapper implements MailOwnedDraftStore {
	private const ATTEMPTS = 'mcp_mail_owned_attempts';
	private const ACCEPTED = ['accepted', 'accepted_copy_unconfirmed'];
	public const MAX_DRAFTS = 50;
	public const MAX_PLAINTEXT_BYTES = 900_000;
	public const MAX_CIPHER_BYTES = 1_048_576;

	public function __construct(private IDBConnection $db, private ICrypto $crypto) {}

	public function load(string $uid, string $actor): array {
		$q = $this->db->getQueryBuilder();
		$r = $q->select('actor_incarnation', 'version', 'payload')->from('mcp_mail_owned_drafts')
			->where($q->expr()->eq('actor_uid', $q->createNamedParameter($uid)))->executeQuery();
		try { $row = $r->fetch(); } finally { $r->closeCursor(); }
		if (!$row) return ['version' => 0, 'drafts' => []];
		$version = (int)$row['version'];
		// A recreated UID must never decrypt or see the retired actor's drafts.
		if (!is_string($row['actor_incarnation']) || !hash_equals($actor, $row['actor_incarnation'])) return ['version' => $version, 'drafts' => []];
		$ciphertext=(string)$row['payload'];
		if (strlen($ciphertext)>self::MAX_CIPHER_BYTES) throw new \RuntimeException('Local draft store exceeds its storage limit.');
		$drafts = json_decode($this->crypto->decrypt($ciphertext), true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($drafts) || !array_is_list($drafts) || count($drafts) > self::MAX_DRAFTS) throw new \RuntimeException('Local draft store is invalid.');
		return ['version' => $version, 'drafts' => $drafts];
	}

	public function compareAndSwap(string $uid, string $actor, int $expectedVersion, array $drafts, int $updatedAt): bool {
		if (!array_is_list($drafts)) {
			throw new \InvalidArgumentException('Invalid Mail draft storage.');
		}
		$active = [];
		$attempts = [];
		foreach ($drafts as $draft) {
			if (isset($draft['send_state'])) {
				$attempts[] = $draft;
			} else {
				$active[] = $draft;
			}
		}
		if (count($active) > self::MAX_DRAFTS) {
			throw new \InvalidArgumentException('Local draft quota exceeded.');
		}
		$payload = $this->encrypt($active);
		if ($attempts === []) {
			return $this->saveSnapshot($uid, $actor, $expectedVersion, $payload, $updatedAt);
		}

		if ($this->db->inTransaction()) {
			throw new \LogicException('Mail attempts require an independent database transaction.');
		}
		$this->db->beginTransaction();
		try {
			if (!$this->saveSnapshot($uid, $actor, $expectedVersion, $payload, $updatedAt)) {
				$this->db->rollBack();
				return false;
			}
			foreach ($attempts as $draft) {
				$this->insertAttempt($uid, $actor, $draft, $updatedAt);
			}
			// The caller may contact SMTP only after this commit was acknowledged.
			$this->db->commit();
			return true;
		} catch (\Throwable $failure) {
			if ($this->db->inTransaction()) {
				try {
					$this->db->rollBack();
				} catch (\Throwable) {
					$this->db->close();
				}
			}
			throw $failure;
		}
	}

	public function findAttempt(string $uid, string $actor, int $account, string $id): ?array {
		$query = $this->db->getQueryBuilder();
		$query->select('draft_revision', 'send_state', 'payload')->from(self::ATTEMPTS);
		$this->attemptScope($query, $uid, $actor, $account, $id);
		$result = $query->executeQuery();
		try {
			$row = $result->fetch();
		} finally {
			$result->closeCursor();
		}
		if (!$row) {
			return null;
		}
		if (strlen((string)$row['payload']) > self::MAX_CIPHER_BYTES) {
			throw new \RuntimeException('Invalid Mail attempt storage.');
		}
		$draft = json_decode($this->crypto->decrypt((string)$row['payload']), true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($draft) || ($draft['owned_draft_id'] ?? null) !== $id || ($draft['account_id'] ?? null) !== $account
			|| ($draft['revision'] ?? null) !== (int)$row['draft_revision'] || !$this->isAttemptState($row['send_state'])) {
			throw new \RuntimeException('Invalid Mail attempt storage.');
		}
		$draft['send_state'] = $row['send_state'];
		return $draft;
	}

	public function completeAttempt(string $uid, string $actor, int $account, string $id, int $revision, string $outcome): bool {
		if (!in_array($outcome, self::ACCEPTED, true)) {
			throw new \InvalidArgumentException('Invalid accepted Mail attempt state.');
		}
		$query = $this->db->getQueryBuilder();
		$query->update(self::ATTEMPTS)->set('send_state', $query->createNamedParameter($outcome));
		$this->attemptScope($query, $uid, $actor, $account, $id);
		return $query->andWhere($query->expr()->eq('draft_revision', $query->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('send_state', $query->createNamedParameter('uncertain')))
			->executeStatement() === 1;
	}

	private function attemptScope(IQueryBuilder $query, string $uid, string $actor, int $account, string $id): void {
		$query->where($query->expr()->eq('draft_id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('actor_uid', $query->createNamedParameter($uid)))
			->andWhere($query->expr()->eq('actor_incarnation', $query->createNamedParameter($actor)))
			->andWhere($query->expr()->eq('account_id', $query->createNamedParameter($account, IQueryBuilder::PARAM_INT)));
	}

	private function insertAttempt(string $uid, string $actor, array $draft, int $updatedAt): void {
		$id = $draft['owned_draft_id'] ?? null;
		$account = $draft['account_id'] ?? null;
		$revision = $draft['revision'] ?? null;
		$state = $draft['send_state'];
		if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_int($account) || $account < 1
			|| !is_int($revision) || $revision < 1 || !$this->isAttemptState($state)) {
			throw new \InvalidArgumentException('Invalid Mail attempt.');
		}
		unset($draft['send_state']);
		$query = $this->db->getQueryBuilder();
		$inserted = $query->insert(self::ATTEMPTS)
			->setValue('draft_id', $query->createNamedParameter($id))
			->setValue('actor_uid', $query->createNamedParameter($uid))
			->setValue('actor_incarnation', $query->createNamedParameter($actor))
			->setValue('account_id', $query->createNamedParameter($account, IQueryBuilder::PARAM_INT))
			->setValue('draft_revision', $query->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
			->setValue('send_state', $query->createNamedParameter($state))
			->setValue('recorded_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
			->setValue('payload', $query->createNamedParameter($this->encrypt($draft)))
			->executeStatement();
		if ($inserted !== 1) {
			throw new \RuntimeException('The Mail attempt was not stored.');
		}
	}

	private function isAttemptState(mixed $state): bool {
		return $state === 'uncertain' || in_array($state, self::ACCEPTED, true);
	}

	private function encrypt(array $data): string {
		$json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (strlen($json) > self::MAX_PLAINTEXT_BYTES) {
			throw new \InvalidArgumentException('Local draft storage quota exceeded.');
		}
		$payload = $this->crypto->encrypt($json);
		if (strlen($payload) > self::MAX_CIPHER_BYTES) {
			throw new \InvalidArgumentException('Local draft storage quota exceeded.');
		}
		return $payload;
	}

	private function saveSnapshot(string $uid, string $actor, int $expectedVersion, string $payload, int $updatedAt): bool {
		$q = $this->db->getQueryBuilder();
		$changed = $q->update('mcp_mail_owned_drafts')
			->set('actor_incarnation', $q->createNamedParameter($actor))
			->set('version', $q->createNamedParameter($expectedVersion + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $q->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
			->set('payload', $q->createNamedParameter($payload))
			->where($q->expr()->eq('actor_uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('version', $q->createNamedParameter($expectedVersion, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($changed === 1) return true;
		if ($expectedVersion !== 0) return false;
		try {
			$q = $this->db->getQueryBuilder();
			return $q->insert('mcp_mail_owned_drafts')
				->setValue('actor_uid', $q->createNamedParameter($uid))
				->setValue('actor_incarnation', $q->createNamedParameter($actor))
				->setValue('version', $q->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->setValue('updated_at', $q->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
				->setValue('payload', $q->createNamedParameter($payload))
				->executeStatement() === 1;
		} catch (Exception $error) {
			if ($error->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) return false;
			throw $error;
		}
	}
}
