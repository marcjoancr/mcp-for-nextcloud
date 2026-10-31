<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

const PRIVATE_ORACLE = '/tmp/protection-ui-private.json';
const NEXTCLOUD_BASE = 'https://nc.fixture.test';
const CA_FILE = '/tmp/ca.crt';

function failUiProbe(string $reason): never {
    if (!preg_match('/^[a-z-]+$/D', $reason)) $reason = 'probe-failed';
    fwrite(STDERR, 'MCP_PROTECTION_UI_ERROR=' . $reason . "\n");
    exit(1);
}

function request(CurlHandle $curl, string $path, string $method = 'GET', ?string $body = null, array $headers = []): array {
    curl_setopt_array($curl, [
        CURLOPT_URL => NEXTCLOUD_BASE . $path,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CAINFO => CA_FILE,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($curl);
    if (!is_string($response)) failUiProbe('http-transport');
    return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response];
}

function safeVersion(mixed $value): ?string {
    return is_string($value) && preg_match('/^[0-9A-Za-z.+-]{1,64}$/D', $value) ? $value : null;
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['control-stable', 'filtered-stable', 'control-beta', 'filtered-beta'], true)) failUiProbe('invalid-mode');
    $catalogBase = $argv[2] ?? '';
    $parsedBase = parse_url($catalogBase);
    if (!is_array($parsedBase) || ($parsedBase['scheme'] ?? null) !== 'http'
        || !filter_var($parsedBase['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || (int)($parsedBase['port'] ?? 0) !== 18765 || isset($parsedBase['user']) || isset($parsedBase['pass'])
        || isset($parsedBase['query']) || isset($parsedBase['fragment'])
        || (($parsedBase['path'] ?? '') !== '')) failUiProbe('invalid-catalog-base');

    if (!is_file(PRIVATE_ORACLE) || is_link(PRIVATE_ORACLE) || (fileperms(PRIVATE_ORACLE) & 0077) !== 0) failUiProbe('private-oracle-unavailable');
    $private = json_decode(file_get_contents(PRIVATE_ORACLE), true, 32, JSON_THROW_ON_ERROR);
    $password = $private['adminPassword'] ?? null;
    if (!is_string($password) || $password === '') failUiProbe('admin-password-unavailable');

    $isControl = str_starts_with($mode, 'control-');
    $isBeta = str_ends_with($mode, '-beta');

    $curl = curl_init();
    if (!$curl) failUiProbe('http-client-unavailable');
    curl_setopt($curl, CURLOPT_COOKIEFILE, '');
    $loginPage = request($curl, '/index.php/login');
    if ($loginPage['status'] !== 200 || !preg_match('/data-requesttoken="([^"]+)"/', $loginPage['body'], $token)) failUiProbe('login-form-unavailable');
    $requestToken = html_entity_decode($token[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $login = request($curl, '/index.php/login', 'POST', http_build_query([
        'user' => 'admin', 'password' => $password, 'requesttoken' => $requestToken,
    ]), ['Origin: ' . NEXTCLOUD_BASE, 'Content-Type: application/x-www-form-urlencoded']);
    unset($password, $private, $requestToken, $token);
    if ($login['status'] !== 303) failUiProbe('admin-login-rejected');

    // In Nextcloud 35, the appstore app's PageController owns this frontpage route.
    $page = request($curl, '/index.php/settings/apps');
    if ($page['status'] !== 200) failUiProbe('admin-app-page-rejected');
    // This is the OCS endpoint used by the NC35 appstore frontend's app-list service.
    $csrf = request($curl, '/index.php/csrftoken');
    $token = json_decode($csrf['body'], true, 16, JSON_THROW_ON_ERROR)['token'] ?? null;
    if ($csrf['status'] !== 200 || !is_string($token) || $token === '') failUiProbe('admin-session-token-unavailable');
    $listing = request($curl, '/ocs/v2.php/apps/appstore/api/v1/apps?details=true&format=json', headers: [
        'OCS-APIRequest: true', 'Accept: application/json', 'requesttoken: ' . $token,
    ]);
    if ($listing['status'] !== 200) failUiProbe('admin-app-list-rejected');
    $payload = json_decode($listing['body'], true, 512, JSON_THROW_ON_ERROR);
    $ocs = $payload['ocs'] ?? null;
    $apps = is_array($ocs) && ($ocs['meta']['statuscode'] ?? null) === 200 ? ($ocs['data'] ?? null) : null;
    if (!is_array($apps)) failUiProbe('admin-app-list-invalid');
    // ApiController::listApps returns an array of app rows, with the app ID
    // on each row. Do not recursively match arbitrary nested metadata.
    $row = null;
    foreach ($apps as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === 'mcp') {
            $row = $candidate;
            break;
        }
    }
    $update = safeVersion($row['update'] ?? null);
    $expectedUpdate = $isControl ? ($isBeta ? '2.2.0-beta.1' : '2.1.0') : null;
    $ok = $page['status'] === 200 && $listing['status'] === 200 && is_array($row)
        && ($expectedUpdate === null ? !isset($row['update']) : $update === $expectedUpdate)
        && isset($row['appstoreData']) === $isControl;
    $result = [
        'mode' => $mode,
        'uiPath' => '/index.php/settings/apps',
        'catalogApiPath' => '/ocs/v2.php/apps/appstore/api/v1/apps',
        'pageStatus' => $page['status'],
        'apiStatus' => (int)($ocs['meta']['statuscode'] ?? 0),
        'mcpRowPresent' => is_array($row),
        'catalogMetadataPresent' => isset($row['appstoreData']),
        'mcpCurrentVersion' => safeVersion($row['version'] ?? null),
        'mcpUpdateVersion' => $update,
        'ok' => $ok,
    ];
    echo 'MCP_PROTECTION_UI=' . json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    curl_close($curl);
    if (!$ok) exit(1);
} catch (Throwable) {
    failUiProbe('probe-failed');
}
