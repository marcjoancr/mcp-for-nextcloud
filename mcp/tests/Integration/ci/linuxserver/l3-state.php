<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use OCP\{Server, IConfig, IDBConnection, IUserManager, IGroupManager};
require '/fixture-probes/ProbeCompletion.php';
require '/fixture-probes/state-value.php';
require '/fixture-probes/app-paths.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
if (posix_geteuid() !== 1000) throw new RuntimeException('Fixture user required');
$mode = $argv[1] ?? '';
require '/fixture-probes/state-snapshot.php';

function assert121Baseline(IConfig $config, array $fixture, string $mode): void {
    if (($fixture['baseline'] ?? null) !== '1.2.1') return;
    $seed = $fixture['baseline121'] ?? throw new RuntimeException('Missing 1.2.1 baseline expectations');
    $settings = json_decode($config->getAppValue('mcp', 'backup_settings', ''), true, 16, JSON_THROW_ON_ERROR);
    if ($mode === 'persist-after') {
        if (($settings['folder'] ?? null) !== 'L3 backups' || ($settings['copies'] ?? null) !== true
            || !in_array('MCP backups', $settings['reserved'] ?? [], true)
            || !in_array($seed['backupSettings']['folder'], $settings['reserved'] ?? [], true)) {
            throw new RuntimeException('1.2.1 backup policy or protected legacy name was lost during recreation');
        }
    } elseif ($settings !== $seed['backupSettings']) {
        throw new RuntimeException('1.2.1 backup policy changed before the candidate backup configuration');
    }
    $backend = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
    $calendarId = $seed['taskCalendarId'];
    $parent = $backend->getCalendarObject($calendarId, $seed['taskParent']['uri']);
    $child = $backend->getCalendarObject($calendarId, $seed['taskChild']['uri']);
    if (!$parent || !$child || !str_contains($parent['calendardata'], 'UID:' . $seed['taskParent']['uid'])
        || !str_contains($parent['calendardata'], 'SUMMARY:' . $seed['taskParent']['summary'])
        || !str_contains($child['calendardata'], 'UID:' . $seed['taskChild']['uid'])
        || !str_contains($child['calendardata'], 'SUMMARY:' . $seed['taskChild']['summary'])
        || !preg_match('/RELATED-TO(?:;[^:\\r\\n]*)?:' . preg_quote($seed['taskParent']['uid'], '/') . '(?:\\r?\\n)/', $child['calendardata'])) {
        throw new RuntimeException('1.2.1 task hierarchy changed during upgrade or recreation');
    }
}

try {
$config = Server::get(IConfig::class); $version = $config->getAppValue('mcp', 'installed_version');
$apps = json_decode(file_get_contents('/fixture-probes/apps.json'), true, 512, JSON_THROW_ON_ERROR);
$baselineVersion = $apps['mcp'] ?? throw new RuntimeException('Pinned app fixture has no MCP baseline');
if (in_array($mode, ['before', 'after', 'persist-after'], true)) {
    $seedPath = $mode === 'persist-after' ? '/tmp/l3-private.json' : '/tmp/l2-private.json';
    $seed = json_decode(file_get_contents($seedPath), true, 512, JSON_THROW_ON_ERROR);
    if (($seed['baseline'] ?? null) !== $baselineVersion) throw new RuntimeException('Seeded baseline differs from pinned apps.json');
}
if ($mode === 'before') {
    if ($version !== $baselineVersion) throw new RuntimeException('Installed baseline differs from pinned apps.json');
    assert121Baseline($config, $seed, $mode);
    savePrivate('/tmp/l3-before.json', snapshot());
} elseif (in_array($mode, ['after', 'persist-after'], true)) {
    $candidate = json_decode(file_get_contents('/fixture-source/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($version !== ($candidate['version'] ?? null)) throw new RuntimeException('Installed candidate differs from staged source manifest');
    $before = json_decode(file_get_contents($mode === 'after' ? '/tmp/l3-before.json' : '/tmp/l3-persist.json'), true, 512, JSON_THROW_ON_ERROR);
    if (realpath(Server::get(\OCP\App\IAppManager::class)->getAppPath('mcp')) !== '/config/www/nextcloud/apps/mcp') throw new RuntimeException('Unexpected installed app path');
    assertSingleMcpTree(\OC::$APPSROOTS, '/config/www/nextcloud/apps/mcp');
    $actual = snapshot();
    foreach (['schema', 'tables', 'settings', 'sourceOffer', 'files'] as $kind) if ($before[$kind] !== $actual[$kind]) {
        $changed = array_keys(array_filter((array)$actual[$kind], fn ($value, $key) => ($before[$kind][$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        throw new RuntimeException('Persisted ' . $kind . ' changed: ' . implode(',', $changed));
    }
    assert121Baseline($config, $seed, $mode);
    if ($mode === 'after') {
        $policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        foreach (['l1-admin', 'l2-owner', 'l2-reader', 'l2-denied'] as $uid) if ($policy->granted($uid, 'mail', 'read')) throw new RuntimeException('Mail privilege expanded');
        if ($baselineVersion === '1.2.0'
            && (array_key_exists('backup_settings', $actual['settings'])
                || Server::get(\OCA\Mcp\Access\BackupPolicy\BackupLocation::class)->current() !== \OCA\Mcp\Access\BackupPolicy\BackupLocation::defaults())) {
            throw new RuntimeException('Uncustomized 1.2.0 backup defaults changed');
        }
    }
} elseif ($mode === 'suite-backup-save' || $mode === 'suite-backup-restore') {
    $seed = json_decode(file_get_contents('/tmp/l2-private.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($baselineVersion !== '1.2.1' || $seed['baseline'] !== $baselineVersion) throw new RuntimeException('Wrong backup policy fixture');
    $saved = '/tmp/l3-suite-backup.json';
    if ($mode === 'suite-backup-save') {
        assert121Baseline($config, $seed, $mode);
        savePrivate($saved, ['value' => $config->getAppValue('mcp', 'backup_settings')]);
        $config->deleteAppValue('mcp', 'backup_settings');
        if (in_array('backup_settings', $config->getAppKeys('mcp'), true)) throw new RuntimeException('Regression suite needs a fresh backup policy');
    } else {
        $value = json_decode(file_get_contents($saved), true, 16, JSON_THROW_ON_ERROR)['value'];
        $config->setAppValue('mcp', 'backup_settings', $value);
        assert121Baseline($config, $seed, $mode);
        if ($config->getAppValue('mcp', 'backup_settings') !== $value || !unlink($saved)) throw new RuntimeException('Could not restore pre-suite policy');
    }
} elseif ($mode === 'fixtures') {
    $policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
    foreach (['admin', 'mcp-it-alice', 'mcp-it-bob', 'mcp-it-deck-owner', 'mcp-it-deck-editor', 'mcp-it-deck-reader', 'mcp-it-deck-outsider'] as $uid) {
        $user = Server::get(IUserManager::class)->createUser($uid, $uid === 'admin' ? getenv('MCP_ADMIN_PASSWORD') : bin2hex(random_bytes(32)));
        if (!$user) throw new RuntimeException('Regression fixture user creation failed');
        $policy->setEligible($uid, true); $policy->setConnected($uid, true);
    }
    Server::get(IGroupManager::class)->get('admin')->addUser(Server::get(IUserManager::class)->get('admin'));
    foreach (['mcp-it-staff', 'mcp-it-empty'] as $gid) Server::get(IGroupManager::class)->createGroup($gid);
    Server::get(IGroupManager::class)->get('mcp-it-staff')->addUser(Server::get(IUserManager::class)->get('mcp-it-alice'));
    $offer = json_decode(file_get_contents('/fixture-source/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $config->setSystemValue('mcp_source_offer', ['version' => $offer['version'], 'sourceCommit' => $offer['sourceCommit'], 'manifestSha256' => hash_file('sha256', '/fixture-source/manifest.json'), 'baseUrl' => 'https://nc.fixture.test/source']);
    $config->setSystemValue('cache_app_config', false);
    $private = json_decode(file_get_contents('/tmp/l2-private.json'), true, 512, JSON_THROW_ON_ERROR);
    $private['adminPassword'] = getenv('MCP_ADMIN_PASSWORD');
    savePrivate('/tmp/l3-private.json', $private);
} elseif ($mode === 'persist-before') {
    savePrivate('/tmp/l3-persist.json', snapshot());
} elseif ($mode === 'offer-missing' || $mode === 'offer-mismatch' || $mode === 'offer-valid') {
    $offer = json_decode(file_get_contents('/fixture-source/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $config->setSystemValue('mcp_source_offer', $mode === 'offer-missing' ? null : ['version' => $offer['version'], 'sourceCommit' => $mode === 'offer-mismatch' ? str_repeat('f', 40) : $offer['sourceCommit'], 'manifestSha256' => hash_file('sha256', '/fixture-source/manifest.json'), 'baseUrl' => 'https://nc.fixture.test/source']);
} elseif ($mode === 'disconnect' || $mode === 'reconnect') {
    Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setConnected('l2-owner', $mode === 'reconnect');
} elseif ($mode === 'reset-visibility') {
    $out = json_decode(file_get_contents('/tmp/l3-private.json'), true, 512, JSON_THROW_ON_ERROR);
    Server::get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class)->setHiddenTagIds([$out['excludedTag']]);
} elseif ($mode === 'permissions-allowed' || $mode === 'permissions-denied') {
    $policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
    foreach (['people', 'logs'] as $domain) $policy->setGrant('l2-owner', $domain, 'read', $mode === 'permissions-allowed');
    if ($mode === 'permissions-allowed') {
        $logs = Server::get(\OCA\Mcp\Access\Logs\LogsAccess::class); $before = $logs->state();
        $logs->setGroups(['l2-allowed'], $before['groups'], $before['version'], Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'], 'l1-admin');
    }
} elseif ($mode === 'final') {
    $out = json_decode(file_get_contents('/tmp/l3-private.json'), true, 512, JSON_THROW_ON_ERROR);
    Server::get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class)->setHiddenTagIds([$out['excludedTag']]);
    $service = Server::get(\OCA\Mcp\OAuth\TokenService::class);
    $clients = \OCA\Mcp\OAuth\CustomClient::clients(Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class));
    $client = array_values(array_filter($clients, fn ($client) => $client['name'] === 'L2 fixture'))[0];
    foreach ($out['users'] as $uid => &$credentials) {
        if ($uid === 'l2-denied') continue;
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request = new \OCA\Mcp\OAuth\AuthorizationRequest(\OCA\Mcp\OAuth\CustomClient::metadata($client), $credentials['redirect'], 'l3', rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $credentials['resource'], 'mcp');
        $credentials['previousTokens'] = $credentials['tokens'];
        $code = $service->consent($request, $uid) ?? throw new RuntimeException('Final token consent refused');
        $credentials['tokens'] = $service->exchangeCode(['code' => $code, 'code_verifier' => $verifier, 'client_id' => $credentials['clientId'], 'redirect_uri' => $credentials['redirect'], 'resource' => $credentials['resource']]);
        $credentials['issuedAt'] = time();
        $credentials['pendingCode'] = $service->consent($request, $uid) ?? throw new RuntimeException('Final consent refused');
        $store = Server::get(\OCA\Mcp\OAuth\OAuthStore::class); $hasher = Server::get(\OCA\Mcp\OAuth\TokenHasher::class);
        $token = $store->findByAccess($hasher->hash($credentials['tokens']['access_token'])) ?? throw new RuntimeException('Final token missing');
        $credentials['accessExpiresAt'] = (int)$token['access_expires'];
        $credentials['refreshExpiresAt'] = (int)$token['refresh_expires'];
        $credentials['codeExpiresAt'] = (int)$store->findCode($hasher->hash($credentials['pendingCode']))['expires_at'];
        $credentials['verifier'] = $verifier;
    } unset($credentials);
    $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-owner');
    if ($root->get($out['fileBackup'])->getContent() !== 'after B0') throw new RuntimeException('Final pre-write backup content differs');
    $out['baselineDav'] = $out['dav'];
    foreach (['VEVENT', 'VTODO'] as $type) {
        $row = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class)->getCalendarObject($out['dav'][$type]['id'], $out['dav'][$type]['uri']);
        if (!$row) throw new RuntimeException('Retained DAV object missing');
        $out['dav'][$type]['sha256'] = hash('sha256', $row['calendardata']);
    }
    $card = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class)->getCard($out['dav']['VCARD']['id'], $out['dav']['VCARD']['uri']);
    if ($card !== false) throw new RuntimeException('Deleted contact still exists');
    $out['dav']['VCARD']['deleted'] = true;
    $out['dav']['VCARD']['sha256'] = null;
    $out['current'] = snapshot();
    $out['baselineFiles'] = $out['files'];
    $out['files'] = $out['current']['files'];
    $out['appConfig'] = $out['current']['settings'] + ['installed_version' => $version];
    ksort($out['appConfig']);
    $out['policySnapshot'] = array_filter($out['current']['tables'], fn ($name) => str_starts_with($name, 'mcp_') || in_array($name, ['migrations', 'preferences'], true), ARRAY_FILTER_USE_KEY);
    $out['installedMcp'] = $version;
    savePrivate('/tmp/l3-private.json', $out);
} else throw new RuntimeException('Unknown L3 state mode');
$completion->complete();
echo 'MCP_L3_STATE=' . json_encode(['mode' => $mode, 'version' => $version, 'ok' => true], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'L3 ' . $mode . ': ' . get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1);
}
