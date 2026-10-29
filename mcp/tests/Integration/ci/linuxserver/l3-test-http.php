<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Support;
/** Explicit LinuxServer profile adapter; the Apache Http class is not changed or loaded. */
final class Http {
    public const BASE = 'https://nc.fixture.test';
    public static function request(string $method, string $url, array $headers = [], ?string $body = null): array {
        if (!str_starts_with($url, self::BASE . '/')) throw new \RuntimeException('Unexpected native test destination');
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CAINFO => '/config/tls/ca.crt', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($curl);
        if (!is_string($raw)) throw new \RuntimeException('Private native test transport failed');
        $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }
}
