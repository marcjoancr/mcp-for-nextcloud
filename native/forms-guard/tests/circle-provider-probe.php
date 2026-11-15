<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    $apps = Server::get(\OCP\App\IAppManager::class);
    $apps->loadApp('forms');
    $apps->loadApp('circles');
    $users = Server::get(\OCP\IUserManager::class);
    $uid = 'circle-owner-' . bin2hex(random_bytes(5));
    $user = $users->createUser($uid, bin2hex(random_bytes(32)));
    $otherId = 'circle-member-' . bin2hex(random_bytes(5));
    $other = $users->createUser($otherId, bin2hex(random_bytes(32)));
    Server::get(\OCP\IUserSession::class)->setUser($user);
    $circles = Server::get(\OCA\Circles\CirclesManager::class);
    $circles->startSession($circles->getLocalFederatedUser($uid), true);
    $circle = $circles->createCircle('Synthetic Forms Circle ' . bin2hex(random_bytes(5)), null, false, true, false);
    $member = $circles->addMember($circle->getSingleId(), $circles->getLocalFederatedUser($otherId));
    $circles->stopSession();
    $manager = Server::get(\OCP\Share\IManager::class);
    $folder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->newFolder('Native Circle control');
    $share = $manager->newShare();
    $share->setShareType(7)->setSharedWith($circle->getSingleId())->setShareOwner($uid)->setSharedBy($uid)->setNode($folder)->setPermissions(1);
    $created = $manager->createShare($share);
    $db = Server::get(\OCP\IDBConnection::class);
    $row = $db->executeQuery('SELECT id,share_type,share_with,uid_owner FROM *PREFIX*share WHERE id = ?', [(int)$created->getId()])->fetch();
    $checks = ['native_provider_create' => $created->getFullId() === 'ocCircleShare:' . $row['id'] && (int)$row['share_type'] === 7 && $row['uid_owner'] === $uid && $row['share_with'] === $circle->getSingleId()];
    $userShare = $manager->newShare();
    $userShare->setShareType(0)->setSharedWith($otherId)->setShareOwner($uid)->setSharedBy($uid)->setNode($folder)->setPermissions(1);
    $userShare = $manager->createShare($userShare);
    $userRow = $db->executeQuery('SELECT id,share_type,share_with,uid_owner FROM *PREFIX*share WHERE id = ?', [(int)$userShare->getId()])->fetch();
    $checks['mixed_native_pk_identity'] = $userShare->getFullId() === 'ocinternal:' . $userRow['id'] && (int)$userRow['share_type'] === 0 && $userRow['uid_owner'] === $uid && $userRow['share_with'] === $otherId && $userRow['id'] !== $row['id'];
    $manager->deleteShare($userShare);
    $created->setPermissions(17);
    $updated = $manager->updateShare($created);
    $checks['native_provider_update'] = $updated->getPermissions() === 17;
    $manager->deleteShare($updated);
    $checks['native_provider_revoke'] = $db->executeQuery('SELECT id FROM *PREFIX*share WHERE id = ?', [(int)$created->getId()])->fetchOne() === false;
    $scope = new \OCA\Forms\Mcp\Service\ScopedServices($uid, nativeOnly: true);
    $api = $scope->get(\OCA\Forms\Controller\ApiController::class);
    $shares = $scope->get(\OCA\Forms\Controller\ShareApiController::class);
    $id = $api->newForm()->getData()['id'];
    $native = $shares->newShare($id, 7, $circle->getSingleId(), ['submit'])->getData();
    $checks['native_circle_form_create'] = isset($native['id']);
    try {
        $shares->updateShare($id, $native['id'], ['permissions' => ['submit', 'results']]);
        $checks['native_circle_results'] = true;
    } catch (Throwable $e) {
        $checks['native_circle_results'] = false;
        $refusal = $e->getMessage();
    }
    Server::get(\OCP\IUserSession::class)->setUser($other);
    $deferred = new \OCA\Forms\Mcp\Service\ScopedServices($uid, deferred: true, nativeOnly: true);
    $checks['deferred_original_circle_membership'] = $deferred->get(\OCA\Forms\Service\CirclesService::class)->isUserInCircle($circle->getSingleId(), $uid);
    echo json_encode(['ok' => !in_array(false, $checks, true), 'checks' => $checks, 'provider' => ['full_id' => $created->getFullId(), 'table' => 'share', 'type' => $row['share_type'], 'other_full_id' => $userShare->getFullId(), 'canonical_rows_distinct' => $row['id'] !== $userRow['id']], 'refusal' => $refusal ?? null], JSON_THROW_ON_ERROR);
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error_class' => $e::class, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine(), 'checks' => $checks ?? []], JSON_THROW_ON_ERROR);
    exit(1);
}
