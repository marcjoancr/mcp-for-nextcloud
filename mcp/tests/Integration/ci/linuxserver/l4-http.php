<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require '/tmp/ProbeCompletion.php';
require '/tmp/tool-response.php';
require '/tmp/http-client.php';
require '/tmp/l3-probe-support.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
$fixture = json_decode(file_get_contents('/tmp/l4-private.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $margins = []; $mode = $argv[1] ?? '';
function live(array $user): void {
    global $margins;
    $expires = [$user['accessExpiresAt'], $user['refreshExpiresAt'], $user['codeExpiresAt']];
    assertion(!array_filter($expires, fn ($value) => !is_int($value) || $value - time() < 30), 'credentials-live-with-30-second-margin');
    $margins[] = min($expires) - time();
}
if ($mode === 'closed' || $mode === 'disconnected') {
    foreach (['Basic', 'Bearer'] as $auth) {
        $r = rpc('l2-owner', $auth, 'tools/list');
        assertion(in_array($r['status'], [401, 403], true), $mode . '-denied-' . $auth);
    }
} elseif ($mode === 'reconnected') {
    $fresh = $fixture['users'];
    foreach (['historicalUsers', 'postSnapshotUsers'] as $set) foreach ($fixture[$set] as $uid => $user) {
        if ($uid === 'l2-denied') continue;
        $fixture['users'][$uid] = $user;
        live($user); $r = rpc($uid, 'Bearer', 'tools/list'); live($user);
        assertion($r['status'] === 401, $set . '-bearer-rejected-' . $uid);
        foreach (['refresh_token', 'authorization_code'] as $grant) {
            $args = ['grant_type' => $grant, 'client_id' => $user['clientId'], 'resource' => $user['resource']];
            $args += $grant === 'refresh_token' ? ['refresh_token' => $user['tokens']['refresh_token']] : ['code' => $user['pendingCode'], 'code_verifier' => $user['verifier'], 'redirect_uri' => $user['redirect']];
            live($user);
            $r = web('/index.php/apps/mcp/oauth/token', 'POST', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($args));
            live($user);
            assertion($r['status'] === 400 && (json_decode($r['body'], true)['error'] ?? null) === 'invalid_grant', $set . '-' . $grant . '-rejected-' . $uid);
        }
    }
    $fixture['users'] = $fresh;
    $r = rpc('l2-owner', 'Bearer', 'tools/list');
    assertion($r['status'] === 200 && isset($r['body']['result']['tools']), 'fresh-oauth-admission');
    $schema = array_map(fn ($value) => $value['properties'] ?? [], array_column($r['body']['result']['tools'], 'inputSchema', 'name'));
    $read = callTool('files_read', ['path' => '/L2/català-日本語.txt'], auth: 'Bearer');
    assertion(str_contains(json_encode($read), 'Unicode baseline'), 'restored-unicode-content');
    callTool('files_read', ['path' => '/L2/excluded.txt'], auth: 'Bearer', denied: 'Resource not found in Nextcloud.');
    callTool('files_read', ['path' => '/outside.txt'], auth: 'Bearer', denied: 'Resource not found in Nextcloud.');
    callTool('files_create', ['path' => '/L2/forbidden.csv', 'content' => 'denied', 'confirm' => true], auth: 'Bearer', denied: 'This file type cannot be written through MCP. Ask an administrator to review the file type policy.');
    $reader = rpc('l2-reader', 'Basic', 'tools/list'); assertion(in_array($reader['status'], [401, 403], true), 'other-account-stays-disconnected');
    $pending = $fixture['pendingPlans']['notes'];
    $before = callTool('notes_read', ['id' => $fixture['note']['id']], auth: 'Bearer');
    $plan = callTool('notes_edit', $pending['arguments'], auth: 'Bearer');
    assertion(isset($plan['plan_state']) && $plan['plan_state'] === $pending['plan']['plan_state'], 'unchanged-stateless-fingerprint-can-match-after-recovery');
    $bad = callTool('notes_edit', $pending['arguments'] + ['plan_state' => $plan['plan_state'] . 'tampered', 'confirm' => true, 'confirm_shared' => true], auth: 'Bearer');
    assertion(($bad['requiresConfirmation'] ?? false) === true, 'tampered-confirmation-refused');
    assertion(callTool('notes_read', ['id' => $fixture['note']['id']], auth: 'Bearer') === $before, 'tampered-confirmation-no-write');
    $changedArgs = array_replace($pending['arguments'], ['append' => 'Changed arguments after recovery']);
    $bad = callTool('notes_edit', $changedArgs + ['plan_state' => $plan['plan_state'], 'confirm' => true, 'confirm_shared' => true], auth: 'Bearer');
    assertion(($bad['requiresConfirmation'] ?? false) === true, 'changed-arguments-refused');
    assertion(callTool('notes_read', ['id' => $fixture['note']['id']], auth: 'Bearer') === $before, 'changed-arguments-no-write');
    $newPlan = callTool('notes_edit', $changedArgs, auth: 'Bearer');
    $written = callTool('notes_edit', $changedArgs + ['plan_state' => $newPlan['plan_state'], 'confirm' => true, 'confirm_shared' => true], auth: 'Bearer');
    assertion(($written['requiresConfirmation'] ?? false) === false, 'fresh-confirmation-write');
    $after = callTool('notes_read', ['id' => $fixture['note']['id']], auth: 'Bearer');
    assertion($after !== $before, 'intervening-state-change-observed');
    $stale = rpc('l2-owner', 'Bearer', 'tools/call', ['name' => 'notes_edit', 'arguments' => $pending['arguments'] + ['plan_state' => $plan['plan_state'], 'confirm' => true, 'confirm_shared' => true]]);
    $freshPlan = null;
    if ($after['etag'] === $before['etag']) {
        $renewed = callTool('notes_edit', $pending['arguments'], auth: 'Bearer');
        assertion(isset($renewed['plan_state']) && $renewed['plan_state'] !== $plan['plan_state'], 'changed-state-has-new-fingerprint');
        $freshPlan = $renewed['plan_state'];
    }
    \OCA\Mcp\Tests\Integration\LinuxServer\ToolResponse::staleNote($stale, $after['etag'], $freshPlan);
    assertion(true, 'stale-confirmation-exact-refusal');
    assertion(callTool('notes_read', ['id' => $fixture['note']['id']], auth: 'Bearer') === $after, 'stale-confirmation-no-write');
} else throw new RuntimeException('Unknown L4 HTTP mode');
$completion->complete();
echo 'MCP_L4_HTTP=' . json_encode(['mode' => $mode, 'checks' => $checks, 'minimumCredentialMarginSeconds' => $margins ? min($margins) : null], JSON_THROW_ON_ERROR) . "\n";
