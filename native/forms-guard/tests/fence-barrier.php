<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
$db=\OCP\Server::get(\OCP\IDBConnection::class);$db->beginTransaction();
try {$db->executeStatement('UPDATE *PREFIX*forms_mcp_fence SET revision = revision WHERE id = 1');fwrite(STDOUT,"LOCKED\n");fflush(STDOUT);fgets(STDIN);}finally{$db->rollBack();}
