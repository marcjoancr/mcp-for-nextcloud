<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Service\Compat\AppEnablement;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Scope\ScopeGuard;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use Throwable;

/**
 * Standalone resolution core. Consumer integration is deliberately deferred to ticket 09.
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
        private PolicyWriter $writer,
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
    ) {}

    /**
     * A bounded snapshot assembly: one repeat on concurrent revision change. No directory I/O under a mutex.
     * @param list<string> $added delivered event overlay
     * @param list<string> $removed delivered event overlay
     * @throws PolicyUnavailable read failure, unusable writer, or repeated concurrent change; never falls back
     */
    public function begin(string $uid, array $added = [], array $removed = []): PolicyOperationContext {
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $this->writer->assertUsable();
                $this->userConfig->clearCache($uid, false);
                $this->appConfig->clearCache(false);
                $before = $this->state->state();
                $user = $this->users->get($uid);
                $ids = $user === null ? [] : $this->membership->read($user, true, $added, $removed);
                $candidates = $lost = $lifecycles = [];
                foreach ($this->life->forGroups($ids) as $row) {
                    $lifecycles[IdentityKey::group($row['groupId'])] = $row;
                }
                foreach ($this->grants->forGroups($ids) as $row) {
                    if ($row['grants'] === []) {
                        continue;
                    }
                    $life = $lifecycles[IdentityKey::group($row['groupId'])] ?? null;
                    $trusted = $this->classifier->trusted($row['groupId']);
                    $candidates[] = $row + ['trusted' => $trusted, 'state' => $life['state'] ?? null];
                    if (!$trusted && ($life['state'] ?? null) === GroupLifeMapper::ACTIVE) {
                        $lost[] = ['groupId' => $row['groupId'], 'gen' => $life['gen']];
                    }
                }
                $this->trust->observe($lost);
                $keys = [GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY];
                foreach (GrantPolicy::CATALOG as $module => $operations) {
                    foreach ($operations as $operation) {
                        $keys[] = 'grant_' . $module . '_' . $operation;
                    }
                }
                $raw = [];
                foreach ($keys as $key) {
                    $values = $this->config->getUserValueForUsers(GrantPolicy::APP, $key, [$uid]);
                    $raw[$key] = array_key_exists($uid, $values)
                        ? ['present' => true, 'value' => $values[$uid]] : ['present' => false];
                }
                $enabled = [];
                foreach (GrantMatrix::MODULE_APPS as $module => $app) {
                    $enabled[$module] = $app === '' || $app === 'files'
                        || ($user !== null && AppEnablement::forUser($this->apps, $app, $user));
                }
                $account = $user !== null && $user->isEnabled();
                $service = $this->config->getAppValue(GrantPolicy::APP, GrantPolicy::SERVICE_KEY, '0') === '1';
                $logs = $this->logs->read($user === null ? null : $uid, $ids);
                // Read inside the revision window: every scope write bumps the revision, so a scope saved while
                // the snapshot was assembled repeats it instead of pairing new rows with old grants. A failed read
                // is kept as unavailable for the scope modules only.
                $scope = ScopeGuard::read($this->scopes, $user === null ? null : $uid);
                $after = $this->state->state();
                $this->writer->assertUsable();
                if ($before['revision'] === $after['revision'] && $before['auth_epoch'] === $after['auth_epoch']) {
                    return new PolicyOperationContext($after['revision'], $this->writer, $raw, $candidates,
                        $account, $service, $enabled, $logs['available'], $logs['permitted'], $scope);
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
}
