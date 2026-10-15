<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Service\GrantPolicy;
use UnexpectedValueException;

/**
 * The payload of each policy event kind written by the administrative commands (contracts §2.3, §3.2), built and
 * read back through one validator so the stored form never drifts from what undo and the future audit delivery read.
 *
 * A change is {uid, key, before, after}: uid is null for an app-wide setting; before/after hold the exact raw value,
 * and null means the key was absent. Presence and value are therefore one field, and absent stays distinct from '0'.
 *
 * - receipt (version 1): {v, actor, action, fromRevision, revision, changes}. Only grant commands from the admin
 *   matrix write one; revision is the revision the command committed, fromRevision + 1; changes are user cells only,
 *   1..PolicyWriter::MAX_CELLS of them, each with before !== after.
 * - audit (version 1): {v, actor, action, fromRevision, revision, receiptId, undoOf, changes}. One per committed
 *   administrative command. A command that has a receipt links it and leaves changes empty (the receipt holds them);
 *   undo links the receipt it restored in undoOf; service and logs-access changes carry their single app-wide change.
 *
 * - cleanup (version 1): {v, scope, uid, cut, epoch}. Written with every revocation, in its transaction; the physical
 *   delete runs after the commit and may repeat (contracts §3.2). scope 'user' names uid and the generation cut (every
 *   credential of uid with auth_gen <= cut goes) and leaves epoch null; scope 'global' names the new auth epoch (every
 *   credential below it goes) and leaves uid and cut null. A cleanup never removes a credential issued after it.
 */
final class PolicyEventPayload {
    public const VERSION = 1;
    public const ACTION_UPDATE = 'grants.update';
    public const ACTION_BULK = 'grants.bulk';
    public const ACTION_COMMANDS = 'grants.commands';
    public const ACTION_UNDO = 'grants.undo';
    public const ACTION_SERVICE = 'service';
    public const ACTION_LOGS = 'logs_access';
    /** Commands whose changes the matrix can undo through a receipt. */
    public const RECEIPT_ACTIONS = [self::ACTION_UPDATE, self::ACTION_BULK, self::ACTION_COMMANDS];
    public const ACTIONS = [...self::RECEIPT_ACTIONS, self::ACTION_UNDO, self::ACTION_SERVICE, self::ACTION_LOGS];
    public const SCOPE_USER = 'user';
    public const SCOPE_GLOBAL = 'global';
    /** App-wide keys an audit change may name, with a null uid. */
    public const APP_KEYS = [GrantPolicy::SERVICE_KEY, 'logs_groups'];

    /**
     * @param string $actor uid of the administrator who sent the command
     * @param string $action one of RECEIPT_ACTIONS
     * @param string $fromRevision revision the command read under the mutex
     * @param string $revision revision the command commits
     * @param list<array{uid:string, key:string, before:?string, after:?string}> $changes changed user cells
     * @return array<string, mixed> a valid receipt payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function receipt(string $actor, string $action, string $fromRevision, string $revision, array $changes): array {
        return self::validate(PolicyEventsMapper::KIND_RECEIPT, ['v' => self::VERSION, 'actor' => $actor, 'action' => $action,
            'fromRevision' => $fromRevision, 'revision' => $revision, 'changes' => $changes]);
    }

    /**
     * @param string|null $actor uid of the administrator, or null for an internal command
     * @param string $action one of ACTIONS
     * @param string $fromRevision revision the command read under the mutex
     * @param string $revision revision the command commits
     * @param string|null $receiptId the receipt of this same command, if it has one
     * @param string|null $undoOf the receipt this command restored, for an undo
     * @param list<array{uid:?string, key:string, before:?string, after:?string}> $changes changes no receipt holds
     * @return array<string, mixed> a valid audit payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function audit(?string $actor, string $action, string $fromRevision, string $revision, ?string $receiptId, ?string $undoOf, array $changes): array {
        return self::validate(PolicyEventsMapper::KIND_AUDIT, ['v' => self::VERSION, 'actor' => $actor, 'action' => $action,
            'fromRevision' => $fromRevision, 'revision' => $revision, 'receiptId' => $receiptId, 'undoOf' => $undoOf, 'changes' => $changes]);
    }

    /**
     * @param string $uid owner of the revoked credentials
     * @param string $cut canonical generation: every credential of $uid at or below it is invalid
     * @return array<string, mixed> a valid cleanup payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function cleanupUser(string $uid, string $cut): array {
        return self::validate(PolicyEventsMapper::KIND_CLEANUP, ['v' => self::VERSION, 'scope' => self::SCOPE_USER,
            'uid' => $uid, 'cut' => $cut, 'epoch' => null]);
    }

    /**
     * @param string $epoch the auth epoch just committed: every credential below it is invalid
     * @return array<string, mixed> a valid cleanup payload
     * @throws UnexpectedValueException when any part is invalid
     */
    public static function cleanupGlobal(string $epoch): array {
        return self::validate(PolicyEventsMapper::KIND_CLEANUP, ['v' => self::VERSION, 'scope' => self::SCOPE_GLOBAL,
            'uid' => null, 'cut' => null, 'epoch' => $epoch]);
    }

    /**
     * Checks a payload of a receipt or audit event exactly: no missing, extra or mistyped field.
     *
     * @param string $kind one of PolicyEventsMapper::KINDS
     * @param array<array-key, mixed> $payload as built or as read back from storage
     * @return array<string, mixed> the same payload
     * @throws UnexpectedValueException when the payload does not match its kind
     */
    public static function validate(string $kind, array $payload): array {
        if ($kind === PolicyEventsMapper::KIND_CLEANUP) {
            return self::validateCleanup($payload);
        }
        $receipt = $kind === PolicyEventsMapper::KIND_RECEIPT;
        if (!$receipt && $kind !== PolicyEventsMapper::KIND_AUDIT) {
            throw new UnexpectedValueException('No payload is defined for this policy event kind');
        }
        $fields = $receipt
            ? ['v', 'actor', 'action', 'fromRevision', 'revision', 'changes']
            : ['v', 'actor', 'action', 'fromRevision', 'revision', 'receiptId', 'undoOf', 'changes'];
        $keys = array_keys($payload);
        sort($keys);
        sort($fields);
        if ($keys !== $fields || ($payload['v'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        $actor = $payload['actor'];
        $action = $payload['action'];
        $valid = (is_string($actor) ? self::validUid($actor) : (!$receipt && $actor === null))
            && in_array($action, $receipt ? self::RECEIPT_ACTIONS : self::ACTIONS, true)
            && is_string($payload['fromRevision']) && is_string($payload['revision'])
            && self::nextRevision($payload['fromRevision'], $payload['revision'])
            && is_array($payload['changes']) && array_is_list($payload['changes'])
            && count($payload['changes']) <= PolicyWriter::MAX_CELLS
            && (!$receipt || $payload['changes'] !== []);
        if ($valid && !$receipt) {
            $valid = self::optionalId($payload['receiptId']) && self::optionalId($payload['undoOf'])
                && ($payload['receiptId'] === null || $payload['changes'] === [])
                && ($action === self::ACTION_UNDO) === ($payload['undoOf'] !== null);
        }
        if (!$valid) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        foreach ($payload['changes'] as $change) {
            if (!self::validChange($change, $receipt)) {
                throw new UnexpectedValueException('Invalid policy event payload');
            }
        }
        return $payload;
    }

    /**
     * @param string $module catalog module, or null for eligibility
     * @param string $operation catalog operation, or GrantPolicy::ELIGIBLE_KEY with a null module
     * @return string|null the user preference key of the cell, or null when the pair names no cell
     */
    public static function userKey(?string $module, string $operation): ?string {
        if ($module === null) {
            return $operation === GrantPolicy::ELIGIBLE_KEY ? GrantPolicy::ELIGIBLE_KEY : null;
        }
        return GrantPolicy::inCatalog($module, $operation) ? 'grant_' . $module . '_' . $operation : null;
    }

    /** @return bool whether $key is eligibility or a grant of the CATALOG */
    public static function validUserKey(string $key): bool {
        if ($key === GrantPolicy::ELIGIBLE_KEY) {
            return true;
        }
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                if ($key === 'grant_' . $module . '_' . $operation) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return bool a user id the official user config accepts: non-empty, valid UTF-8, without NUL */
    public static function validUid(string $uid): bool {
        return $uid !== '' && !str_contains($uid, "\0") && mb_check_encoding($uid, 'UTF-8');
    }

    private static function validateCleanup(array $payload): array {
        $keys = array_keys($payload);
        sort($keys);
        $counter = static function (mixed $value): bool {
            try {
                return is_string($value) && DecimalCounter::validate($value, DecimalCounter::MAX) === $value;
            } catch (\InvalidArgumentException) {
                return false;
            }
        };
        $valid = $keys === ['cut', 'epoch', 'scope', 'uid', 'v'] && ($payload['v'] ?? null) === self::VERSION
            && match ($payload['scope'] ?? null) {
                self::SCOPE_USER => is_string($payload['uid']) && self::validUid($payload['uid']) && $counter($payload['cut']) && $payload['epoch'] === null,
                self::SCOPE_GLOBAL => $payload['uid'] === null && $payload['cut'] === null && $counter($payload['epoch']),
                default => false,
            };
        if (!$valid) {
            throw new UnexpectedValueException('Invalid policy event payload');
        }
        return $payload;
    }

    private static function validChange(mixed $change, bool $receipt): bool {
        if (!is_array($change)) {
            return false;
        }
        $keys = array_keys($change);
        sort($keys);
        if ($keys !== ['after', 'before', 'key', 'uid']) {
            return false;
        }
        ['uid' => $uid, 'key' => $key, 'before' => $before, 'after' => $after] = $change;
        $raw = static fn (mixed $value): bool => $value === null || is_string($value);
        if (!is_string($key) || !$raw($before) || !$raw($after) || $before === $after) {
            return false;
        }
        if ($uid === null) {
            return !$receipt && in_array($key, self::APP_KEYS, true);
        }
        return is_string($uid) && self::validUid($uid) && self::validUserKey($key);
    }

    private static function optionalId(mixed $id): bool {
        return $id === null || (is_string($id) && PolicyEventsMapper::validId($id));
    }

    private static function nextRevision(string $from, string $to): bool {
        try {
            return DecimalCounter::increment($from) === DecimalCounter::validate($to, DecimalCounter::MAX);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
