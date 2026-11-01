<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Grants\Groups\LifecycleHolds;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\ToolGrantResolution;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\Revocation\CredentialCuts;

/** Revisioned preview and commit commands for individual and group tool decisions. */
final class ToolGrantCommands {
    public function __construct(
        private PolicyTransaction $transaction,
        private ToolGrantsMapper $tools,
        private PolicyEventsMapper $events,
        private GroupGrantsMapper $legacyGroups,
        private GroupLifeMapper $life,
        private GroupSourceClassifier $groups,
        private IUserManager $users,
        private ITimeFactory $time,
        private GrantPolicy $policy,
        private OAuthStore $oauth,
        private AuthUsersMapper $authUsers,
        private CredentialCuts $cuts,
        private LifecycleHolds $holds,
    ) {}

    public function previewUser(string $expectedRevision, string $actor, string $uid, mixed $cells): array {
        return $this->preview($expectedRevision, $actor, ToolGrantsMapper::USER, $uid, $cells);
    }

    public function previewGroup(string $expectedRevision, string $actor, string $gid, mixed $cells): array {
        return $this->preview($expectedRevision, $actor, ToolGrantsMapper::GROUP, $gid, $cells);
    }

    public function setUser(string $expectedRevision, string $actor, string $uid, mixed $cells, string $previewId): array {
        return $this->set($expectedRevision, $actor, ToolGrantsMapper::USER, $uid, $cells, $previewId);
    }

    public function setGroup(string $expectedRevision, string $actor, string $gid, mixed $cells, string $previewId): array {
        return $this->set($expectedRevision, $actor, ToolGrantsMapper::GROUP, $gid, $cells, $previewId);
    }

    private function preview(string $expected, string $actor, string $kind, string $subject, mixed $rawCells): array {
        $cells = self::cells($rawCells);
        $memberHash = $kind === ToolGrantsMapper::GROUP ? $this->memberHash($subject) : null;
        $life = $kind === ToolGrantsMapper::GROUP ? $this->life($subject) : null;
        $impact = $this->impact($kind, $subject, $cells, false);
        $previewId = null;
        $result = $this->transaction->runAdmin($expected, $kind === ToolGrantsMapper::USER ? [$subject] : [],
            fn () => $this->admit($kind, $subject, $cells),
            function (array $snapshot, PolicyAttempt $attempt) use ($actor, $kind, $subject, $expected, $cells, $memberHash, $life, $impact, &$previewId): bool {
                $current = $attempt->state();
                if ($kind === ToolGrantsMapper::GROUP && ($snapshot['memberHash'] !== $memberHash || $snapshot['life'] !== $life)) {
                    throw new PolicyConflict();
                }
                $previewId = $attempt->outbox()->add(PolicyEventsMapper::KIND_PREVIEW,
                    PolicyEventPayload::toolPreview($actor, $kind, $subject, $expected, $cells, $memberHash, $life, $impact));
                return false;
            });
        return ['previewId' => $previewId, 'revision' => $result['revision'], 'changed' => count($cells), 'impact' => $impact];
    }

    private function set(string $expected, string $actor, string $kind, string $subject, mixed $rawCells, string $previewId): array {
        $cells = self::cells($rawCells);
        $preview = $this->readPreview($previewId, $actor);
        if ($preview['kind'] !== $kind || $preview['subject'] !== $subject || $preview['revision'] !== $expected || $preview['cells'] !== $cells) {
            throw new ReceiptNotFound();
        }
        $memberHash = $kind === ToolGrantsMapper::GROUP ? $this->memberHash($subject) : null;
        $life = $kind === ToolGrantsMapper::GROUP ? $this->life($subject) : null;
        if ($preview['memberHash'] !== $memberHash || $preview['life'] !== $life) {
            throw new PolicyConflict();
        }
        $result = $this->transaction->runAdmin($expected, $kind === ToolGrantsMapper::USER ? [$subject] : [],
            function () use ($kind, $subject, $cells, $preview): array {
                $admission = $this->admit($kind, $subject, $cells);
                $hold = null;
                if ($kind === ToolGrantsMapper::USER && array_filter($cells, static fn (array $cell): bool =>
                    $cell['toolName'] === ToolGrantsMapper::SERVICE && $cell['decision'] === ToolGrantsMapper::NEUTRAL) !== []) {
                    $hold = $this->policy->inheritance($subject, ToolGrantsMapper::SERVICE);
                }
                $impact = $this->impact($kind, $subject, $cells, true);
                $publicImpact = $impact;
                unset($publicImpact['lossUids']);
                unset($publicImpact['holdLifecycles']);
                if ($publicImpact !== $preview['impact']) {
                    throw new PolicyConflict();
                }
                return ['admission' => $admission, 'impact' => $impact, 'hold' => $hold];
            },
            function (array $snapshot, PolicyAttempt $attempt) use ($actor, $kind, $subject, $cells, $memberHash, $life): bool {
                if ($kind === ToolGrantsMapper::GROUP && ($snapshot['admission']['memberHash'] !== $memberHash || $snapshot['admission']['life'] !== $life)) {
                    throw new PolicyConflict();
                }
                $before = $this->tools->forPrincipals($kind, [$subject])[$subject] ?? [];
                $beforeLife = $kind === ToolGrantsMapper::GROUP ? $this->life->get($subject) : null;
                $willChange = false;
                foreach ($cells as $cell) {
                    if (($before[$cell['toolName']] ?? null) !== $cell['decision']) {
                        $willChange = true;
                        break;
                    }
                }
                if ($willChange) {
                    $lifecycles = [...($snapshot['impact']['holdLifecycles'] ?? []), ...(is_array($snapshot['hold']) ? $snapshot['hold'] : [])];
                    if ($lifecycles !== []) {
                        $this->holds->hold($attempt, $lifecycles);
                    }
                }
                foreach ($cells as $cell) {
                    $this->tools->set($kind, $subject, $cell['toolName'], $cell['decision']);
                }
                $after = $this->tools->forPrincipals($kind, [$subject])[$subject] ?? [];
                if ($before === $after) {
                    if ($kind === ToolGrantsMapper::USER) {
                        return false;
                    }
                    $stored = $this->life->get($subject);
                    $legacy = $this->legacyGroups->forGroups([$subject])[0]['grants'];
                    if ($stored === null && $after === [] && $legacy === []) {
                        return false;
                    }
                    if ($stored !== null && $stored['state'] === GroupLifeMapper::ACTIVE) {
                        return false;
                    }
                }
                $outbox = $attempt->outbox();
                $revision = DecimalCounter::increment($attempt->state()['revision']);
                if ($kind === ToolGrantsMapper::GROUP) {
                    $legacy = $this->legacyGroups->forGroups([$subject])[0]['grants'];
                    if ($after !== [] || $legacy !== []) {
                        $stored = $this->life->get($subject);
                        $gen = bin2hex(random_bytes(16));
                        if ($stored === null) {
                            $this->life->put($subject, GroupLifeMapper::ACTIVE, $gen, $revision);
                        } elseif (!$this->life->replace($subject, $stored['gen'], GroupLifeMapper::ACTIVE, $gen, $revision)) {
                            throw new PolicyConflict();
                        }
                    } else {
                        $stored = $this->life->get($subject);
                        if ($stored !== null && !$this->life->delete($subject, $stored['gen'])) {
                            throw new PolicyConflict();
                        }
                    }
                }
                if ($snapshot['impact']['lossUids'] !== []) {
                    $losses = array_fill_keys($snapshot['impact']['lossUids'], true);
                    foreach ($this->oauth->liveHolders($this->time->getTime(), $attempt->state()['auth_epoch']) as $holder) {
                        $uid = $holder['uid'];
                        if (!isset($losses[$uid])) {
                            continue;
                        }
                        $generation = $this->authUsers->generations($uid);
                        if (DecimalCounter::compare($holder['gen'], $generation['revoked_below']) > 0) {
                            $this->cuts->user($attempt, $uid);
                            unset($losses[$uid]);
                        }
                    }
                }
                if ($kind === ToolGrantsMapper::USER) {
                    $changes = [];
                    foreach ($cells as $cell) {
                        if (($before[$cell['toolName']] ?? null) !== ($after[$cell['toolName']] ?? null)) {
                            $changes[] = ['uid' => $subject, 'key' => self::userKey($cell['toolName']),
                                'before' => $before[$cell['toolName']] ?? null, 'after' => $after[$cell['toolName']] ?? null];
                        }
                    }
                } else {
                    $afterLife = $this->life->get($subject);
                    $changes = [['gid' => $subject, 'key' => 'tool_grants',
                        'before' => self::groupSnapshot($beforeLife['state'] ?? null, $before),
                        'after' => self::groupSnapshot($afterLife['state'] ?? null, $after)]];
                }
                $outbox->add(PolicyEventsMapper::KIND_AUDIT,
                    PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_TOOL, $attempt->state()['revision'], $revision, null, null, $changes));
                return true;
            });
        return ['revision' => $result['revision'], 'changed' => $result['changed']];
    }

    private function impact(string $kind, string $subject, array $cells, bool $includeLossUids): array {
        $uids = $kind === ToolGrantsMapper::USER ? [$subject] : array_values($this->groups->members($subject));
        sort($uids, SORT_STRING);
        $groupId = $kind === ToolGrantsMapper::GROUP ? $subject : null;
        $projected = $this->policy->projectedToolStates($uids, $cells, $groupId);
        $current = $this->policy->toolStatesForUsers($uids, $cells);
        $groupProjected = $kind === ToolGrantsMapper::GROUP ? $this->policy->projectedGroupToolStates($subject, $cells) : [];
        $lossUids = $affected = $holdNeeds = [];
        $tools = [];
        foreach ($cells as $cell) {
            $name = $cell['toolName'];
            $service = $name === ToolGrantsMapper::SERVICE;
            if ($kind === ToolGrantsMapper::GROUP) {
                $tools[$name] = $groupProjected[$name] ?? ['effective' => null, 'source' => 'unknown', 'groupIds' => []];
            } else {
                $state = $projected[$subject][$name] ?? ['effective' => null, 'source' => 'unknown', 'groupIds' => []];
                $tools[$name] = $state;
            }
            foreach ($uids as $uid) {
                $before = $current[$uid][$name] ?? ['effective' => null];
                $after = $projected[$uid][$name] ?? ['effective' => null];
                if ($before['effective'] !== $after['effective']) {
                    $affected[$uid] = true;
                }
                if ($service && ToolGrantResolution::isEligibilityLoss($before['effective'], $after['effective'])) {
                    $lossUids[$uid] = true;
                }
                if ($kind === ToolGrantsMapper::GROUP && $service && $includeLossUids && $after['effective'] === true) {
                    $ids = array_values(array_filter($after['groupIds'] ?? [], static fn (string $gid): bool => $gid !== $subject));
                    if ($ids !== []) {
                        $holdNeeds[$uid] = $ids;
                    }
                }
            }
        }
        $loss = array_keys($lossUids);
        sort($loss, SORT_STRING);
        ksort($tools, SORT_STRING);
        $impact = ['tools' => $tools, 'affectedUsers' => count($affected), 'eligibilityLoss' => count($loss),
            'summary' => count($affected) . ' users affected; ' . count($loss) . ' lose service eligibility'];
        if ($includeLossUids) {
            $impact['lossUids'] = $loss;
            $lifecycles = $kind === ToolGrantsMapper::GROUP && $holdNeeds !== []
                ? $this->policy->lifecyclesForUsers($holdNeeds) : [];
            $held = array_fill_keys(array_column($lifecycles, 'groupId'), true);
            foreach ($holdNeeds as $ids) {
                foreach ($ids as $gid) {
                    if (!isset($held[$gid])) {
                        throw new PolicyConflict();
                    }
                }
            }
            $impact['holdLifecycles'] = $lifecycles;
        }
        return $impact;
    }

    /** @return array{kind:string,subject:string,revision:string,cells:array,memberHash:?string,life:?array} */
    private function readPreview(string $id, string $actor): array {
        if (!PolicyEventsMapper::validId($id)) {
            throw new ReceiptNotFound();
        }
        try {
            $event = $this->events->find($id);
            $preview = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_PREVIEW ? null
                : PolicyEventPayload::validate(PolicyEventsMapper::KIND_PREVIEW, $event['payload']);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid tool policy preview', previous: $e);
        }
        if ($preview === null || ($preview['actor'] ?? null) !== $actor || !isset($preview['kind'])) {
            throw new ReceiptNotFound();
        }
        if ($this->time->getTime() >= $event['createdAt'] + PolicyLimits::PREVIEW_TTL) {
            throw new ReceiptExpired();
        }
        return $preview;
    }

    private function admit(string $kind, string $subject, array $cells): array {
        if ($kind === ToolGrantsMapper::USER) {
            if ($this->users->get($subject) === null) {
                throw new \OutOfBoundsException();
            }
            return [];
        }
        $group = $this->groups->classify($subject);
        if (!$group['exists']) {
            throw new \OutOfBoundsException();
        }
        if (!$group['trusted'] && array_filter($cells, static fn (array $cell): bool => $cell['decision'] !== 'neutral') !== []) {
            throw new InvalidArgumentException('Only trusted Nextcloud groups can receive MCP grants');
        }
        return ['memberHash' => $this->memberHash($subject), 'life' => $this->life($subject)];
    }

    private function memberHash(string $gid): string {
        $members = array_values($this->groups->members($gid));
        sort($members, SORT_STRING);
        return PolicyEventPayload::memberHash($members);
    }

    private function life(string $gid): ?array {
        $row = $this->life->forGroups([$gid])[0] ?? null;
        return $row === null ? null : ['state' => $row['state'], 'gen' => $row['gen']];
    }

    private static function cells(mixed $cells): array {
        if (!is_array($cells) || !array_is_list($cells) || $cells === [] || count($cells) > PolicyLimits::MAX_CELLS) {
            throw new \InvalidArgumentException('Invalid tool grant cells');
        }
        $unique = [];
        foreach ($cells as $cell) {
            if (!is_array($cell) || !is_string($cell['toolName'] ?? null) || !is_string($cell['module'] ?? null)
                || !is_string($cell['operation'] ?? null) || !is_string($cell['decision'] ?? null)
                || !ToolGrantsMapper::validToolName($cell['toolName'])
                || !in_array($cell['decision'], [ToolGrantsMapper::ALLOW, ToolGrantsMapper::DENY, ToolGrantsMapper::NEUTRAL], true)) {
                throw new \InvalidArgumentException('Invalid tool grant cell');
            }
            $service = $cell['toolName'] === ToolGrantsMapper::SERVICE && $cell['module'] === '_system' && $cell['operation'] === 'eligible';
            if (!$service && !GrantPolicy::inCatalog($cell['module'], $cell['operation'])) {
                throw new \InvalidArgumentException('Invalid tool grant key');
            }
            $variants = null;
            if (array_key_exists('grantAnyOf', $cell)) {
                if ($service || !is_array($cell['grantAnyOf']) || !array_is_list($cell['grantAnyOf']) || $cell['grantAnyOf'] === []) {
                    throw new \InvalidArgumentException('Invalid tool operation alternatives');
                }
                $variants = [];
                foreach ($cell['grantAnyOf'] as $operation) {
                    if (!is_string($operation) || !GrantPolicy::inCatalog($cell['module'], $operation) || in_array($operation, $variants, true)) {
                        throw new \InvalidArgumentException('Invalid tool operation alternatives');
                    }
                    $variants[] = $operation;
                }
                sort($variants, SORT_STRING);
            }
            if (isset($unique[$cell['toolName']])) {
                throw new \InvalidArgumentException('Duplicate tool grant cell');
            }
            $unique[$cell['toolName']] = ['toolName' => $cell['toolName'], 'module' => $cell['module'],
                'operation' => $cell['operation'], 'decision' => $cell['decision']]
                + ($variants === null ? [] : ['grantAnyOf' => $variants]);
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    private static function userKey(string $toolName): string {
        return 'tool_' . hash('sha256', $toolName);
    }

    private static function groupSnapshot(?string $state, array $grants): ?string {
        if ($state === null && $grants === []) {
            return null;
        }
        ksort($grants, SORT_STRING);
        return json_encode(['state' => $state, 'tools' => (object)$grants], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function snapshot(array $grants): string {
        ksort($grants, SORT_STRING);
        return json_encode((object)$grants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
