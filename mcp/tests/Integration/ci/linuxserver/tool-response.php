<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\LinuxServer;

final class ToolResponse {
    public static function staleNote(array $response, string $etag, ?string $freshPlan): void {
        if ($freshPlan === null) {
            self::result($response, 'The note changed since it was read; nothing was written. Current etag: ' . $etag);
            return;
        }
        $result = self::result($response);
        $data = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '', true);
        if (($data['requiresConfirmation'] ?? false) !== true || ($data['plan_state'] ?? null) !== $freshPlan) throw new \RuntimeException('Expected fresh note plan was not returned');
    }

    public static function result(array $response, ?string $denial = null): array {
        $body = $response['body'] ?? null;
        if (($response['status'] ?? null) !== 200 || !is_array($body)
            || ($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== 1
            || array_key_exists('error', $body) || !is_array($body['result'] ?? null)) {
            throw new \RuntimeException('Unexpected tool transport or RPC envelope');
        }
        $result = $body['result'];
        if ($denial !== null) {
            if (($result['isError'] ?? null) !== true
                || ($result['content'] ?? null) !== [['type' => 'text', 'text' => $denial]]) {
                throw new \RuntimeException('Expected policy denial was not returned');
            }
            return [];
        }
        if (($result['isError'] ?? false) !== false) throw new \RuntimeException('Unexpected tool failure');
        return $result;
    }
}
