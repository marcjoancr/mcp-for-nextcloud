<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
Server::get(\OCP\App\IAppManager::class)->loadApp('forms');Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
$uid='guard-owner';Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));require __DIR__.'/origin-fixture.php';
$input=json_decode(fgets(STDIN),true,64,JSON_THROW_ON_ERROR);$origin=formsNativeOrigin($uid,'forms_submit_response',$input);
try {\OCA\Forms\Mcp\Service\OriginContext::within($uid,$origin,'forms_submit_response',$input,fn()=>\OCA\Forms\Mcp\Service\FormsRevisionFence::run(function(){\OCA\Forms\Mcp\Service\OriginContext::assertFresh();fwrite(STDOUT,"CHECKED\n");fflush(STDOUT);fgets(STDIN);\OCA\Forms\Mcp\Service\OriginContext::assertFresh();}));echo json_encode(['refused'=>false]);}catch(Throwable $e){echo json_encode(['refused'=>$e->getMessage()==='Original Forms authority changed']);}
