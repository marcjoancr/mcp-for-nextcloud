<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCP\Server;
use OCA\Mcp\Db\{PolicyStateMapper, OAuthClientsMapper};
use OCA\Mcp\OAuth\{OAuthClientsService, CustomClient, AuthorizationRequest, TokenService};

require '/fixture-probes/ProbeCompletion.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
if (posix_geteuid() !== 1000 || Server::get(\OCP\IConfig::class)->getAppValue('mcp', 'installed_version') !== '1.2.0') throw new RuntimeException('L2 requires the pinned baseline as abc');
$private = '/tmp/l2-private.json';
$mode = $argv[1] ?? '';
$stage = $mode;
try {
$policy = Server::get('OCA\Mcp\Service\GrantPolicy');
$writer = Server::get('OCA\Mcp\Service\Policy\PolicyWriter');
$state = Server::get(PolicyStateMapper::class);
$config = Server::get(\OCP\IConfig::class);
$root = Server::get(\OCP\Files\IRootFolder::class);
if ($mode === 'seed') {
    $out = ['schemaVersion' => 1, 'baseline' => '1.2.0', 'users' => [], 'files' => [], 'dav' => []];
    $policy->setGlobalEnabled(true);
    foreach (['l2-owner', 'l2-reader', 'l2-denied'] as $uid) {
        $stage = 'create-user-' . $uid;
        $user = Server::get(\OCP\IUserManager::class)->createUser($uid, bin2hex(random_bytes(32)));
        if (!$user) throw new RuntimeException('User creation failed');
        $policy->setEligible($uid, true);
        $policy->setConnected($uid, true);
        $basic = bin2hex(random_bytes(36));
        Server::get(\OC\Authentication\Token\IProvider::class)->generateToken($basic, $uid, $uid, null, 'L2 synthetic fixture', \OCP\Authentication\Token\IToken::PERMANENT_TOKEN);
        $out['users'][$uid] = ['basic' => $basic];
        foreach (['files', 'notes', 'deck', 'talk', 'calendar', 'contacts', 'tasks'] as $module) {
            foreach ($policy::CATALOG[$module] as $operation) {
                if ($policy->inCatalog($module, $operation)) $policy->setGrant($uid, $module, $operation, true);
            }
        }
    }
    $policy->setEligible('l1-admin', true);
    $policy->setConnected('l1-admin', true);
    $group = Server::get(\OCP\IGroupManager::class)->createGroup('l2-allowed');
    $group->addUser(Server::get(\OCP\IUserManager::class)->get('l2-owner'));
    $groups = Server::get('OCA\Mcp\Service\Policy\GroupGrantsService');
    $grants = ['_system' => ['eligible'], 'files' => ['read']];
    $preview = $groups->preview($state->state()['revision'], 'l1-admin', 'l2-allowed', $grants);
    $groups->save($preview['revision'], 'l1-admin', 'l2-allowed', $grants, $preview['previewId']);
    $stage = 'create-files';
    $folder = $root->getUserFolder('l2-owner')->newFolder('L2');
    foreach (['empty.txt' => '', 'català-日本語.txt' => "Unicode baseline\n", 'changed.txt' => 'before change', 'deleted.txt' => 'before deletion', 'shared.txt' => 'read only', 'excluded.txt' => 'hidden sentinel', 'large.txt' => str_repeat('L2 payload\n', 600000)] as $name => $content) {
        $stage = 'file-' . $name;
        $file = $folder->newFile($name, $content);
        if ($file->getContent() !== $content) throw new RuntimeException('Seed content mismatch');
        $out['files'][$name] = ['id' => $file->getId(), 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
    }
    $stage = 'shares-policy';
    $shares = Server::get(\OCP\Share\IManager::class);
    $share = $shares->createShare($shares->newShare()->setNode($folder->get('shared.txt'))->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith('l2-reader')->setSharedBy('l2-owner')->setPermissions(\OCP\Constants::PERMISSION_READ));
    $out['shareId'] = $share->getId();
    $tag = Server::get(\OCP\SystemTag\ISystemTagManager::class)->createTag('L2 excluded', true, true);
    Server::get(\OCP\SystemTag\ISystemTagObjectMapper::class)->assignTags((string)$folder->get('excluded.txt')->getId(), 'files', [(string)$tag->getId()]);
    Server::get('OCA\Mcp\Service\VisibilityGuard')->setHiddenTagIds([$tag->getId()]);
    $out['excludedTag'] = $tag->getId();
    $root->getUserFolder('l2-owner')->newFile('outside.txt', 'out of scope');
    $writer->setResourceScope($state->state()['revision'], 'l1-admin', 'l2-owner', 'files', [(string)$folder->getId()]);
    Server::get('OCA\Mcp\Service\FileWritePolicy')->save('allowlist', ['txt', 'md'], false);
    $stage = 'dav';
    $cal = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
    foreach (['VEVENT', 'VTODO'] as $type) {
        $id = (int)$cal->createCalendar('principals/users/l2-owner', 'l2-' . strtolower($type), ['{DAV:}displayname' => 'L2 ' . $type,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet([$type])]);
        $dates = $type === 'VEVENT' ? "DTSTART:20261101T120000Z\r\nDTEND:20261101T130000Z\r\n" : "STATUS:NEEDS-ACTION\r\n";
        $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//L2//EN\r\nBEGIN:$type\r\nUID:l2-$type\r\nDTSTAMP:20261029T100000Z\r\nSUMMARY:L2 $type\r\n$dates" . "END:$type\r\nEND:VCALENDAR\r\n";
        $cal->createCalendarObject($id, 'fixture.ics', $body);
        $stored = $cal->getCalendarObject($id, 'fixture.ics');
        $out['dav'][$type] = ['id' => $id, 'uri' => 'fixture.ics', 'sha256' => hash('sha256', $stored['calendardata'])];
    }
    $dav = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
    $id = (int)$dav->createAddressBook('principals/users/l2-owner', 'l2-contacts', ['{DAV:}displayname' => 'L2 contacts']);
    $body = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:l2-contact\r\nFN:L2 synthetic contact\r\nEND:VCARD\r\n";
    $dav->createCard($id, 'fixture.vcf', $body);
    $out['dav']['VCARD'] = ['id' => $id, 'uri' => 'fixture.vcf', 'sha256' => hash('sha256', $dav->getCard($id, 'fixture.vcf')['carddata'])];
    Server::get(OAuthClientsService::class)->update($state->state()['revision'], null, null,
        [['name' => 'L2 fixture', 'enabled' => true, 'redirectUris' => ['https://client.invalid/callback']]]);
} else {
    $out = json_decode(file_get_contents($private), true, 512, JSON_THROW_ON_ERROR);
}
if (in_array($mode, ['seed', 'credentials'], true)) {
    $stage = 'oauth';
    $client = CustomClient::clients(Server::get(OAuthClientsMapper::class))[0];
    $service = Server::get(TokenService::class);
    foreach ($out['users'] as $uid => &$credentials) {
        if ($mode === 'credentials' && $uid === 'l2-denied') continue;
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $resource = 'https://nc.fixture.test/apps/mcp/';
        $request = new AuthorizationRequest(CustomClient::metadata($client), $client['redirectUris'][0], 'l2', $challenge, $resource, 'mcp');
        $issued = time();
        $code = $service->consent($request, $uid) ?? throw new RuntimeException('Consent refused');
        $credentials['tokens'] = $service->exchangeCode(['code' => $code, 'code_verifier' => $verifier, 'client_id' => CustomClient::clientId($client['id']), 'redirect_uri' => $client['redirectUris'][0], 'resource' => $resource]);
        $credentials['issuedAt'] = $issued;
        $store = Server::get(\OCA\Mcp\OAuth\OAuthStore::class);
        $hasher = Server::get(\OCA\Mcp\OAuth\TokenHasher::class);
        $row = $store->findByAccess($hasher->hash($credentials['tokens']['access_token'])) ?? throw new RuntimeException('Issued token missing');
        $credentials['accessExpiresAt'] = (int)$row['access_expires'];
        $credentials['refreshExpiresAt'] = (int)$row['refresh_expires'];
        $credentials['pendingCode'] = $service->consent($request, $uid) ?? throw new RuntimeException('Pending consent refused');
        $row = $store->findCode($hasher->hash($credentials['pendingCode'])) ?? throw new RuntimeException('Pending code missing');
        $credentials['codeExpiresAt'] = (int)$row['expires_at'];
        $credentials['verifier'] = $verifier;
        $credentials['clientId'] = CustomClient::clientId($client['id']);
        $credentials['redirect'] = $client['redirectUris'][0];
        $credentials['resource'] = $resource;
    }
    unset($credentials);
    // Retain a credential that native revocation must reject through both HTTP modes.
    $policy->setEligible('l2-denied', false);
    // Read the oracle after the final policy mutex acquisition, including a repeated deny.
    $out['appConfig'] = [];
    foreach ($config->getAppKeys('mcp') as $key) $out['appConfig'][$key] = $config->getAppValue('mcp', $key);
    ksort($out['appConfig']);
    $out['policySnapshot'] = [];
    $db = Server::get(\OCP\IDBConnection::class);
    foreach (['migrations', 'mcp_policy_state', 'mcp_auth_users', 'mcp_group_grants', 'mcp_group_life', 'mcp_scope_modes', 'mcp_scope_items', 'mcp_oauth_clients', 'mcp_oauth_codes', 'mcp_oauth_tokens', 'preferences'] as $table) {
        $qb = $db->getQueryBuilder();
        $qb->select('*')->from($table);
        if ($table === 'migrations') $qb->where($qb->expr()->eq('app', $qb->createNamedParameter('mcp')));
        if ($table === 'preferences') $qb->where($qb->expr()->eq('appid', $qb->createNamedParameter('mcp')));
        $rows = $qb->executeQuery()->fetchAll();
        foreach ($rows as &$row) ksort($row);
        unset($row);
        usort($rows, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        $out['policySnapshot'][$table] = $rows;
    }
} elseif ($mode === 'post-b0') {
    $folder = $root->getUserFolder('l2-owner')->get('L2');
    $folder->get('changed.txt')->putContent('after B0');
    $folder->get('deleted.txt')->delete();
    if ($folder->get('changed.txt')->getContent() !== 'after B0' || $folder->nodeExists('deleted.txt')) throw new RuntimeException('Post-B0 mutation failed');
    $out['postB0'] = ['changedSha256' => hash('sha256', 'after B0'), 'deleted' => true];
} else throw new RuntimeException('Unknown L2 seed mode');
$encoded = json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
if (file_put_contents($private, $encoded) !== strlen($encoded) || !chmod($private, 0600)) throw new RuntimeException('Private oracle write failed');
$completion->complete();
echo "MCP_L2_COMPLETE=$mode\n";

} catch (Throwable $error) {
    fwrite(STDERR, 'L2 seed failed at ' . $stage . ': ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    foreach (array_slice($error->getTrace(), 0, 8) as $frame) fwrite(STDERR, basename($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0) . ' ' . ($frame['function'] ?? '') . "\n");
    exit(1);
}
