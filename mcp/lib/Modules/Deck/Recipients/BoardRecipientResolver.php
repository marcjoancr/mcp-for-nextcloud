<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Recipients;
use Throwable;
use Closure;

use OCA\Mcp\Modules\Deck\Files\CardFiles;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages;

use OCA\Deck\Db\Acl;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;

/**
 * Internal complete board recipients after grants and fresh active source READ/EDIT; never creates a share.
 */
final class BoardRecipientResolver {
	/**
	 * Maximum complete ACL rows supported in a single resolution.
	 */
	public const MAX_ACLS = 128;

	/**
	 * Monotonic operation-local clock, never persisted or included in either hash.
	 *
	 * @var Closure():int
	 */
	private Closure $clock;

	/**
	 * Compose authorization, native identity reads and the existing plan fingerprint mechanism.
	 *
	 * @param \Closure(string,int,bool):array $source Existing fresh source authorization boundary.
	 * @param GrantPolicy $policy Caller-bound grants, re-read in every resolution operation.
	 * @param RecipientDirectory $directory Native ACL and user/group/circle reads only.
	 * @param PlanState $plans Existing instance-secret HMAC for canonical scalar snapshots.
	 * @param LoggerInterface $logger Receives only the class of an unexpected exception.
	 * @param Closure|null $clock Optional monotonic nanosecond clock for deterministic limit tests.
	 * @return void
	 */
	public function __construct(private \Closure $source, private GrantPolicy $policy,
		private RecipientDirectory $directory, private PlanState $plans, private LoggerInterface $logger, private Closure $clearNativeSession, ?Closure $clock = null) {
		$this->clock = $clock ?? static fn (): int => hrtime(true);
	}

	/**
	 * Resolve current recipients without touching Files; DK-4b still authorizes its node and strict policies.
	 *
	 * The result is internal and ephemeral: no executable public plan is exposed here. Recompute it on
	 * confirm. Native Deck getCircle starts a Circles super session; the native directory clears it in finally.
	 * DK-4b must perform Circles-dependent authorization before resolution, or avoid using Circles
	 * permissions afterwards. The directory does not allow privileged expansion state to escape to later authorization.
	 * Native I/O is bounded cooperatively, not cancelled; hashes do not close TOCTOU windows.
	 *
	 * @param LinkRecipientContext $context Caller and exact expected target, including file identity/version.
	 * @return RecipientSnapshot Complete authorized portrait; never an N derived from partial expansion.
	 * @throws DeckRefusalException On any grant, source, identity, expansion or budget failure.
	 */
	public function resolve(LinkRecipientContext $context): RecipientSnapshot {
		$authorized = false;
		$inactive = false;
		try {
			return $this->policy->within($context->actorUid, function () use ($context, &$authorized, &$inactive): RecipientSnapshot {
				$this->requireGrants($context->actorUid);
				$expansion = new RecipientExpansion($this->clock);
				try {
					$source = $this->context($context->actorUid, $context->cardId, true);
				} catch (DeckRefusalException $first) {
					if (!$first->marksInactiveFileSource($context->actorUid, $context->cardId, $context->boardId)) {
						throw CardFiles::unavailable();
					}
					$this->requireGrants($context->actorUid);
					try {
						$this->context($context->actorUid, $context->cardId, true);
					} catch (DeckRefusalException $second) {
						if ($second->marksInactiveFileSource($context->actorUid, $context->cardId, $context->boardId)) {
							$this->requireGrants($context->actorUid);
							$inactive = true;
							throw $second;
						}
					}
					throw CardFiles::unavailable();
				}
				if ($source['boardId'] !== $context->boardId) {
					throw CardFiles::unavailable();
				}
				$authorized = true;
				$expansion->checkpoint();
				$acls = $this->directory->acls($context->boardId);
				$expansion->checkpoint();
				if (count($acls) > self::MAX_ACLS) {
					throw RecipientResolutionException::limit();
				}
				$hasGroup = false;
				$hasCircle = false;
				foreach ($acls as $acl) {
					if (!in_array($acl['type'], [Acl::PERMISSION_TYPE_USER, Acl::PERMISSION_TYPE_GROUP, Acl::PERMISSION_TYPE_CIRCLE], true)
						|| $acl['participant'] === '' || preg_match('//u', $acl['participant']) !== 1) {
						throw RecipientResolutionException::unsupported();
					}
					$hasGroup = $hasGroup || $acl['type'] === Acl::PERMISSION_TYPE_GROUP;
					$hasCircle = $hasCircle || $acl['type'] === Acl::PERMISSION_TYPE_CIRCLE;
				}
				$expansion->add($this->directory->user($source['board']['owner']));
				foreach ($acls as $acl) {
					$expansion->checkpoint();
					$users = match ($acl['type']) {
						Acl::PERMISSION_TYPE_USER => [$this->directory->user($acl['participant'])],
						Acl::PERMISSION_TYPE_GROUP => $this->directory->group($acl['participant'], $expansion),
						Acl::PERMISSION_TYPE_CIRCLE => $this->directory->circle($acl['participant'], $expansion),
					};
					foreach ($users as $user) {
						$expansion->add($user);
					}
				}
				$snapshot = new RecipientSnapshot($context, $expansion->members(), $acls, $hasGroup, $hasCircle, $this->plans);
				$expansion->checkpoint();
				return $snapshot;
			});
		} catch (Throwable $failure) {
			if ($inactive && $failure instanceof DeckRefusalException
				&& $failure->marksInactiveFileSource($context->actorUid, $context->cardId, $context->boardId)) {
				throw new DeckRefusalException(CapabilityMessages::fileSourceInactive());
			}
			if (!$failure instanceof DeckRefusalException && !$failure instanceof RecipientResolutionException
				&& !is_a($failure, 'OCA\\Deck\\NoPermissionException')
				&& !is_a($failure, 'OCP\\AppFramework\\Db\\DoesNotExistException')) {
				$this->logger->debug('Deck recipient resolution failed.', ['exceptionClass' => $failure::class]);
			}
			if ($authorized) {
				throw new DeckRefusalException($failure instanceof RecipientResolutionException
					? $failure->callerMessage() : RecipientMessages::incomplete());
			}
			throw CardFiles::unavailable();
		}
	}

	/**
	 * Re-evaluate all three decisions from the current operation context without exposing source metadata.
	 *
	 * GrantPolicy deliberately snapshots within() (contract B.5). Repetition is not an external refresh;
	 * concurrent configuration changes remain a TOCTOU limit. A new call/confirm opens its own snapshot.
	 *
	 * @param string $uid Authenticated actor bound by the current policy scope.
	 * @return void
	 * @throws DeckRefusalException When any required grant is absent.
	 */
	private function requireGrants(string $uid): void {
		foreach ([['deck', 'edit'], ['files', 'read'], ['files', 'share']] as [$module, $operation]) {
			if (!$this->policy->granted($uid, $module, $operation)) {
				throw CardFiles::unavailable();
			}
		}
	}
    /** Native source reads must release privilege before any subsequent authorization. */
    private function context(string $uid, int $cardId, bool $edit): array {
        try { return ($this->source)($uid, $cardId, $edit); }
        finally { ($this->clearNativeSession)(); }
    }

}
