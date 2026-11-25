<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$directory = __DIR__;
$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};
$read = static function (string $path) use ($fail): array {
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        $fail('Missing official-apps profile input: ' . basename($path));
    }
    try {
        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        $fail('Invalid official-apps profile JSON: ' . basename($path) . ': ' . $error->getMessage());
    }
    if (!is_array($value)) {
        $fail('Expected an object in ' . basename($path));
    }
    return $value;
};
$profile = $read($directory . '/official-apps-pins.json');
$expected = [
    'profile' => 'official-apps-nc35-pg16',
    'nc' => '35',
    'db' => 'pgsql',
    'mode' => 'fresh',
    'mail_guard' => '0',
    'mail_official' => true,
    'imap_image' => 'dovecot/dovecot:2.4.5@sha256:1b3041c09854aa53b6b57ac0e613fbfb9b859bbf976d808b1b12dba0444db4c6',
    'forms_version' => '5.4.4',
    'forms_sha256' => 'bc5a343c97d0888c0106ebc8646764176f5ed088d5b9bbf20985a7a481f6a9d9',
    'mail_version' => '5.13.4',
    'mail_sha256' => '2859a9020e06dd9d515cb7c55f76c7ac183ffdb5761c8ccff6ffb646bbbc925f',
];
foreach ($expected as $key => $value) {
    if (($profile[$key] ?? null) !== $value) {
        $fail('Official-apps profile has unexpected ' . $key);
    }
}
$matrix = $read($directory . '/matrix.json');
$cell = null;
foreach ($matrix['cells'] ?? [] as $candidate) {
    if (($candidate['nc'] ?? null) === '35' && ($candidate['db'] ?? null) === 'pgsql') {
        $cell = $candidate;
        break;
    }
}
if (!is_array($cell)
    || ($cell['nc_image'] ?? null) !== 'nextcloud:35.0.2-apache@sha256:c73d6b4ada5126dc56bbd6357f9c73c9caf99f4a692bd2da4187ea58b9089eec'
    || ($cell['db_image'] ?? null) !== 'postgres:16.15@sha256:b95b87e93ceb7594715d5c71fb6e09f6fc878851ab537735d617325eb9ba8b1b') {
    $fail('Official-apps profile must use the existing pinned NC35/PostgreSQL 16 cell');
}
$historicalForms = $read($directory . '/forms-pins.json');
$historicalMail = $read($directory . '/mail-pins.json');
if (($historicalForms['forms_version'] ?? null) !== '5.4.1' || ($historicalMail['mail_version'] ?? null) !== '5.12.5') {
    $fail('Official-apps pins must not replace historical release profiles');
}
foreach (['MCP_IT_OFFICIAL_APPS' => '1', 'MCP_IT_MAIL_OFFICIAL' => '1', 'MCP_IT_MAIL_GUARD' => '0'] as $key => $value) {
    putenv($key . '=' . $value);
}
$process = proc_open([PHP_BINARY, $directory . '/matrix.php', '35', 'pgsql', 'fresh', '--mail'], [
    0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) {
    $fail('Could not inspect the official-apps integration plan');
}
$planOutput = stream_get_contents($pipes[1]);
$planError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    $fail('Official-apps plan rejected its supported cell: ' . trim($planError));
}
try {
    $plan = json_decode($planOutput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    $fail('Official-apps plan did not return JSON: ' . $error->getMessage());
}
if (($plan['official_apps_profile'] ?? null) !== $profile['profile']
    || ($plan['mail']['mail_version'] ?? null) !== $profile['mail_version']
    || ($plan['mail']['mail_sha256'] ?? null) !== $profile['mail_sha256']
    || ($plan['forms']['forms_version'] ?? null) !== $profile['forms_version']
    || ($plan['forms']['forms_sha256'] ?? null) !== $profile['forms_sha256']) {
    $fail('Official-apps matrix plan did not select the dedicated archive pins');
}
echo "official-apps pins, plan selection, and historical profile separation verified\n";
