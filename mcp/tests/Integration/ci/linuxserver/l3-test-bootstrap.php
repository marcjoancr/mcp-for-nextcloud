<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
if (getenv('MCP_LINUXSERVER_TESTS') !== '1') throw new RuntimeException('Explicit LinuxServer test profile required');
define('OC_CONSOLE', true);
putenv('NEXTCLOUD_ADMIN_PASSWORD=' . getenv('MCP_ADMIN_PASSWORD'));
require '/fixture-tests/mcp/tests/Integration/bootstrap.php';
if (posix_geteuid() !== 1000 || \OCP\Server::get(\OCP\IConfig::class)->getAppValue('mcp', 'installed_version') !== '2.0.0') throw new RuntimeException('Native candidate required');
