<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use OCP\{Server, IConfig, IUserManager};
use OCA\Mcp\OAuth\{TokenService, OAuthStore, TokenHasher, CustomClient, AuthorizationRequest};
use OCA\Mcp\Db\{OAuthClientsMapper, PolicyStateMapper, AuthUsersMapper};
require '/fixture-probes/ProbeCompletion.php';
require '/fixture-probes/state-value.php';
require '/fixture-probes/state-snapshot.php';
require '/fixture-probes/recovery-effects.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
if (posix_geteuid() !== 1000) throw new RuntimeException('Fixture user required');
$mode = $argv[1] ?? '';
$baselineVersion = $argv[2] ?? '';
$candidateVersion = $argv[3] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/D', $baselineVersion) || !preg_match('/^\d+\.\d+\.\d+$/D', $candidateVersion)
    || $baselineVersion === $candidateVersion) throw new RuntimeException('Validated fixture versions required');
try {
$config = Server::get(IConfig::class); $version = $config->getAppValue('mcp', 'installed_version');
if (!in_array($version, [$baselineVersion, $candidateVersion], true)) throw new RuntimeException('Unexpected recovery version');
$policyClass = class_exists(\OCA\Mcp\Access\Grants\GrantPolicy::class)
    ? \OCA\Mcp\Access\Grants\GrantPolicy::class : 'OCA\Mcp\Service\GrantPolicy';
if (!class_exists($policyClass)) throw new RuntimeException('Recovery policy class unavailable');
$policy = Server::get($policyClass);
$private = '/tmp/l4-private.json';
$out = json_decode(file_get_contents($private), true, 512, JSON_THROW_ON_ERROR);
$uids = array_map(static fn ($user) => $user->getUID(), array_values(Server::get(IUserManager::class)->search(''))); sort($uids);
$restrictions = static function () use ($policy, $policyClass, $uids): array {
    $result = [];
    foreach ($uids as $uid) {
        $result[$uid] = ['eligible' => $policy->eligible($uid)];
        foreach ($policyClass::CATALOG as $module => $operations) {
            $result[$uid][$module] = ['scope' => $policy->scopeItems($uid, $module)];
            foreach ($operations as $op) $result[$uid][$module][$op] = $policy->granted($uid, $module, $op);
        }
    }
    return $result;
};
$issue = static function (array &$users): void {
    $service = Server::get(TokenService::class); $store = Server::get(OAuthStore::class); $hasher = Server::get(TokenHasher::class);
    $clients = array_values(array_filter(CustomClient::clients(Server::get(OAuthClientsMapper::class)), fn ($client) => $client['name'] === 'L2 fixture'));
    if (count($clients) !== 1) throw new RuntimeException('Ambiguous fixture OAuth client');
    $client = $clients[0];
    foreach ($users as $uid => &$credentials) {
        if ($uid === 'l2-denied') continue;
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request = new AuthorizationRequest(CustomClient::metadata($client), $credentials['redirect'], 'l4', rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $credentials['resource'], 'mcp');
        $code = $service->consent($request, $uid) ?? throw new RuntimeException('Recovery consent refused');
        $credentials['tokens'] = $service->exchangeCode(['code' => $code, 'code_verifier' => $verifier, 'client_id' => $credentials['clientId'], 'redirect_uri' => $credentials['redirect'], 'resource' => $credentials['resource']]);
        $credentials['pendingCode'] = $service->consent($request, $uid) ?? throw new RuntimeException('Recovery pending consent refused');
        $token = $store->findByAccess($hasher->hash($credentials['tokens']['access_token'])) ?? throw new RuntimeException('Recovery token missing');
        $credentials['accessExpiresAt'] = (int)$token['access_expires']; $credentials['refreshExpiresAt'] = (int)$token['refresh_expires'];
        $credentials['codeExpiresAt'] = (int)$store->findCode($hasher->hash($credentials['pendingCode']))['expires_at'];
        $credentials['verifier'] = $verifier; $credentials['issuedAt'] = time();
    } unset($credentials);
};
$details = ['mode' => $mode, 'version' => $version];
if ($mode === 'verify') {
    $actual = snapshot();
    if (isset($out['recoverySnapshot'])) {
        foreach ($out['recoverySnapshot'] as $kind => $expected) if ($expected !== $actual[$kind]) throw new RuntimeException('Restored case state differs: ' . $kind);
    } elseif (isset($out['current'])) {
        foreach ($out['current'] as $kind => $expected) if ($expected !== $actual[$kind]) throw new RuntimeException('Restored B1 state differs: ' . $kind);
    } else {
        foreach ($out['policySnapshot'] as $table => $rows) {
            foreach ($rows as &$row) { foreach ($row as &$value) $value = databaseValue($value); unset($value); } unset($row);
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            if ($rows !== $actual['tables'][$table]) throw new RuntimeException('Restored B0 table differs: ' . $table);
        }
        $settings = $out['appConfig']; unset($settings['installed_version']);
        if ($settings !== $actual['settings']) throw new RuntimeException('Restored B0 settings differ');
        foreach ($out['files'] as $name => $file) if (($actual['files']['/L2/' . $name] ?? null) !== $file) throw new RuntimeException('Restored B0 file differs');
        foreach (['VEVENT', 'VTODO'] as $type) {
            $entry = $out['dav'][$type]; $row = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class)->getCalendarObject($entry['id'], $entry['uri']);
            if (!$row || hash('sha256', $row['calendardata']) !== $entry['sha256']) throw new RuntimeException('Restored DAV object differs');
        }
        $entry = $out['dav']['VCARD']; $row = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class)->getCard($entry['id'], $entry['uri']);
        if (!$row || hash('sha256', $row['carddata']) !== $entry['sha256']) throw new RuntimeException('Restored contact differs');
    }
    if ($baselineVersion === '1.2.1') {
        $backup = $actual['settings']['backup_settings'] ?? null;
        $settings = json_decode((string)$backup, true, 16, JSON_THROW_ON_ERROR);
        $expectedFolder = $version === $baselineVersion ? 'Case Archive' : 'L3 backups';
        if (!is_array($settings) || ($settings['version'] ?? null) !== 1
            || ($settings['folder'] ?? null) !== $expectedFolder || ($settings['copies'] ?? null) !== true
            || !in_array('Case Archive', $settings['reserved'] ?? [], true)
            || !in_array($expectedFolder, $settings['reserved'] ?? [], true)
            || $config->getAppValue('mcp', 'backup_settings') !== $backup) {
            throw new RuntimeException('Restored 1.2.1 backup policy origin state differs');
        }
        $calendars = array_values(array_filter($actual['tables']['calendars'] ?? [], static fn (array $row): bool => ($row['uri'] ?? null) === 'l2-121-hierarchy'));
        if (count($calendars) !== 1) throw new RuntimeException('Restored 1.2.1 Tasks calendar missing');
        $calendarId = $calendars[0]['id'] ?? null;
        $tasks = array_values(array_filter($actual['tables']['calendarobjects'] ?? [], static fn (array $row): bool => (string)($row['calendarid'] ?? '') === (string)$calendarId));
        $parent = array_filter($tasks, static fn (array $row): bool => str_contains((string)($row['calendardata'] ?? ''), "UID:l2-121-parent\r\n")
            && str_contains((string)($row['calendardata'] ?? ''), "SUMMARY:1.2.1 baseline parent\r\n"));
        $child = array_filter($tasks, static fn (array $row): bool => str_contains((string)($row['calendardata'] ?? ''), "UID:l2-121-child\r\n")
            && str_contains((string)($row['calendardata'] ?? ''), "SUMMARY:1.2.1 baseline child\r\n")
            && str_contains((string)($row['calendardata'] ?? ''), 'RELATED-TO;RELTYPE=PARENT:l2-121-parent'));
        if (count($parent) !== 1 || count($child) !== 1) throw new RuntimeException('Restored 1.2.1 Tasks hierarchy origin state differs');
        $details['originState'] = ['baseline' => $baselineVersion, 'backupPolicy' => 'restored', 'taskHierarchy' => 'parent and child restored'];
    }
    if (!$config->getSystemValueBool('maintenance') || !$policy->globalEnabled() || !$policy->connected('l2-owner')) throw new RuntimeException('Pre-cut recovery state not restored');
    $details += ['tables' => count($actual['tables']), 'files' => count($actual['files']), 'preRevocationState' => 'matched'];
} elseif ($mode === 'refresh') {
    $before = snapshot(); $out['restrictions'] = $restrictions();
    $out['instanceSecretHash'] = hash('sha256', $config->getSystemValue('secret'));
    $issue($out['users']); $after = snapshot();
    foreach (['schema', 'settings', 'sourceOffer', 'files'] as $kind) if ($before[$kind] !== $after[$kind]) throw new RuntimeException('Credential refresh changed ' . $kind);
    foreach ($before['tables'] as $name => $rows) if (!in_array($name, ['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_auth_users', 'mcp_policy_state'], true) && $rows !== $after['tables'][$name]) throw new RuntimeException('Credential refresh changed business state');
    $out['recoverySnapshot'] = $after; $out['files'] = $after['files'];
    $out['policySnapshot'] = array_filter($after['tables'], fn ($name) => str_starts_with($name, 'mcp_') || in_array($name, ['migrations', 'preferences'], true), ARRAY_FILTER_USE_KEY);
    $out['dataFingerprint'] = $config->getSystemValue('data-fingerprint');
    $out['installedMcp'] = $version;
} elseif ($mode === 'mutate') {
    $issue($out['users']); $out['postSnapshotUsers'] = $out['users'];
    $folder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-owner');
    $changed = $folder->get('/L2/changed.txt'); $old = $changed->getContent(); $changed->putContent('L4 post-snapshot mutation');
    $delete = array_values(array_filter(array_keys($out['recoverySnapshot']['files']), fn ($name) => str_contains($name, '日本語') || str_contains($name, 'unicode')))[0] ?? null;
    if ($delete === null) {
        $delete = array_values(array_filter(array_keys($out['recoverySnapshot']['files']), fn ($name) => str_starts_with($name, '/L2/') && !str_ends_with($name, 'changed.txt')))[0];
    }
    $folder->get($delete)->delete();
    $entry = $out['dav']['VEVENT']; $dav = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class); $row = $dav->getCalendarObject($entry['id'], $entry['uri']);
    $calendar = \Sabre\VObject\Reader::read($row['calendardata']); $calendar->VEVENT->SUMMARY = 'L4 post-snapshot mutation';
    $desired = $calendar->serialize();
    $dav->updateCalendarObject($entry['id'], $entry['uri'], $desired);
    $readback = $dav->getCalendarObject($entry['id'], $entry['uri']);
    if (!$readback) throw new RuntimeException('Mutated DAV object missing');
    \OCA\Mcp\Tests\Integration\LinuxServer\RecoveryEffects::dav($row['calendardata'], $desired, $readback['calendardata']);
    $before = Server::get(AuthUsersMapper::class)->generations('l2-owner');
    Server::get(TokenService::class)->revokeUser('l2-owner');
    $after = Server::get(AuthUsersMapper::class)->generations('l2-owner');
    \OCA\Mcp\Tests\Integration\LinuxServer\RecoveryEffects::revocation($before, $after);
    if ($folder->nodeExists($delete) || $changed->getContent() === $old) throw new RuntimeException('Post-snapshot file mutation not observed');
    $details += ['changedFile' => true, 'deletedFile' => true, 'changedDavRecord' => true, 'ownerRevoked' => true,
        'davHashes' => ['before' => hash('sha256', $row['calendardata']), 'after' => hash('sha256', $readback['calendardata'])],
        'revocationCut' => ['issued' => $before['issue_seq'], 'before' => $before['revoked_below'], 'after' => $after['revoked_below']]];
} elseif ($mode === 'cut' || $mode === 'close') {
    if ($mode === 'cut' && (!$policy->globalEnabled() || !$config->getSystemValueBool('maintenance'))) throw new RuntimeException('Recovery cut must precede maintenance release');
    $epoch = Server::get(PolicyStateMapper::class)->state()['auth_epoch'];
    $generations = []; foreach ($uids as $uid) $generations[$uid] = Server::get(AuthUsersMapper::class)->generations($uid);
    $policy->setGlobalEnabled(false);
    foreach ($uids as $uid) $policy->setConnected($uid, false);
    $next = Server::get(PolicyStateMapper::class)->state()['auth_epoch'];
    if ($mode === 'cut' && (int)$next !== (int)$epoch + 1) throw new RuntimeException('Global epoch did not advance');
    foreach ($uids as $uid) {
        $now = Server::get(AuthUsersMapper::class)->generations($uid);
        if ($policy->connected($uid) || (int)$now['revoked_below'] < (int)$generations[$uid]['issue_seq']) throw new RuntimeException('Restored user generation not cut');
    }
    if ($restrictions() !== $out['restrictions'] || hash('sha256', $config->getSystemValue('secret')) !== $out['instanceSecretHash']) throw new RuntimeException('Recovery restrictions or instance secret changed');
    $details += ['accountsDisconnected' => count($uids), 'authEpochAdvanced' => $next !== $epoch, 'restrictions' => 'unchanged', 'instanceSecret' => 'unchanged'];
} elseif ($mode === 'enable') {
    $policy->setGlobalEnabled(true);
    foreach ($uids as $uid) if ($policy->connected($uid)) throw new RuntimeException('User unexpectedly reconnected');
} elseif ($mode === 'reconnect') {
    $policy->setConnected('l2-owner', true);
    $out['historicalUsers'] = $out['users'];
    $fresh = ['l2-owner' => $out['users']['l2-owner']]; $issue($fresh); $out['users']['l2-owner'] = $fresh['l2-owner'];
    if ($restrictions() !== $out['restrictions']) throw new RuntimeException('Reconnection expanded privileges');
    foreach ($uids as $uid) if ($policy->connected($uid) !== ($uid === 'l2-owner')) throw new RuntimeException('Unexpected reconnected account');
} elseif ($mode === 'fingerprint') {
    $fingerprint = $config->getSystemValue('data-fingerprint');
    if (!is_string($fingerprint) || $fingerprint === '' || $fingerprint === $out['dataFingerprint']) throw new RuntimeException('Recovery fingerprint not changed');
    $details['fingerprintChanged'] = true;
} else throw new RuntimeException('Unknown L4 state mode');
savePrivate($private, $out);
$completion->complete();
echo 'MCP_L4_STATE=' . json_encode($details, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'L4 ' . $mode . ': ' . get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1);
}
