<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require '/tmp/ProbeCompletion.php';
require '/tmp/tool-response.php';
umask(0077);
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
$fixture = json_decode(file_get_contents('/tmp/l2-private.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = [];
function rpc(string $uid, string $auth, string $method, array $params = []): array {
    global $fixture;
    $credentials = $fixture['users'][$uid];
    $header = $auth === 'Basic' ? 'Basic ' . base64_encode($uid . ':' . $credentials['basic']) : 'Bearer ' . $credentials['tokens']['access_token'];
    $curl = curl_init('https://nc.fixture.test/index.php/apps/mcp/');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_CAINFO => '/tmp/ca.crt', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $header, 'Content-Type: application/json', 'Accept: application/json, text/event-stream'],
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object)$params], JSON_THROW_ON_ERROR)]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('Private HTTP transport failed: ' . curl_errno($curl));
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => json_decode($body, true)];
}
function assertion(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks[] = $name;
}
function callTool(string $name, array $args = [], string $uid = 'l2-owner', string $auth = 'Basic', ?string $denied = null): array {
    global $schema;
    assertion(isset($schema[$name]) && array_diff(array_keys($args), array_keys($schema[$name])) === [], $name . '-published-arguments');
    $response = rpc($uid, $auth, 'tools/call', ['name' => $name, 'arguments' => (object)$args]);
    $result = \OCA\Mcp\Tests\Integration\LinuxServer\ToolResponse::result($response, $denied);
    assertion(true, $name . ($denied ? '-denied-' : '-allowed-') . $uid . '-' . $auth);
    if ($denied) return [];
    if ($name === 'files_read') {
        assertion(count($result['content'] ?? []) === 2 && is_array(json_decode($result['content'][1]['text'] ?? '', true)), 'files-read-content-and-metadata');
        return ['text' => $result['content'][0]['text'], 'metadata' => json_decode($result['content'][1]['text'], true)];
    }
    $data = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '', true);
    assertion(is_array($data), $name . '-structured-result');
    return $data;
}
function writeTool(string $name, array $args): array {
    global $schema;
    $plan = callTool($name, $args);
    if (isset($plan['plan_state'])) $args['plan_state'] = $plan['plan_state'];
    if (isset($plan['etag'])) $args['etag'] ??= $plan['etag'];
    if (isset($schema[$name]['confirm_shared'])) $args['confirm_shared'] = true;
    $result = callTool($name, $args + ['confirm' => true]);
    assertion(($result['requiresConfirmation'] ?? false) === false, $name . '-write-complete');
    return $result;
}
foreach (['Basic', 'Bearer'] as $auth) {
    $list = rpc('l2-owner', $auth, 'tools/list');
    assertion($list['status'] === 200 && isset($list['body']['result']['tools']), 'allowed-admission-' . $auth);
    $schema = array_column($list['body']['result']['tools'], 'inputSchema', 'name');
    $schema = array_map(static fn ($value) => $value['properties'] ?? [], $schema);
    $denied = rpc('l2-denied', $auth, 'tools/list');
    assertion(in_array($denied['status'], [401, 403], true), 'denied-admission-' . $auth);
    $read = callTool('files_read', ['path' => '/L2/català-日本語.txt'], auth: $auth);
    assertion(str_contains(json_encode($read), 'Unicode baseline'), 'file-content-' . $auth);
    callTool('files_read', ['path' => '/L2/excluded.txt'], auth: $auth, denied: 'Resource not found in Nextcloud.');
    callTool('files_read', ['path' => '/outside.txt'], auth: $auth, denied: 'Resource not found in Nextcloud.');
    callTool('files_read', ['path' => '/shared.txt'], 'l2-reader', $auth);
    callTool('files_edit', ['path' => '/shared.txt', 'content' => 'forbidden', 'confirm' => true, 'confirm_shared' => true], 'l2-reader', $auth, 'No access to this resource in Nextcloud.');
    callTool('files_create', ['path' => '/L2/forbidden.csv', 'content' => 'denied', 'confirm' => true], auth: $auth, denied: 'This file type cannot be written through MCP. Ask an administrator to review the file type policy.');
}
$note = writeTool('notes_create', ['title' => 'L2 note', 'content' => "First paragraph\n"]);
$fixture['note'] = $note;
$note = writeTool('notes_edit', ['id' => $note['id'], 'append' => "Second paragraph\n", 'etag' => $note['etag']]);
$fixture['noteAfterEdit'] = callTool('notes_read', ['id' => $note['id']]);
assertion(str_contains(json_encode($fixture['noteAfterEdit']), 'Second paragraph'), 'notes-partial-edit-persisted');
$board = writeTool('deck_create_board', ['title' => 'L2 board', 'stacks' => [['title' => 'L2 stack', 'cards' => [['title' => 'L2 first', 'duedate' => '2026-11-01T12:00:00Z'], ['title' => 'L2 second']]]]]);
assertion(($board['complete'] ?? false) === true, 'deck-blueprint-complete');
$fixture['deck'] = $board;
$boardId = $board['created']['board']['id'];
$cards = $board['created']['stacks'][0]['cards'];
$labels = callTool('deck_list_labels', ['boardId' => $boardId]);
$label = $labels['labels'][0]['id'] ?? throw new RuntimeException('Missing native Deck label');
writeTool('deck_edit_card_labels', ['cardId' => $cards[0]['id'], 'add' => [$label]]);
writeTool('deck_add_card_dependency', ['cardId' => $cards[0]['id'], 'targetId' => $cards[1]['id']]);
writeTool('deck_link_card_file', ['cardId' => $cards[0]['id'], 'fileId' => $fixture['files']['shared.txt']['id']]);
$fixture['deckReadback'] = callTool('deck_read_card', ['cardId' => $cards[0]['id']]);
$fixture['deckDependencies'] = callTool('deck_read_card_dependencies', ['cardId' => $cards[0]['id']]);
$fixture['deckFiles'] = callTool('deck_list_card_files', ['cardId' => $cards[0]['id']]);
$room = writeTool('talk_create_group', ['name' => 'L2 conversation', 'participants' => ['l2-reader']]);
assertion(($room['invitations_failed'] ?? []) === [], 'talk-participants-complete');
$fixture['talk'] = $room;
writeTool('talk_reply', ['conversation_token' => $room['conversation_token'], 'message' => 'L2 synthetic message']);
$fixture['talkReadback'] = callTool('talk_read_messages', ['conversation_token' => $room['conversation_token']]);
assertion(str_contains(json_encode($fixture['talkReadback']), 'L2 synthetic message'), 'talk-message-persisted');
$fixture['pendingPlans'] = [
    'notes' => ['arguments' => ['id' => $note['id'], 'append' => 'Pending change', 'etag' => $note['etag']],
        'plan' => callTool('notes_edit', ['id' => $note['id'], 'append' => 'Pending change', 'etag' => $note['etag']])],
    'deck' => ['arguments' => ['cardId' => $cards[0]['id'], 'remove' => [$label]],
        'plan' => callTool('deck_edit_card_labels', ['cardId' => $cards[0]['id'], 'remove' => [$label]])],
];
foreach ($fixture['pendingPlans'] as $plan) assertion(isset($plan['plan']['plan_state']), 'pending-plan-state');
$fixture['httpChecks'] = $checks;
// Docker cp preserves the input UID; root may not overwrite that file in sticky /tmp.
// Export to a new private file and require a complete write before acknowledging success.
$encoded = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
$output = '/tmp/l2-http-private.json';
if (file_put_contents($output, $encoded) !== strlen($encoded) || !chmod($output, 0600)) throw new RuntimeException('HTTP oracle export failed');
$completion->complete();
echo 'MCP_L2_HTTP=' . json_encode(['checks' => $checks, 'oracleSha256' => hash('sha256', $encoded)], JSON_THROW_ON_ERROR) . "\n";
