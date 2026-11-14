<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    require __DIR__.'/commit-fault.php';
    $fence=Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class);
    (new ReflectionProperty($fence,'db'))->setValue($fence,formsCommitFault(Server::get(\OCP\IDBConnection::class),'wait_session'));
    Server::get(\OCA\Forms\BackgroundJob\SyncSubmissionsWithLinkedFileJob::class)->run(['form_id'=>$input['form_id']]);
    echo json_encode(['ok'=>true,'null_session'=>Server::get(\OCP\IUserSession::class)->getUser()===null],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}
