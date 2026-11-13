<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($input['uid']));
    if (isset($input['create_folder'])) {
        $folder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($input['uid'])->newFolder($input['create_folder']);
        echo json_encode(['id' => $folder->getId()], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    $node = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($input['uid'])->getById($input['file_id'])[0];
    $manager = Server::get(\OCP\Share\IManager::class);
    $share = $manager->newShare();
    $share->setShareType(0);
    $share->setSharedWith($input['recipient']);
    $share->setSharedBy($input['uid']);
    $share->setShareOwner($input['uid']);
    $share->setPermissions(1);
    $share->setNode($node);
    echo json_encode(['id' => $manager->createShare($share)->getFullId()], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
