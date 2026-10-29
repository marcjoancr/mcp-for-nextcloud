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
function savePrivate(string $path, array $value): void {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0600)) throw new RuntimeException('Private state write failed');
}
function snapshot(): array {
    $db = Server::get(IDBConnection::class); $config = Server::get(IConfig::class);
    $names = $db->executeQuery("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND (table_name LIKE 'oc_mcp_%' OR table_name LIKE 'oc_deck_%' OR table_name LIKE 'oc_talk_%' OR table_name IN ('oc_migrations','oc_preferences','oc_calendars','oc_calendarobjects','oc_addressbooks','oc_cards')) ORDER BY table_name")->fetchFirstColumn();
    $columns = $db->executeQuery("SELECT table_name,column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema = 'public' AND (table_name LIKE 'oc_mcp_%' OR table_name IN ('oc_migrations','oc_preferences')) ORDER BY table_name,ordinal_position")->fetchAll();
    $tables = [];
    foreach ($names as $name) {
        if (!preg_match('/^oc_[a-z_]+$/D', $name)) throw new RuntimeException('Unexpected table name');
        $table = substr($name, 3); $qb = $db->getQueryBuilder(); $qb->select('*')->from($table);
        if ($table === 'migrations') $qb->where($qb->expr()->eq('app', $qb->createNamedParameter('mcp')));
        if ($table === 'preferences') $qb->where($qb->expr()->eq('appid', $qb->createNamedParameter('mcp')));
        $rows = $qb->executeQuery()->fetchAll();
        foreach ($rows as &$row) { ksort($row); foreach ($row as &$value) $value = databaseValue($value); unset($value); } unset($row);
        usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b))); $tables[$table] = $rows;
    }
    $settings = [];
    foreach ($config->getAppKeys('mcp') as $key) if ($key !== 'installed_version') $settings[$key] = $config->getAppValue('mcp', $key);
    ksort($settings);
    $files = [];
    $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-owner');
    $walk = function ($node) use (&$walk, &$files, $root): void {
        if ($node instanceof \OCP\Files\Folder) { foreach ($node->getDirectoryListing() as $child) $walk($child); }
        else $files[$root->getRelativePath($node->getPath())] = ['id' => $node->getId(), 'bytes' => strlen($node->getContent()), 'sha256' => hash('sha256', $node->getContent())];
    };
    $walk($root); ksort($files);
    return ['schema' => $columns, 'tables' => $tables, 'settings' => $settings, 'sourceOffer' => $config->getSystemValue('mcp_source_offer'), 'files' => $files];
}
try {
$config = Server::get(IConfig::class); $version = $config->getAppValue('mcp', 'installed_version');
if ($mode === 'before') {
    if ($version !== '1.2.0') throw new RuntimeException('Baseline required');
    savePrivate('/tmp/l3-before.json', snapshot());
} elseif (in_array($mode, ['after', 'persist-after'], true)) {
    if ($version !== '2.0.0') throw new RuntimeException('Candidate required');
    $before = json_decode(file_get_contents($mode === 'after' ? '/tmp/l3-before.json' : '/tmp/l3-persist.json'), true, 512, JSON_THROW_ON_ERROR);
    if (realpath(Server::get(\OCP\App\IAppManager::class)->getAppPath('mcp')) !== '/config/www/nextcloud/apps/mcp') throw new RuntimeException('Unexpected installed app path');
    assertSingleMcpTree(\OC::$APPSROOTS, '/config/www/nextcloud/apps/mcp');
    $actual = snapshot();
    foreach (['schema', 'tables', 'settings', 'sourceOffer', 'files'] as $kind) if ($before[$kind] !== $actual[$kind]) {
        $changed = array_keys(array_filter((array)$actual[$kind], fn ($value, $key) => ($before[$kind][$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        throw new RuntimeException('Persisted ' . $kind . ' changed: ' . implode(',', $changed));
    }
    if ($mode === 'after') {
        $policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        foreach (['l1-admin', 'l2-owner', 'l2-reader', 'l2-denied'] as $uid) if ($policy->granted($uid, 'mail', 'read')) throw new RuntimeException('Mail privilege expanded');
        if (array_key_exists('backup_settings', $actual['settings']) || Server::get(\OCA\Mcp\Access\BackupPolicy\BackupLocation::class)->current() !== \OCA\Mcp\Access\BackupPolicy\BackupLocation::defaults()) throw new RuntimeException('Backup defaults changed');
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
