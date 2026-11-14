<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');$r=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->runInternal([$r['uid']],fn()=>null,function()use($r):bool{Server::get(\OCA\Mcp\Db\ToolGrantsMapper::class)->set('user',$r['uid'],'forms_continue_operation',$r['allow']?'allow':'deny');return true;});echo 'authority committed';
