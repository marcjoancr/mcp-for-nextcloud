<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
function web(string $path, string $method = 'GET', array $headers = [], ?string $body = null, ?CurlHandle $session = null): array {
    $curl = $session ?? curl_init();
    curl_setopt_array($curl, [CURLOPT_URL => 'https://nc.fixture.test' . $path, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        CURLOPT_CAINFO => '/tmp/ca.crt', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException('Private web transport failed');
    $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}
function userHeaders(string $uid = 'l2-owner'): array {
    global $fixture;
    return ['Authorization: Basic ' . base64_encode($uid . ':' . $fixture['users'][$uid]['basic'])];
}
function schemaFor(string $uid = 'l2-owner'): void {
    global $schema;
    $list = rpc($uid, 'Basic', 'tools/list');
    assertion($list['status'] === 200 && is_array($list['body']['result']['tools'] ?? null), 'candidate-tools-list');
    $schema = array_map(fn ($value) => $value['properties'] ?? [], array_column($list['body']['result']['tools'], 'inputSchema', 'name'));
    assertion(!array_filter(array_keys($schema), fn ($name) => str_starts_with($name, 'mail_')), 'mail-absent');
}
function exportFixture(array $fixture, string $mode, array $checks): void {
    $json = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents('/tmp/l3-http-output.json', $json) !== strlen($json) || !chmod('/tmp/l3-http-output.json', 0600)) throw new RuntimeException('L3 HTTP oracle export failed');
    echo 'MCP_L3_HTTP=' . json_encode(['mode' => $mode, 'checks' => $checks, 'oracleSha256' => hash('sha256', $json)], JSON_THROW_ON_ERROR) . "\n";
}
