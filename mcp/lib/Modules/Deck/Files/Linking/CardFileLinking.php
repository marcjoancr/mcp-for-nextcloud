<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Files\Linking;
use Throwable;
use Closure;

use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Modules\Deck\Recipients\LinkRecipientContext;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Constants;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * One native file link with a complete fresh warning, signed state and conservative outcome attribution.
 */
final class CardFileLinking {
    /**
     * Public tool identity shared with the typed recipient context.
     */
    public const TOOL = LinkRecipientContext::OPERATION;

    /**
     * Lazily assembled operation services, resolved only after the optional app gate admits a call.
     *
     * @var array{prepare:CardFileLinkPreparation,native:NativeCardFileLink,plans:CardLinkPlan,shared:SharedWriteGuard}|null
     */
    private ?array $services = null;

    /**
     * Receive the existing handler boundaries and a lazy focused service composition.
     *
     * @param LoggerInterface $logger Receives exception classes only.
     * @param Closure():array{prepare:CardFileLinkPreparation,native:NativeCardFileLink,plans:CardLinkPlan,shared:SharedWriteGuard} $factory Lazy operation services.
     * @param Closure():void $clearNativeSession Clear loaded native Circles privilege without optional autoload.
     * @return void
     */
    public function __construct(private LoggerInterface $logger, private Closure $factory, private Closure $clearNativeSession) {
    }

    /**
     * Authorize and show the complete warning without creating a share.
     *
     * @param array<string, mixed> $arguments Validated cardId and fileId.
     * @param string $userId Authenticated actor.
     * @return array<string, mixed> Fresh public preview and state HMAC.
     * @throws Throwable On refused or uncertain source, file, policy or audience.
     */
    public function preview(array $arguments, string $userId): array {
        try {
            ($this->clearNativeSession)();
            $services = $this->services();
            return $services['plans']->of($services['prepare']->prepare($userId,
                (int)$arguments['cardId'], (int)$arguments['fileId']), $userId);
        } finally {
            ($this->clearNativeSession)();
        }
    }

    /**
     * Revalidate the complete plan and execute at most one native creation attempt.
     *
     * Native createShare EDIT and the result READ check follow recipient expansion. Deck's
     * recipient directory clears privileged Circles state in finally; membership can still come
     * from Deck's request cache, not a fresh lookup.
     * A final operation boundary also clears privilege that native write or readback code may re-enter.
     *
     * @param array<string, mixed> $arguments Validated targets, confirmation and previous plan_state.
     * @param string $userId Authenticated actor.
     * @return array<string, mixed> Standard MCP result, without stale file metadata on uncertain visibility.
     * @throws \InvalidArgumentException For an absent mandatory confirmation state.
     */
    public function handle(array $arguments, string $userId): array {
        return $this->run(function () use ($arguments, $userId): array {
            $writeAttempted = false;
            try {
                ($this->clearNativeSession)();
                return $this->perform($arguments, $userId, $writeAttempted);
            } finally {
                try {
                    ($this->clearNativeSession)();
                } catch (Throwable $failure) {
                    if ($writeAttempted) {
                        $this->logger->warning('Deck file link session reset failed after a native write attempt.', ['exceptionClass'=>$failure::class]);
                        return $this->uncertain();
                    }
                    throw $failure;
                }
            }
        });
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function perform(array $arguments, string $userId, bool &$writeAttempted): array {
        $services = $this->services();
        $cardId = (int)$arguments['cardId'];
        $fileId = (int)$arguments['fileId'];
        $state = $services['prepare']->prepare($userId, $cardId, $fileId);
        $plan = $services['plans']->of($state, $userId);
        if (($arguments['confirm'] ?? false) !== true) {
            return ['requiresConfirmation' => true] + $plan;
        }
        PlanState::require($arguments);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($plan);
        }
        $shared = \OCA\Mcp\Modules\Deck\Access\SharedBoardConfirmation::confirmShared($arguments, $userId, $state['source']['board']);
        if ($shared !== null) {
            return $shared;
        }
        // Linking does not require UPDATE on the original: READ+SHARE was already checked.
        // Reuse only the shared-resource confirmation payload, from ownership read before Circles.
        if ($state['snapshot']['fileAccess']['scope'] !== NodeAccessInfo::PERSONAL
            && ($arguments['confirm_shared'] ?? false) !== true) {
            return $services['shared']->request($state['snapshot']['fileAccess'], $state['snapshot']['file']['path']);
        }
        $created = null;
        try {
            $writeAttempted = true;
            $created = $services['native']->create($userId, $cardId, $state['node']);
        } catch (Throwable $failure) {
            $this->logger->warning('Deck file link creation response failed; outcome requires observation.', ['exceptionClass'=>$failure::class]);
        }
        try {
            $visible = $services['prepare']->visibleResult($userId, $cardId, $fileId);
            $observed = $services['native']->find($userId, $cardId, $visible['node']);
        } catch (Throwable $failure) {
            $this->logger->warning('Deck file link result cannot be observed.', ['exceptionClass' => $failure::class]);
            return $this->uncertain();
        }
        if ($observed === null) {
            // Absence cannot prove this attempt never persisted: a hook or concurrent unlink may remove it.
            return $this->uncertain();
        }
        // Native Deck rejects duplicates before returning an ID. A row found after an exception
        // without that return value is not proof this attempt created it, even for the same UID.
        if ($created === null || !$this->matches($created, $observed, $userId, $cardId, $fileId)) {
            return $this->uncertain();
        }
        return ['confirmed' => true, 'linked' => true, 'changed' => true,
            'attachmentId' => (int)$observed->getId(), 'file' => $visible['metadata'],
            'permissions' => Constants::PERMISSION_READ, 'verification' => 'returned-id-and-observed-state'];
    }

    /**
     * Require the returned native creation identity and every observed target/permission field to agree.
     *
     * @param IShare $created Share returned by this attempt's native createShare.
     * @param IShare $observed Share re-read by canonical file and card.
     * @param string $uid Authenticated initiator.
     * @param int $cardId Authorized card.
     * @param int $fileId Authorized canonical node.
     * @return bool Whether the exact identity and native read-only contract were observed.
     */
    private function matches(IShare $created, IShare $observed, string $uid, int $cardId, int $fileId): bool {
        foreach ([$created, $observed] as $share) {
            if ((int)$share->getId() <= 0 || $share->getShareType() !== IShare::TYPE_DECK
                || $share->getSharedBy() !== $uid || (string)$share->getSharedWith() !== (string)$cardId
                || (int)$share->getNodeId() !== $fileId || $share->getPermissions() !== Constants::PERMISSION_READ) {
                return false;
            }
        }
        return (string)$created->getId() === (string)$observed->getId();
    }

    /**
     * Return no pre-write metadata or optimistic attribution when the creation outcome is uncertain.
     *
     * @return array{confirmed:false,message:string} Non-error result that prevents an automatic retry.
     */
    private function uncertain(): array {
        return ['confirmed' => false, 'message' => CardLinkMessages::uncertain()];
    }

    /**
     * Resolve the focused operation services only for an actual preview/confirm.
     *
     * @return array{prepare:CardFileLinkPreparation,native:NativeCardFileLink,plans:CardLinkPlan,shared:SharedWriteGuard} Operation dependencies.
     */
    private function services(): array {
        return $this->services ??= ($this->factory)();
    }

    /** @param callable(): mixed $operation @return array<string, mixed> */
    private function run(callable $operation): array {
        return (new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->logger))->run(self::TOOL, 'deck_read_card', $operation);
    }
}
