<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Deck\Db\Acl;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Recipients\RecipientDirectory;
use OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion;
use OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException;

/** Fingerprints the complete current Deck board audience without returning identities to the caller. */
final class CommentAudience {
	/** @param \Closure():void $clearNativeSession Clears any privileged Circles session in a finally block. */
	public function __construct(private RecipientDirectory $directory, private PlanState $plans, private \Closure $clearNativeSession) {}

	/** @return array{hash:string,isShared:bool} */
	public function fingerprint(string $userId, int $boardId, string $ownerUid): array {
		$expansion = new RecipientExpansion(static fn(): int => hrtime(true));
		try {
			$acls = $this->directory->acls($boardId);
			if (count($acls) > \OCA\Mcp\Modules\Deck\Recipients\BoardRecipientResolver::MAX_ACLS) {
				throw RecipientResolutionException::unsupported();
			}
			$expansion->add($this->directory->user($ownerUid));
			foreach ($acls as $acl) {
				if (!in_array($acl['type'], [Acl::PERMISSION_TYPE_USER, Acl::PERMISSION_TYPE_GROUP, Acl::PERMISSION_TYPE_CIRCLE], true)
					|| $acl['participant'] === '' || preg_match('//u', $acl['participant']) !== 1) {
					throw RecipientResolutionException::unsupported();
				}
				$users = match ($acl['type']) {
					Acl::PERMISSION_TYPE_USER => [$this->directory->user($acl['participant'])],
					Acl::PERMISSION_TYPE_GROUP => $this->directory->group($acl['participant'], $expansion),
					Acl::PERMISSION_TYPE_CIRCLE => $this->directory->circle($acl['participant'], $expansion),
				};
				foreach ($users as $user) { $expansion->add($user); }
			}
			$members = array_column($expansion->members(), 'uid');
			$canonicalAcls = array_map(static fn(array $acl): array => [
				'type' => $acl['type'], 'participant' => $acl['participant'], 'edit' => $acl['edit'],
				'share' => $acl['share'], 'manage' => $acl['manage'],
			], $acls);
			usort($canonicalAcls, static fn(array $a, array $b): int => ($a['type'] <=> $b['type']) ?: strcmp($a['participant'], $b['participant']));
			return [
				'hash' => $this->plans->of('deck_add_comment', $userId, ['boardId' => $boardId, 'members' => $members, 'acls' => $canonicalAcls]),
				'isShared' => $canonicalAcls !== [],
			];
		} finally {
			($this->clearNativeSession)();
		}
	}
}
