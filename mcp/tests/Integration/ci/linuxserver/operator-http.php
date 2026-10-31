<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$mcp = $argv[1] === 'mcp';
$h = curl_init('https://nc.fixture.test/' . ($mcp ? 'index.php/apps/mcp/' : 'status.php'));
curl_setopt_array($h, [CURLOPT_CAINFO => '/tmp/ca.crt', CURLOPT_TIMEOUT => 20, CURLOPT_RETURNTRANSFER => true]);
if ($mcp) curl_setopt_array($h, [CURLOPT_USERPWD => 'l2-owner:' . file_get_contents('/tmp/operator-basic'),
    CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Accept: application/json, text/event-stream', 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'operator-trial', 'version' => '1']]])]);
$body = curl_exec($h);
$status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
if ($mcp && $status !== 200) throw new RuntimeException('Unexpected MCP initialization status ' . $status);
echo $mcp ? json_decode($body, true, 64, JSON_THROW_ON_ERROR)['result']['serverInfo']['version'] : $status;
