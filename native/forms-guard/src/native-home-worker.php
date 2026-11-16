<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_string($argv[1]) || is_link($argv[1])) {
    exit(64);
}
$serverRoot = realpath($argv[1]);
if (!is_string($serverRoot) || !is_file($serverRoot . '/lib/base.php') || is_link($serverRoot . '/lib/base.php')) {
    exit(64);
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
ob_start();
try {
    require_once $serverRoot . '/lib/base.php';
    \OC_App::loadApp('forms');
    \OC_App::loadApp('mcp');
    $raw = stream_get_contents(STDIN, 1048577);
    if (!is_string($raw) || strlen($raw) > 1048576 || $raw === '') {
        throw new RuntimeException('Native Home request is unavailable');
    }
    $envelope = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $journal = fopen('php://fd/3', 'r+');
    if (!is_array($envelope) || !is_resource($journal)) {
        throw new RuntimeException('Native Home request is unavailable');
    }
    $receipt = \OCA\Forms\Mcp\Service\NativeHomeChild::run($envelope, $journal);
    fclose($journal);
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($receipt, JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    exit(1);
}
