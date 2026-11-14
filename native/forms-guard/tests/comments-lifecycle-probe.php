<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    $input=json_decode(stream_get_contents(STDIN),true)??[];
    $apps=Server::get(\OCP\App\IAppManager::class);
    if (($input['mode']??'')!=='early') {$apps->loadApp('forms');}
    if (($input['mode']??'')==='early') {
        // Force a real unavailable registration interval before first shared-service resolution.
        $db=Server::get(\OCP\IDBConnection::class);
        $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ? WHERE appid = ? AND configkey = ?',['no','forms','enabled']);
    }
    $manager=Server::get(\OCP\Comments\ICommentsManager::class);
    $comment=$manager->create('users','guard-owner','comments_probe','1');$comment->setMessage('ordinary')->setVerb('comment');
    $saved=$manager->save($comment);$comment->setMessage('edited');$edited=$manager->save($comment);$deleted=$manager->delete($comment->getId());
    if (($input['mode']??'')==='early') {
        $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ? WHERE appid = ? AND configkey = ?',['yes','forms','enabled']);$apps->loadApp('forms');
    }
    $caps=class_exists(\OCA\Forms\Mcp\Guard::class)?Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities():[];
    echo json_encode(['manager'=>$manager::class,'ordinary_crud'=>$saved&&$edited&&$deleted,'same_instance'=>$manager===Server::get(\OCP\Comments\ICommentsManager::class),'comments'=>$caps['comments']??false,'qualified'=>$caps['qualified']??false],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {echo json_encode(['error'=>$error->getMessage()]);exit(1);}
