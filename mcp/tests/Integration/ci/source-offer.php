<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

// Run only through the disposable source acceptance runner, never against an operator's installation.
if (getenv('MCP_SOURCE_ACCEPTANCE') !== '1') { throw new RuntimeException('Disposable source acceptance marker required'); }
define('OC_CONSOLE', 1);
require_once dirname(__DIR__) . '/Support/ProbeCompletion.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
require dirname(__DIR__) . '/bootstrap.php';

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Protocol\ProtocolMetadata;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\Support\{Http, LoginFactory};
use OCP\Server;

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('Source acceptance failed: ' . $label); }
    $checks++;
}
try {
$config = Server::get(\OCP\IConfig::class);
$manifest = json_decode(file_get_contents('/tmp/mcp-source-fixture/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$digest = getenv('MCP_SOURCE_DIGEST');
check(is_string($digest) && hash_equals($digest, hash_file('sha256', '/tmp/mcp-source-fixture/manifest.json')), 'trusted manifest');
$offer = ['version' => $manifest['version'], 'sourceCommit' => $manifest['sourceCommit'],
    'manifestSha256' => $digest, 'baseUrl' => 'http://127.0.0.1:8765'];
$config->setSystemValue('mcp_source_offer', $offer);
$config->setSystemValue('cache_app_config', false);
$policy = Server::get(GrantPolicy::class);
$policy->setGlobalEnabled(true);
$policy->setEligible(Fixtures::ALICE, true);
$policy->setConnected(Fixtures::ALICE, true);
check(!Server::get(\OCP\IGroupManager::class)->isAdmin(Fixtures::ALICE), 'user is non-admin');
$basic = LoginFactory::basic(Fixtures::ALICE);
$bearer = LoginFactory::bearer(Fixtures::ALICE);
$headers = ['Authorization: ' . $basic->authorization(), 'Accept: text/html'];
sleep(3); // Allow the stock web PHP configuration timestamp cache to observe CLI config writes.
$page = Http::request('GET', Http::BASE . '/index.php/settings/user/mcp', $headers);
check($page['status'] === 200, 'real personal settings HTTP status');
check(str_contains($page['body'], 'id="mcp-source-offer"'), 'real source offer section');
check(str_contains($page['body'], $offer['sourceCommit']) && str_contains($page['body'], $digest), 'personal identity and digest');
check(str_contains($page['body'], 'Developed by Sintax'), 'attribution visible');
function discover(string $authorization, string $method): array {
    $params = $method === 'initialize'
        ? ['protocolVersion' => ProtocolMetadata::VERSION, 'clientInfo' => ['name' => 'source-acceptance', 'version' => '1']]
        : ['_meta' => [ProtocolMetadata::META_VERSION => ProtocolMetadata::MODERN_VERSION]];
    $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    if ($authorization !== '') { $headers[] = 'Authorization: ' . $authorization; }
    if ($method !== 'initialize') { $headers[] = 'MCP-Protocol-Version: ' . ProtocolMetadata::MODERN_VERSION; $headers[] = 'Mcp-Method: server/discover'; }
    return Http::request('POST', Http::BASE . '/index.php/apps/mcp/', $headers,
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
}
foreach ([false, true] as $filesGranted) {
    $policy->setGrant(Fixtures::ALICE, 'files', 'read', $filesGranted);
    foreach ([$basic, $bearer] as $credential) {
        foreach (['initialize', 'server/discover'] as $method) {
            $response = discover($credential->authorization(), $method);
            check($response['status'] === 200, 'authenticated discovery');
            $instructions = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['result']['instructions'] ?? '';
            check(str_contains($instructions, $offer['sourceCommit']) && str_contains($instructions, $digest), 'MCP identity and digest');
            foreach (['manifest.json', ...array_keys($manifest['files'])] as $file) {
                $url = $offer['baseUrl'] . '/' . $file;
                check(str_contains($instructions, $url), 'MCP complete source link');
                check(str_contains($page['body'], htmlspecialchars($url, ENT_QUOTES)), 'same personal source link');
            }
        }
    }
}
check(discover('', 'initialize')['status'] === 401, 'anonymous MCP refused');
mkdir('/tmp/mcp-source-retrieved', 0700);
foreach (['manifest.json', ...array_keys($manifest['files'])] as $file) {
    check((bool)preg_match('/\A[A-Za-z0-9.-]+\z/', $file), 'fixed artifact filename');
    $download = Http::request('GET', $offer['baseUrl'] . '/' . $file);
    check($download['status'] === 200, 'download without any credentials: ' . $file . ' HTTP ' . $download['status']);
    $expected = $file === 'manifest.json' ? $digest : $manifest['files'][$file]['sha256'];
    check(hash_equals($expected, hash('sha256', $download['body'])), 'download digest');
    file_put_contents('/tmp/mcp-source-retrieved/' . $file, $download['body']);
}
$policy->setConnected(Fixtures::ALICE, false);
check(discover($basic->authorization(), 'initialize')['status'] === 403, 'connection denial preserved');
$page = Http::request('GET', Http::BASE . '/index.php/settings/user/mcp', $headers);
check($page['status'] === 200 && str_contains($page['body'], $digest), 'personal source access does not require MCP connection');
foreach ([null, [...$offer, 'sourceCommit' => str_repeat('f', 40)]] as $invalid) {
    $config->setSystemValue('mcp_source_offer', $invalid);
    sleep(3);
    $page = Http::request('GET', Http::BASE . '/index.php/settings/user/mcp', $headers);
    check($page['status'] === 200 && str_contains($page['body'], 'Source delivery is unavailable.'), 'missing or wrong-revision offer unavailable');
    check(!str_contains($page['body'], $offer['baseUrl']), 'invalid destination not advertised');
}
$config->setSystemValue('mcp_source_offer', $offer);
$policy->setGlobalEnabled(false);
echo json_encode(['checks' => $checks, 'sourceCommit' => $manifest['sourceCommit'], 'version' => $manifest['version'],
    'manifestSha256' => $digest, 'nonAdmin' => true, 'authentication' => ['Basic', 'Bearer'], 'protocols' => ['initialize', 'server/discover']], JSON_THROW_ON_ERROR) . "\n";

$completion->complete();
} catch (\Throwable $error) {
    fwrite(STDERR, "Source acceptance: " . get_class($error) . ": " . $error->getMessage() . "\n");
    exit(1);
}
