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
		if (!array_is_list($drafts) || count($drafts) > self::MAX_DRAFTS) throw new \InvalidArgumentException('Local draft quota exceeded.');
		$json=json_encode($drafts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if(strlen($json)>self::MAX_PLAINTEXT_BYTES)throw new \InvalidArgumentException('Local draft storage quota exceeded.');
		$payload = $this->crypto->encrypt($json);
		if (strlen($payload) > self::MAX_CIPHER_BYTES) throw new \InvalidArgumentException('Local draft storage quota exceeded.');
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
