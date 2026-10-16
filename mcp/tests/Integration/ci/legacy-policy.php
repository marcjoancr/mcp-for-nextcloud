<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Upgrade cells only (validation T12/T13), inside the container, with the released baseline's code:
 *
 *   seed <output.json>              while the baseline is the installed app, before the upgrade (harness.sh install-app):
 *                                   writes the synthetic snapshot of Compat/SnapshotProbe through the baseline's own
 *                                   GrantPolicy setters, issues one access token per user through its own store, and
 *                                   records the plan, the stored bytes and every answer of that release.
 *   read <output.json> <app-dir>    after the upgrade (Compat/UpgradeSnapshotTest): loads GrantPolicy from the baseline
 *                                   copy at <app-dir> (the one harness.sh moved aside) instead of the installed branch,
 *                                   and records the stored bytes and every answer that release gives for them now.
 *                                   Nothing is downgraded; it reads only, besides putting the service switch back.
 *
 * The accounts of the snapshot are created later by the fixture step; a preference only names its user.
 */

use OCA\Mcp\Tests\Integration\Compat\SnapshotProbe;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['seed', 'read'], true) || !isset($argv[2]) || ($argv[1] === 'read' && !isset($argv[3]))) {
    fwrite(STDERR, "Usage: php legacy-policy.php seed <output.json> | read <output.json> <baseline-app-dir>\n");
    exit(1);
}
[, $mode, $output] = $argv;
require_once '/var/www/html/lib/base.php';
require_once __DIR__ . '/../Compat/SnapshotProbe.php';

if ($mode === 'seed') {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
} else {
    // The baseline's classes win over the installed branch's: this loader is prepended, and nothing of OCA\Mcp may be
    // loaded yet. The file GrantPolicy came from is checked below, so a wrong release can never pass silently.
    $lib = rtrim($argv[3], '/') . '/lib/';
    if (class_exists('OCA\\Mcp\\Service\\GrantPolicy', false)) {
        fwrite(STDERR, "GrantPolicy was loaded before the baseline loader\n");
        exit(1);
    }
    spl_autoload_register(static function (string $class) use ($lib): void {
        if (str_starts_with($class, 'OCA\\Mcp\\')) {
            $file = $lib . str_replace('\\', '/', substr($class, strlen('OCA\\Mcp\\'))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

$config = Server::get(IConfig::class);
$appConfig = Server::get(IAppConfig::class);
$userConfig = Server::get(IUserConfig::class);
$db = Server::get(\OCP\IDBConnection::class);
$store = new \OCA\Mcp\OAuth\OAuthStore($db);
// The baseline's constructor: (IConfig, OAuthStore, ?IUserConfig). Built by hand so the branch's container never
// resolves it.
$policy = new \OCA\Mcp\Service\GrantPolicy($config, $store, $userConfig);
$source = (string)(new ReflectionClass($policy))->getFileName();
if ($mode === 'read' && !str_starts_with($source, $lib)) {
    fwrite(STDERR, "GrantPolicy came from $source, not from the baseline at $lib\n");
    exit(1);
}
$catalog = \OCA\Mcp\Service\GrantPolicy::CATALOG;
$uids = SnapshotProbe::uids();
$result = ['source' => $source, 'catalog' => $catalog];

if ($mode === 'seed') {
    $plan = SnapshotProbe::plan($catalog);
    foreach ($plan as $uid => $values) {
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            match (true) {
                $key === 'eligible' => $policy->setEligible($uid, $value === '1'),
                $key === 'connected' => $policy->setConnected($uid, $value === '1'),
                default => $policy->setGrant($uid, ...[...explode('_', substr($key, strlen('grant_')), 2), $value === '1']),
            };
        }
    }
    // After the setters: the baseline's setEligible(false) deletes the user's tokens.
    $hasher = Server::get(\OCA\Mcp\OAuth\TokenHasher::class);
    $now = time();
    $ttl = 6 * 3600;
    $tokens = [];
    foreach ($uids as $uid) {
        $tokens[$uid] = $hasher->generate('at');
        $store->insertToken(['user_id' => $uid, 'client_id' => SnapshotProbe::CLIENT, 'resource' => SnapshotProbe::RESOURCE, 'scope' => 'mcp',
            'access_hash' => $hasher->hash($tokens[$uid]), 'access_expires' => $now + $ttl,
            'refresh_hash' => $hasher->hash($hasher->generate('rt')), 'refresh_expires' => $now + $ttl, 'created_at' => $now], $now);
    }
    $result += ['plan' => $plan, 'tokens' => $tokens, 'appKeys' => $appConfig->getAllValues('mcp')];
}
$result += ['raw' => SnapshotProbe::raw($config, $appConfig, $userConfig, $uids),
    'answers' => SnapshotProbe::answers($policy, $catalog, $config, $appConfig, $userConfig, $uids)];
file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR));
echo "Legacy policy snapshot ($mode) of ", count($uids), ' users from ', $source, PHP_EOL;
