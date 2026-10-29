<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require '/tmp/ProbeCompletion.php';
require '/tmp/tool-response.php';
require '/tmp/http-client.php';
require '/tmp/l3-probe-support.php';
umask(0077);
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
$fixture = json_decode(file_get_contents('/tmp/l3-private.json'), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? ''; $checks = [];
if ($mode === 'preserved') {
    schemaFor();
    foreach (['Basic', 'Bearer'] as $auth) {
        $initialize = rpc('l2-owner', $auth, 'initialize', ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'L3', 'version' => '1']]);
        assertion($initialize['status'] === 200 && ($initialize['body']['result']['serverInfo']['version'] ?? '') === '2.0.0', 'candidate-http-version-' . $auth);
        $denied = rpc('l2-denied', $auth, 'tools/list'); assertion(in_array($denied['status'], [401, 403], true), 'revoked-credential-still-denied-' . $auth);
        $read = callTool('files_read', ['path' => '/L2/changed.txt'], auth: $auth); assertion($read['text'] === 'after B0', 'pre-upgrade-mutation-preserved-' . $auth);
        foreach (['/L2/deleted.txt', '/L2/excluded.txt', '/outside.txt'] as $path) callTool('files_read', ['path' => $path], auth: $auth, denied: 'Resource not found in Nextcloud.');
        callTool('files_edit', ['path' => '/shared.txt', 'content' => 'blocked', 'confirm' => true, 'confirm_shared' => true], 'l2-reader', $auth, 'No access to this resource in Nextcloud.');
        callTool('files_create', ['path' => '/L2/forbidden.csv', 'content' => 'blocked', 'confirm' => true], auth: $auth, denied: 'This file type cannot be written through MCP. Ask an administrator to review the file type policy.');
        $note = callTool('notes_read', ['id' => $fixture['note']['id']], auth: $auth); assertion(str_contains(json_encode($note), 'Second paragraph'), 'seeded-note-preserved-' . $auth);
        $cardId = $fixture['deck']['created']['stacks'][0]['cards'][0]['id'];
        $card = callTool('deck_read_card', ['cardId' => $cardId], auth: $auth); assertion(str_contains(json_encode($card), 'L2 first'), 'seeded-card-preserved-' . $auth);
        callTool('deck_read_card_dependencies', ['cardId' => $cardId], auth: $auth);
        callTool('deck_list_card_files', ['cardId' => $cardId], auth: $auth);
        $messages = callTool('talk_read_messages', ['conversation_token' => $fixture['talk']['conversation_token']], auth: $auth);
        assertion(str_contains(json_encode($messages), 'L2 synthetic message'), 'seeded-talk-preserved-' . $auth);
    }
    assertion(callTool('files_read', ['path' => '/shared.txt'], 'l2-reader')['text'] === 'read only', 'denied-share-write-left-content-intact');
    callTool('files_read', ['path' => '/L2/forbidden.csv'], denied: 'Resource not found in Nextcloud.');
    $pending = $fixture['pendingPlans']['notes']; $before = callTool('notes_read', ['id' => $fixture['note']['id']]);
    $bad = callTool('notes_edit', $pending['arguments'] + ['plan_state' => $pending['plan']['plan_state'] . 'tampered', 'confirm' => true, 'confirm_shared' => true]);
    assertion(($bad['requiresConfirmation'] ?? false) === true && ($bad['plan_state'] ?? null) === $pending['plan']['plan_state'] && !empty($bad['warnings']), 'tampered-plan-requires-new-approval');
    assertion(callTool('notes_read', ['id' => $fixture['note']['id']]) === $before, 'tampered-plan-no-write');
    $result = callTool('notes_edit', $pending['arguments'] + ['plan_state' => $pending['plan']['plan_state'], 'confirm' => true, 'confirm_shared' => true]);
    assertion(($result['requiresConfirmation'] ?? false) === false, 'unchanged-baseline-plan-survives-version-change');
    assertion(str_contains(json_encode(callTool('notes_read', ['id' => $fixture['note']['id']])), 'Pending change'), 'confirmed-baseline-plan-readback');
    $link = ['cardId' => $fixture['deck']['created']['stacks'][0]['cards'][1]['id'], 'fileId' => $fixture['files']['changed.txt']['id']];
    writeTool('deck_link_card_file', $link);
    $files = callTool('deck_list_card_files', ['cardId' => $link['cardId']]);
    assertion(in_array($link['fileId'], array_column($files['items'], 'fileId'), true), 'candidate-native-card-link-readback');
    $fixture['upgradeDeckLink'] = $link;
} elseif (in_array($mode, ['source', 'disconnected', 'offer-missing', 'offer-mismatch'], true)) {
    $page = web('/index.php/settings/user/mcp', headers: userHeaders());
    assertion($page['status'] === 200 && str_contains($page['body'], 'mcp-source-offer'), 'personal-settings-route');
    if ($mode === 'offer-missing' || $mode === 'offer-mismatch') {
        assertion(str_contains($page['body'], 'Source delivery is unavailable.') && !str_contains($page['body'], 'https://nc.fixture.test/source/'), 'invalid-source-offer-not-advertised');
    } else {
        $manifest = json_decode(web('/source/manifest.json')['body'], true, 512, JSON_THROW_ON_ERROR);
        assertion(str_contains($page['body'], $manifest['sourceCommit']), 'personal-source-identity');
        foreach (['manifest.json', ...array_keys($manifest['files'])] as $name) assertion(str_contains($page['body'], 'https://nc.fixture.test/source/' . $name), 'personal-source-link-' . $name);
        if ($mode === 'source') {
            foreach (['Basic', 'Bearer'] as $auth) {
                $r = rpc('l2-owner', $auth, 'initialize', ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'L3', 'version' => '1']]);
                $instructions = $r['body']['result']['instructions'] ?? '';
                assertion($r['status'] === 200 && str_contains($instructions, $manifest['sourceCommit']), 'non-admin-mcp-source-' . $auth);
                $authorization = $auth === 'Basic' ? userHeaders()[0] : 'Authorization: Bearer ' . $fixture['users']['l2-owner']['tokens']['access_token'];
                $modern = web('/index.php/apps/mcp/', 'POST', [$authorization, 'Accept: application/json, text/event-stream', 'Content-Type: application/json', 'MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: server/discover'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]]));
                assertion($modern['status'] === 200, 'modern-http-status-' . $auth . '-' . $modern['status']);
                $discovery = json_decode($modern['body'], true, 512, JSON_THROW_ON_ERROR)['result']['instructions'] ?? '';
                assertion($modern['status'] === 200 && str_contains($discovery, $manifest['sourceCommit']), 'modern-source-discovery-' . $auth);
                foreach (['manifest.json', ...array_keys($manifest['files'])] as $name) {
                    $link = 'https://nc.fixture.test/source/' . $name;
                    assertion(str_contains($instructions, $link) && str_contains($discovery, $link), 'complete-mcp-source-link-' . $auth . '-' . $name);
                }
            }
        } else assertion(rpc('l2-owner', 'Basic', 'tools/list')['status'] === 403, 'disconnected-mcp-denied');
    }
    $anonymous = web('/index.php/apps/mcp/', 'POST', ['Accept: application/json, text/event-stream', 'Content-Type: application/json'], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
    assertion($anonymous['status'] === 401, 'anonymous-mcp-denied');
    foreach (['admin', 'personal'] as $asset) assertion(web('/apps/mcp/js/' . $asset . '.js')['status'] === 200, 'settings-asset-' . $asset);
} elseif ($mode === 'backup-configure') {
    $browser = curl_init(); curl_setopt($browser, CURLOPT_COOKIEFILE, '');
    $login = web('/index.php/login', session: $browser);
    assertion($login['status'] === 200 && preg_match('/data-requesttoken="([^"]+)"/', $login['body'], $match) === 1, 'login-csrf-token');
    $login = web('/index.php/login', 'POST', ['Origin: https://nc.fixture.test', 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['user' => 'admin', 'password' => $fixture['adminPassword'], 'requesttoken' => html_entity_decode($match[1], ENT_QUOTES)]), $browser);
    assertion($login['status'] === 303 && !preg_match('/^Location: .*login/mi', $login['headers']), 'real-admin-session');
    $token = json_decode(web('/index.php/csrftoken', session: $browser)['body'], true, 512, JSON_THROW_ON_ERROR)['token'];
    $headers = ['requesttoken: ' . $token, 'Content-Type: application/json'];
    assertion(web('/index.php/settings/admin/mcp', session: $browser)['status'] === 200, 'real-admin-settings');
    $initial = json_decode(web('/index.php/apps/mcp/api/backup-settings', headers: $headers, session: $browser)['body'], true, 512, JSON_THROW_ON_ERROR);
    $saved = web('/index.php/apps/mcp/api/backup-settings', 'PUT', $headers, json_encode(['expectedRevision' => $initial['revision'], 'folder' => 'L3 backups', 'copies' => true]), $browser);
    $config = json_decode($saved['body'], true, 512, JSON_THROW_ON_ERROR);
    assertion($saved['status'] === 200 && $config['folder'] === 'L3 backups' && in_array('MCP backups', $config['reserved'], true), 'revisioned-backup-configured');
    $fixture['backupSettings'] = $config;
    schemaFor();
    $written = writeTool('files_edit', ['path' => '/L2/changed.txt', 'content' => 'L3 confirmed content']);
    assertion(str_starts_with($written['backup'] ?? '', '/L3 backups/'), 'configured-file-backup-created');
    assertion(callTool('files_read', ['path' => '/L2/changed.txt'])['text'] === 'L3 confirmed content', 'file-write-readback');
    callTool('files_read', ['path' => $written['backup']], denied: 'Resource not found in Nextcloud.');
    $fixture['fileBackup'] = $written['backup'];
} elseif ($mode === 'permissions-denied') {
    schemaFor(); assertion(!isset($schema['users_search']) && !isset($schema['logs_list']), 'people-and-logs-not-advertised');
    foreach (['users_search' => ['query' => 'l2'], 'logs_list' => []] as $name => $args) {
        $r = rpc('l2-owner', 'Basic', 'tools/call', ['name' => $name, 'arguments' => (object)$args]);
        assertion($r['status'] === 200 && ($r['body']['error']['code'] ?? null) === -32602, 'hidden-tool-refused-' . $name);
    }
} elseif ($mode === 'permissions-allowed') {
    schemaFor(); callTool('users_search', ['query' => 'l2', 'limit' => 5]); callTool('logs_list', ['limit' => 5]);
} elseif ($mode === 'final') {
    $version = rpc('l2-owner', 'Basic', 'initialize', ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'L3 recreated', 'version' => '1']]);
    assertion($version['status'] === 200 && ($version['body']['result']['serverInfo']['version'] ?? null) === '2.0.0', 'recreated-http-version');
    schemaFor(); assertion(callTool('files_read', ['path' => '/L2/changed.txt'])['text'] === 'L3 confirmed content', 'recreated-file-content');
    callTool('files_read', ['path' => '/L2/excluded.txt'], denied: 'Resource not found in Nextcloud.');
    $link = $fixture['upgradeDeckLink'];
    $files = callTool('deck_list_card_files', ['cardId' => $link['cardId']]);
    assertion(in_array($link['fileId'], array_column($files['items'], 'fileId'), true), 'recreated-card-link-readback');
    $note = callTool('notes_read', ['id' => $fixture['note']['id']]);
    $fixture['noteAfterEdit'] = $note;
    $fixture['pendingPlans']['notes'] = ['arguments' => ['id' => $fixture['note']['id'], 'append' => 'B1 pending', 'etag' => $note['etag']], 'plan' => callTool('notes_edit', ['id' => $fixture['note']['id'], 'append' => 'B1 pending', 'etag' => $note['etag']])];
    $deck = $fixture['pendingPlans']['deck'];
    $fixture['pendingPlans']['deck']['plan'] = callTool('deck_edit_card_labels', $deck['arguments']);
    foreach ($fixture['pendingPlans'] as $plan) assertion(is_string($plan['plan']['plan_state'] ?? null), 'B1-pending-plan');
} else throw new RuntimeException('Unknown L3 HTTP mode');
$fixture['l3HttpChecks'][$mode] = $checks;
exportFixture($fixture, $mode, $checks); $completion->complete();
