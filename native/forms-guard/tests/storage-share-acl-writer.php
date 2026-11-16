<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $owner = $input['owner'] ?? '';
    $shareId = $input['share_id'] ?? '';
    $permissions = $input['permissions'] ?? null;
    if (!is_string($owner) || !preg_match('/^shared-destination-[a-f0-9]{10}$/D', $owner)
        || !is_string($shareId) || !str_starts_with($shareId, 'ocinternal:')
        || ($permissions !== null && !is_int($permissions))) {
        throw new RuntimeException('Invalid fixture ACL identity');
    }
    $users = \OCP\Server::get(\OCP\IUserManager::class);
    $user = $users->get($owner);
    if ($user === null) { throw new RuntimeException('Fixture share owner is unavailable'); }
    \OCP\Server::get(\OCP\IUserSession::class)->setUser($user);
    $manager = \OCP\Server::get(\OCP\Share\IManager::class);
    $share = $manager->getShareById($shareId, null, false);
    if ($share->getShareOwner() !== $owner || $share->getShareType() !== \OCP\Share\IShare::TYPE_USER) {
        throw new RuntimeException('Fixture share identity changed');
    }
    if ($permissions === null) { $manager->deleteShare($share); }
    else { $manager->updateShare($share->setPermissions($permissions)); }
    echo "shared ACL committed\n";
} catch (Throwable) {
    fwrite(STDERR, "shared ACL refused\n");
    exit(1);
}
