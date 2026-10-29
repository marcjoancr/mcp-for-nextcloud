<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
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
