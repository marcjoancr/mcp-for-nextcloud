<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Resolution;

use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;

use OCA\Mcp\Access\Grants\Groups\MembershipReader;

use OCA\Mcp\Access\Policy\Resolution\LogsRoleReader;

use OCA\Mcp\Access\Grants\Groups\GroupTrustObserver;

use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\ToolGrantResolution;
use OCA\Mcp\Access\ResourceScope\ScopeGuard;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use Throwable;

/**
 * The resolution every consumer reads through GrantPolicy (contracts §4.1–§4.3): individual overrides first, then the
 * groups that may grant (trusted predicate and an active lifecycle), then the default.
 *
 * Fresh reads clear only the official IUserConfig and IAppConfig caches. Declared limits, deliberately not
 * cleared (§4.2): Database::groupExists keeps a positive cache and getBackends() reflects the backends registered
 * in this process; AppManager keeps its own enabled/installed apps cache, so AppEnablement may miss an app
 * enabled or disabled elsewhere; IUserManager::get returns memoized IUser objects. HTTP and cron processes are
 * short; a long-running worker or occ process can observe these stale values.
 */
class PolicyResolver {
    public function __construct(
        private PolicyStateMapper $state,
        private PolicyTransaction $writer,
        private IConfig $config,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private IUserManager $users,
        private MembershipReader $membership,
        private GroupSourceClassifier $classifier,
        private GroupGrantsMapper $grants,
        private GroupLifeMapper $life,
        private GroupTrustObserver $trust,
        private IAppManager $apps,
        private LogsRoleReader $logs,
        private ResourceScopeMapper $scopes,
        private ?ToolGrantsMapper $toolGrants = null,
    ) {}

    /** @return array{assigned:?string,effective:?bool,source:string,groupIds:list<string>} */
    public function groupToolState(string $gid, string $toolName, string $module, string $operation): array {
        return $this->groupToolStates($gid, [['toolName' => $toolName, 'module' => $module, 'operation' => $operation]])[$toolName];
    }

    /** Resolve a group's full visible tool catalog from one policy/lifecycle snapshot. */
    public function groupToolStates(string $gid, array $tools): array {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->writer->assertUsable();
            $before = $this->state->state();
            $legacy = $this->grants->forGroups([$gid])[0]['grants'];
            $stored = $this->toolGrants?->forPrincipals(ToolGrantsMapper::GROUP, [$gid])[$gid] ?? [];
            $life = $this->life->forGroups([$gid])[0] ?? null;
            $trusted = $this->classifier->trusted($gid) && ($life['state'] ?? null) === GroupLifeMapper::ACTIVE;
            $result = [];
            foreach ($tools as $item) {
                $toolName = $item['toolName'];
                $module = $item['module'];
                $operations = $item['grantAnyOf'] ?? [$item['operation']];
                $new = $stored[$toolName] ?? null;
                $resolved = null;
                $assigned = $new;
                if (!array_key_exists('grantAnyOf', $item)) {
                    $operation = $item['operation'];
                    $legacyAllowed = $module === '_system' && $operation === 'eligible'
                        ? in_array('eligible', $legacy['_system'] ?? [], true)
                        : in_array($operation, $legacy[$module] ?? [], true);
                    $legacyResult = ToolGrantResolution::resolve(null, null, [[
                        'decision' => $new, 'legacyAllowed' => $legacyAllowed, 'groupId' => $gid, 'trusted' => $trusted,
                    ]], $module === '_system' ? false : GrantPolicy::defaultGranted($module, $operation));
                    if ($assigned === null && $legacyAllowed && $trusted) {
                        $assigned = ToolGrantsMapper::ALLOW;
                    }
                    $result[$toolName] = ['assigned' => $assigned, 'effective' => $legacyResult['allowed'],
                        'source' => $legacyResult['source'], 'groupIds' => $legacyResult['groupIds']];
                    continue;
                }
                foreach ($operations as $operation) {
                    $legacyAllowed = $module === '_system' && $operation === 'eligible'
                        ? in_array('eligible', $legacy['_system'] ?? [], true)
                        : in_array($operation, $legacy[$module] ?? [], true);
                    $tool = ToolGrantResolution::resolve(null, null, [[
                        'decision' => $new,
                        'legacyAllowed' => $legacyAllowed,
                        'groupId' => $gid,
                        'trusted' => $trusted,
                    ]], $module === '_system' ? false : GrantPolicy::defaultGranted($module, $operation));
                    $operationAllowed = $legacyAllowed || ($module !== '_system' && GrantPolicy::defaultGranted($module, $operation));
                    $variant = self::concreteToolState(
                        ['effective' => $tool['allowed'], 'source' => $tool['source'], 'groupIds' => $tool['groupIds']],
                        ['effective' => $operationAllowed, 'source' => $legacyAllowed ? 'legacy_group' : 'default',
                            'groupIds' => $legacyAllowed ? [$gid] : []], $gid);
                    $resolved = self::anyToolState($resolved, $variant);
                    if ($assigned === null && $legacyAllowed && $trusted) {
                        $assigned = ToolGrantsMapper::ALLOW;
                    }
                }
                $result[$toolName] = ['assigned' => $assigned, 'effective' => $resolved['effective'],
                    'source' => $resolved['source'], 'groupIds' => $resolved['groupIds']];
            }
            $after = $this->state->state();
            $this->writer->assertUsable();
            if ($before['revision'] === $after['revision']) {
                return $result;
            }
        }
        throw new PolicyUnavailable('Policy changed while reading group tool policy');
    }

    /** Resolve a group's proposed decisions as they would be after its admin save. */
    public function projectedGroupToolStates(string $gid, array $cells): array {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->writer->assertUsable();
            $before = $this->state->state();
            $legacy = $this->grants->forGroups([$gid])[0]['grants'];
            $stored = $this->toolGrants?->forPrincipals(ToolGrantsMapper::GROUP, [$gid])[$gid] ?? [];
            $life = $this->life->forGroups([$gid])[0] ?? null;
            $sourceTrusted = $this->classifier->trusted($gid);
            $result = [];
            foreach ($cells as $cell) {
                $name = $cell['toolName'];
                $module = $name === ToolGrantsMapper::SERVICE ? '_system' : $cell['module'];
                $operations = $name === ToolGrantsMapper::SERVICE ? ['eligible'] : ($cell['grantAnyOf'] ?? [$cell['operation']]);
                $decision = $cell['decision'];
                $futureTrusted = $sourceTrusted && ($decision !== ToolGrantsMapper::NEUTRAL || ($life['state'] ?? null) === GroupLifeMapper::ACTIVE);
                if (!array_key_exists('grantAnyOf', $cell)) {
                    $operation = $module === '_system' ? 'eligible' : $cell['operation'];
                    $legacyAllowed = $module === '_system' && $operation === 'eligible'
                        ? in_array('eligible', $legacy['_system'] ?? [], true)
                        : in_array($operation, $legacy[$module] ?? [], true);
                    $legacyResult = ToolGrantResolution::resolve(null, null, [[
                        'decision' => $decision, 'legacyAllowed' => $legacyAllowed, 'groupId' => $gid, 'trusted' => $futureTrusted,
                    ]], $module === '_system' ? false : GrantPolicy::defaultGranted($module, $operation));
                    $result[$name] = ['effective' => $legacyResult['allowed'], 'source' => $legacyResult['source'],
                        'groupIds' => $legacyResult['groupIds']];
                    continue;
                }
                $resolved = null;
                foreach ($operations as $operation) {
                    $legacyAllowed = $module === '_system' && $operation === 'eligible'
                        ? in_array('eligible', $legacy['_system'] ?? [], true)
                        : in_array($operation, $legacy[$module] ?? [], true);
                    $tool = ToolGrantResolution::resolve(null, null, [[
                        'decision' => $decision === ToolGrantsMapper::NEUTRAL ? null : $decision,
                        'legacyAllowed' => $legacyAllowed,
                        'groupId' => $gid,
                        'trusted' => $futureTrusted,
                    ]], $module === '_system' ? false : GrantPolicy::defaultGranted($module, $operation));
                    $operationAllowed = $legacyAllowed || ($module !== '_system' && GrantPolicy::defaultGranted($module, $operation));
                    $resolved = self::anyToolState($resolved, self::concreteToolState(
                        ['effective' => $tool['allowed'], 'source' => $tool['source'], 'groupIds' => $tool['groupIds']],
                        ['effective' => $operationAllowed, 'source' => $legacyAllowed ? 'legacy_group' : 'default',
                            'groupIds' => $legacyAllowed ? [$gid] : []], $gid));
                }
                $result[$name] = ['effective' => $resolved['effective'], 'source' => $resolved['source'],
                    'groupIds' => $resolved['groupIds']];
            }
            $after = $this->state->state();
            $this->writer->assertUsable();
            if ($before['revision'] === $after['revision']) {
                return $result;
            }
        }
        throw new PolicyUnavailable('Policy changed while projecting group tool policy');
    }

    /** Current tool states for a caller-bounded page of users, from grant-only snapshots. */
    public function toolStatesForUsers(array $uids, array $cells): array {
        $result = [];
        foreach (array_chunk(array_values(array_unique($uids)), 100) as $chunk) {
            $contexts = $this->beginManyGrants($chunk);
            try {
                foreach ($contexts as $uid => $context) {
                    foreach ($cells as $cell) {
                        $name = $cell['toolName'];
                        if (!array_key_exists('grantAnyOf', $cell)) {
                            if ($name === ToolGrantsMapper::SERVICE) {
                                $grant = $context->eligible();
                            } else {
                                $grant = $context->toolGranted($name, $cell['module'], $cell['operation']);
                            }
                            $result[$uid][$name] = ['effective' => $grant->allowed, 'source' => $grant->source,
                                'groupIds' => $grant->groupIds];
                            continue;
                        }
                        $operations = $name === ToolGrantsMapper::SERVICE ? ['eligible'] : ($cell['grantAnyOf'] ?? [$cell['operation']]);
                        $grant = null;
                        foreach ($operations as $operation) {
                            if ($name === ToolGrantsMapper::SERVICE) {
                                $tool = $context->eligible();
                                $operationAllowed = $tool->allowed;
                            } else {
                                $operationGrant = $context->granted($cell['module'], $operation);
                                $tool = $context->toolGranted($name, $cell['module'], $operation);
                                $operationAllowed = $operationGrant->allowed;
                            }
                            $operation = $name === ToolGrantsMapper::SERVICE ? $tool : $operationGrant;
                            $grant = self::anyToolState($grant, self::concreteToolState(
                                ['effective' => $tool->allowed, 'source' => $tool->source, 'groupIds' => $tool->groupIds],
                                ['effective' => $operation->allowed, 'source' => $operation->source, 'groupIds' => $operation->groupIds]));
                        }
                        $result[$uid][$name] = $grant;
                    }
                }
            } finally {
                foreach ($contexts as $context) {
                    $context->close();
                }
            }
        }
        return $result;
    }

    /** @return array{effective:?bool,source:string,groupIds:list<string>} */
    private static function concreteToolState(array $tool, array $operation, ?string $gid = null): array {
        $allowed = $tool['effective'] === false || $operation['effective'] === false ? false
            : ($tool['effective'] === true && $operation['effective'] === true ? true : null);
        $groups = [...$tool['groupIds'], ...$operation['groupIds'], ...($gid !== null && $allowed === true ? [$gid] : [])];
        $groups = array_values(array_unique($groups));
        sort($groups, SORT_STRING);
        $source = $allowed === null ? 'unknown'
            : ($allowed === false ? ($operation['effective'] === false ? $operation['source'] : $tool['source'])
                : ($tool['source'] === 'default' ? $operation['source'] : $tool['source']));
        return ['effective' => $allowed, 'source' => $source, 'groupIds' => $groups];
    }

    /** Three-valued any: any true variant wins; otherwise unknown wins over false. */
    private static function anyToolState(?array $current, array $next): array {
        if ($current === null || $next['effective'] === true) {
            return $next;
        }
        if ($current['effective'] === true) {
            return $current;
        }
        if ($current['effective'] === null && $next['effective'] === false) {
            return $current;
        }
        if ($next['effective'] === null) {
            return $next;
        }
        $groups = array_values(array_unique([...$current['groupIds'], ...$next['groupIds']]));
        sort($groups, SORT_STRING);
        return ['effective' => false, 'source' => $current['source'] === 'legacy_group' || $next['source'] === 'legacy_group' ? 'legacy_group' : 'default', 'groupIds' => $groups];
    }

    /**
     * A bounded snapshot assembly: one repeat on concurrent revision change. No directory I/O under a mutex.
     * @param list<string> $added delivered event overlay
     * @param list<string> $removed delivered event overlay
     * @param bool $observe whether an observed loss of group trust is persisted (needs_confirm) by a writer command;
     *     false where no command may run: a pre-event handler, which never takes the mutex, and the external phase of
     *     a writer command. The group then simply does not grant in this read.
     * @throws PolicyUnavailable read failure, unusable writer, or repeated concurrent change; never falls back
     */
    public function begin(string $uid, array $added = [], array $removed = [], bool $observe = true): PolicyOperationContext {
        return $this->assemble([$uid], [$uid => [$added, $removed]], $observe)[$uid];
    }

    /**
     * One context per user over a single revision snapshot, for a page of the admin matrix: each policy key is read
     * once for every user, and the grant rows, lifecycles and classification once per group of the page. The
     * resolution of each context is the one begin() gives for that user. Close every context.
     *
     * @param list<string> $uids Nextcloud user ids
     * @return array<string, PolicyOperationContext> by uid
     * @throws PolicyUnavailable read failure, unusable writer, or repeated concurrent change; never falls back
     */
    public function beginMany(array $uids): array {
        $uids = array_values(array_unique(array_map('strval', $uids)));
        return $uids === [] ? [] : $this->assemble($uids, [], true);
    }

    /**
     * Grant-only contexts: admin counting only, not an access decision. Used in a caller-bounded batch. These contexts support eligible,
     * granted and inherited only: no effective gates/resource scope are assembled, and no trust observation writes.
     * Group rows and raw overrides are fetched in batches, rather than once per member. Close every context.
     *
     * @param list<string> $uids
     * @return array<string, PolicyOperationContext>
     */
    public function beginManyGrants(array $uids): array {
        $uids = array_values(array_unique(array_map('strval', $uids)));
        return $uids === [] ? [] : $this->assemble($uids, [], false, true);
    }

    /**
     * Every user whose eligibility resolves to true now, for the admin filters and totals (contracts §4.3, T10): the
     * individual Allows ('1') and the members of each group that may grant eligibility (trusted, active lifecycle),
     * less every member with an individual value other than '1' (a Deny, or an invalid value, which denies). A user
     * who would be eligible only through a group of indeterminate policy is not counted, since unknown is not
     * eligible, and the aggregation of other backends never adds anyone. A user in two groups counts once.
     *
     * Members come from the backend that serves each group (one call per group), never from a cached IGroup.
     *
     * @return list<string> uids, unique, sorted bytewise
     * @throws PolicyUnavailable when a group or a value cannot be read, or the policy keeps changing
     */
    public function eligibleUids(): array {
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $this->writer->assertUsable();
                $this->appConfig->clearCache(false);
                $before = $this->state->state();
                $candidates = [];
                foreach ($this->userConfig->searchUsersByValueString(GrantPolicy::APP, GrantPolicy::ELIGIBLE_KEY, '1') as $uid) {
                    $candidates[(string)$uid] = true;
                }
                $groups = $this->grants->groupsGranting('_system', 'eligible');
                if ($this->toolGrants !== null) {
                    foreach ($this->toolGrants->principalsForTool(ToolGrantsMapper::USER, ToolGrantsMapper::SERVICE) as $uid) {
                        $candidates[$uid] = true;
                    }
                    $groups = array_values(array_unique([...$groups,
                        ...$this->toolGrants->principalsForTool(ToolGrantsMapper::GROUP, ToolGrantsMapper::SERVICE)]));
                }
                foreach ($groups as $gid) {
                    foreach ($this->classifier->members($gid) as $uid) {
                        $candidates[$uid] = true;
                    }
                }
                $uids = [];
                foreach (array_chunk(array_map('strval', array_keys($candidates)), 100) as $chunk) {
                    foreach ($this->beginManyGrants($chunk) as $uid => $context) {
                        try {
                            if ($context->eligible()->allowed === true) {
                                $uids[] = (string)$uid;
                            }
                        } finally {
                            $context->close();
                        }
                    }
                }
                $after = $this->state->state();
                $this->writer->assertUsable();
                if ($before['revision'] === $after['revision']) {
                    sort($uids, SORT_STRING);
                    return array_values(array_unique($uids));
                }
            }
            throw new PolicyUnavailable('Policy changed while listing eligible users');
        } catch (Throwable $e) {
            if ($e instanceof PolicyUnavailable) {
                throw $e;
            }
            throw new PolicyUnavailable('Cannot list eligible users', previous: $e);
        }
    }

    /**
     * @param list<string> $uids
     * @param array<string, array{0:list<string>, 1:list<string>}> $overlays added and removed GIDs by uid
     * @return array<string, PolicyOperationContext> by uid
     * @throws PolicyUnavailable
     */
    private function assemble(array $uids, array $overlays, bool $observe, bool $grantsOnly = false): array {
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $this->writer->assertUsable();
                foreach ($uids as $uid) {
                    $this->userConfig->clearCache($uid, false);
                }
                $this->appConfig->clearCache(false);
                $before = $this->state->state();
                $users = $ids = $all = [];
                foreach ($uids as $uid) {
                    $user = $users[$uid] = $this->users->get($uid);
                    [$added, $removed] = $overlays[$uid] ?? [[], []];
                    $ids[$uid] = $user === null ? [] : $this->membership->read($user, true, $added, $removed);
                    foreach ($ids[$uid] as $gid) {
                        $all[IdentityKey::group($gid)] = $gid;
                    }
                }
                $all = array_values($all);
                $lifecycles = $groups = $lost = [];
                foreach ($this->life->forGroups($all) as $row) {
                    $lifecycles[IdentityKey::group($row['groupId'])] = $row;
                }
                $legacyGroupRows = [];
                foreach ($this->grants->forGroups($all) as $row) {
                    $legacyGroupRows[IdentityKey::group($row['groupId'])] = $row['grants'];
                }
                $groupToolGrants = $this->toolGrants?->forPrincipals(ToolGrantsMapper::GROUP, $all) ?? [];
                $userToolGrants = $this->toolGrants?->forPrincipals(ToolGrantsMapper::USER, $uids) ?? [];
                foreach ($all as $gid) {
                    $key = IdentityKey::group($gid);
                    $life = $lifecycles[$key] ?? null;
                    // The gen lets a writer command compare, under its mutex, the lifecycle this read relied on.
                    $hasPolicy = ($legacyGroupRows[$key] ?? []) !== [] || ($groupToolGrants[$gid] ?? []) !== [];
                    if (!$hasPolicy) {
                        continue;
                    }
                    $trusted = $this->classifier->trusted($gid);
                    if ($hasPolicy) {
                        $groups[$key] = ['groupId' => $gid, 'grants' => $legacyGroupRows[$key] ?? [],
                            'toolGrants' => $groupToolGrants[$gid] ?? [], 'trusted' => $trusted,
                            'state' => $life['state'] ?? null, 'gen' => $life['gen'] ?? null];
                    }
                    if ($hasPolicy && !$trusted && ($life['state'] ?? null) === GroupLifeMapper::ACTIVE) {
                        $lost[] = ['groupId' => $gid, 'gen' => $life['gen']];
                    }
                }
                if ($observe) {
                    $this->trust->observe($lost);
                }
                $raw = [];
                foreach (self::keys() as $key) {
                    $values = $this->config->getUserValueForUsers(GrantPolicy::APP, $key, $uids);
                    foreach ($uids as $uid) {
                        $raw[$uid][$key] = array_key_exists($uid, $values)
                            ? ['present' => true, 'value' => $values[$uid]] : ['present' => false];
                    }
                }
                $service = $this->config->getAppValue(GrantPolicy::APP, GrantPolicy::SERVICE_KEY, '0') === '1';
                $parts = [];
                foreach ($uids as $uid) {
                    $user = $users[$uid];
                    $enabled = [];
                    foreach (GrantMatrix::MODULE_APPS as $module => $app) {
                        $enabled[$module] = !$grantsOnly && ($app === '' || $app === 'files'
                            || ($user !== null && AppEnablement::forUser($this->apps, $app, $user)));
                    }
                    $candidates = [];
                    foreach ($ids[$uid] as $gid) {
                        if (isset($groups[IdentityKey::group($gid)])) {
                            $candidates[] = $groups[IdentityKey::group($gid)];
                        }
                    }
                    $logs = $grantsOnly ? ['available' => false, 'permitted' => false]
                        : $this->logs->read($user === null ? null : $uid, $ids[$uid]);
                    // Read inside the revision window: every scope write bumps the revision, so a scope saved while
                    // the snapshot was assembled repeats it instead of pairing new rows with old grants. A failed read
                    // is kept as unavailable for the scope modules only.
                    $scope = $grantsOnly ? null : ScopeGuard::read($this->scopes, $user === null ? null : $uid);
                    $parts[$uid] = [$raw[$uid], $candidates, $user !== null && $user->isEnabled(), $enabled, $logs, $scope,
                        $userToolGrants[$uid] ?? []];
                }
                $after = $this->state->state();
                $this->writer->assertUsable();
                if ($before['revision'] === $after['revision'] && $before['auth_epoch'] === $after['auth_epoch']) {
                    $contexts = [];
                    foreach ($parts as $uid => [$userRaw, $candidates, $account, $enabled, $logs, $scope, $tools]) {
                        $contexts[$uid] = new PolicyOperationContext($after['revision'], $this->writer, $userRaw, $candidates,
                            $account, $service, $enabled, $logs['available'], $logs['permitted'], $scope, $grantsOnly, $tools);
                    }
                    return $contexts;
                }
            }
            throw new PolicyUnavailable('Policy changed while assembling the operation');
        } catch (Throwable $e) {
            if ($e instanceof PolicyUnavailable) {
                throw $e;
            }
            throw new PolicyUnavailable('Cannot assemble policy operation', previous: $e);
        }
    }

    /** @return list<string> every user policy key: eligibility, connection and each catalog grant */
    private static function keys(): array {
        $keys = [GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY];
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $keys[] = 'grant_' . $module . '_' . $operation;
            }
        }
        return $keys;
    }
}
