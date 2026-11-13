<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
class UnknownShareStorage extends \OC\Files\Storage\Wrapper\Wrapper
{
}
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $case = $input['case'];
    $uid = 'guard-owner';
    $users = Server::get(\OCP\IUserManager::class);
    Server::get(\OCP\IUserSession::class)->setUser($users->get($uid));
    $recipient = 'guard-share-recipient';
    if ($users->get($recipient) === null) {
        $users->createUser($recipient, bin2hex(random_bytes(32)));
    }
    $shareType = 0;
    if (in_array($case, ['group', 'revoked_target_group'], true)) {
        $recipient = 'forms-share-group-' . bin2hex(random_bytes(6));
        $group = Server::get(\OCP\IGroupManager::class)->createGroup($recipient);
        $group->addUser($users->get('guard-share-recipient'));
        $shareType = 1;
    } elseif ($case === 'circle') {
        Server::get(\OCP\App\IAppManager::class)->loadApp('circles');
        $circles = Server::get(\OCA\Circles\CirclesManager::class);
        $circles->startSession($circles->getLocalFederatedUser($uid), true);
        $circle = $circles->createCircle('Forms sharing ' . bin2hex(random_bytes(6)), null, false, true, false);
        $circles->stopSession();
        $recipient = $circle->getSingleId();
        $shareType = 7;
    }
    $container = (new \OCA\Forms\AppInfo\Application())->getContainer();
    $api = $container->get(\OCA\Forms\Controller\ApiController::class);
    $shares = $container->get(\OCA\Forms\Controller\ShareApiController::class);
    $db = Server::get(\OCP\IDBConnection::class);
    if ($case === 'deleted_user_cleanup') {
        $owner = 'forms-share-deleted-' . bin2hex(random_bytes(6));
        $user = $users->createUser($owner, bin2hex(random_bytes(32)));
        Server::get(\OCP\IUserSession::class)->setUser($user);
        try {
            $scope = new \OCA\Forms\Mcp\Service\ScopedServices($owner);
            $ownApi = $scope->get(\OCA\Forms\Controller\ApiController::class);
            $ownShares = $scope->get(\OCA\Forms\Controller\ShareApiController::class);
            $formId = $ownApi->newForm()->getData()['id'];
            $nativeId = $ownShares->newShare($formId, 0, $recipient, ['submit'])->getData()['id'];
            $ownShares->updateShare($formId, $nativeId, ['permissions' => ['submit', 'results']]);
            $deleted = $user->delete();
            Server::get(\OCP\IUserSession::class)->setUser(null);
            $container->get(\OCA\Forms\BackgroundJob\UserDeletedJob::class)->run(['owner_id' => $owner]);
            $formGone = $db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$formId])->fetchOne() === false;
            $mapGone = $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$formId])->fetchOne() == 0;
        } finally {
            Server::get(\OCP\IUserSession::class)->setUser($users->get($uid));
        }
        echo json_encode(['ok' => true, 'result' => ['user_deleted' => $deleted, 'form_cleaned' => $formGone, 'mapping_cleaned' => $mapGone]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'wait_native_child') {
        require __DIR__ . '/commit-fault.php';
        $fence = Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class);
        (new ReflectionProperty($fence, 'db'))->setValue($fence, formsCommitFault($db, 'wait_session'));
        $refused = false;
        try {
            $shares->updateShare($input['id'], $input['share_id'], ['permissions' => ['submit', 'results']]);
        } catch (Throwable) {
            $refused = true;
        }
        echo json_encode(['refused' => $refused], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'mcp_disabled') {
        $config = Server::get(\OCP\IAppConfig::class);
        $old = $config->getValueString('mcp', 'enabled', 'no');
        $config->setValueString('mcp', 'enabled', 'no');
        try {
            $process = proc_open([PHP_BINARY, __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode(['case' => 'native', 'expect_mcp_disabled' => true]));
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('Native-only child failed ' . $err);
            }
            $result = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
            echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        } finally {
            $config->setValueString('mcp', 'enabled', $old);
        }
        exit;
    }
    if (($input['expect_mcp_disabled'] ?? false) && Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('mcp', $users->get($uid))) {
        throw new RuntimeException('Native-only fixture requires disabled MCP');
    }
    $id = $api->newForm()->getData()['id'];
    $api->updateForm($id, ['title' => 'Share phase ' . bin2hex(random_bytes(8))]);
    $shareId = $shares->newShare($id, $shareType, $recipient, ['submit'])->getData()['id'];
    $guard = $container->get(\OCA\Forms\Mcp\Guard::class);
    require __DIR__ . '/origin-fixture.php';
    $apply = function (string $action, array $args) use ($uid, $guard): array {
        $args['_mcp_origin'] = formsNativeOrigin($uid, $action, $args);
        $snapshot = $guard->snapshot($uid, $action, $args);
        $snapshot['shares'] = Server::get(\OCA\Forms\Service\FormsService::class)->getShares((int) $args['id']);
        $args['_mcp_origin'] = Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->capture($uid, $action, $args, $snapshot);
        $GLOBALS['share_operation_key'] = bin2hex(random_bytes(16));
        $GLOBALS['share_original_origin'] = $args['_mcp_origin'];
        return $guard->apply($uid, $action, $args, $snapshot['revision'], $GLOBALS['share_operation_key'], $snapshot['file_manifest']);
    };
    if (in_array($case, ['routing_email', 'routing_federated', 'routing_all', 'concurrent_folder'], true)) {
        require __DIR__ . '/share-phase-review.php';
        exit;
    }
    if (in_array($case, ['provider_create_revoke', 'provider_create_mutation', 'provider_delete_revoke'], true)) {
        require __DIR__ . '/share-phase-lock.php';
        $delete = $case === 'provider_delete_revoke';
        if ($delete) {
            $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
        }
        $eventClass = $delete ? \OCP\Share\Events\BeforeShareDeletedEvent::class : \OCP\Share\Events\BeforeShareCreatedEvent::class;
        $reached = false;
        Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener($eventClass, function ($event) use ($case, $uid, &$reached): void {
            $reached = true;
            if ($case === 'provider_create_mutation') {
                $event->getShare()->setSharedWith($uid);
                $event->getShare()->setPermissions(31);
            } else {
                shareAuthority($uid, 'files', false);
            }
        });
        try {
            $result = $delete ? $apply('forms_unshare', ['id' => $id, 'share_id' => $shareId]) : $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
        } finally {
            if ($case !== 'provider_create_mutation' && $reached) {
                shareAuthority($uid, 'files', true);
            }
        }
        $form = $container->get(\OCA\Forms\Db\FormMapper::class)->findById($id);
        $path = $container->get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        $folder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($path);
        $count = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE file_source = ?', [$folder->getId()])->fetchOne();
        echo json_encode(['ok' => true, 'result' => ['event_reached' => $reached, 'uncertain' => $result['phases']['files'] === 'native_files_shares_uncertain', 'core_effect_refused' => $count === ($delete ? 1 : 0)]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'wait_native') {
        $pg = $db->getDatabaseProvider(true) === 'postgres';
        $fence = Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class);
        $revision = $fence->revision();
        $pg ? $db->executeQuery('SELECT pg_advisory_lock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT GET_LOCK(?, 60)', ['forms_mcp_revision_v1'])->fetchOne();
        $process = proc_open([PHP_BINARY, __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['case' => 'wait_native_child', 'id' => $id, 'share_id' => $shareId]));
        fclose($pipes[0]);
        $ready = trim((string) fgets($pipes[1]));
        try {
            if ($ready !== 'WAITING') {
                throw new RuntimeException('Native session barrier was not reached');
            }
            $users->get($uid)->setEnabled(false);
        } finally {
            $pg ? $db->executeQuery('SELECT pg_advisory_unlock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT RELEASE_LOCK(?)', ['forms_mcp_revision_v1'])->fetchOne();
        }
        try {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('Native waiter failed ' . $error);
            }
            $result = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        } finally {
            $users->get($uid)->setEnabled(true);
        }
        echo json_encode(['ok' => true, 'result' => ['waited' => $ready === 'WAITING', 'refused' => $result['refused'], 'permissions_preserved' => Server::get(\OCA\Forms\Db\ShareMapper::class)->findById($shareId)->getPermissions() === ['submit'], 'revision_preserved' => $fence->revision() === $revision]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'source') {
        $path = (new ReflectionClass(\OC\Share20\Manager::class))->getFileName();
        $original = file_get_contents($path);
        $called = false;
        try {
            if (file_put_contents($path, $original . "\n") === false) {
                throw new RuntimeException('Pinned source fixture cannot be changed');
            }
            $unqualified = !$guard->capabilities()['qualified'];
            $refused = false;
            try {
                Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class)->transaction(function () use (&$called) {
                    $called = true;
                });
            } catch (RuntimeException) {
                $refused = true;
            }
        } finally {
            file_put_contents($path, $original);
        }
        echo json_encode(['ok' => true, 'result' => ['capabilities_closed' => $unqualified, 'fence_refused' => $refused, 'no_work' => !$called, 'source_restored' => $guard->capabilities()['qualified']]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'public_link_without_files_grant') {
        $link = $shares->newShare($id, 3, '', ['submit'])->getData()['id'];
        require __DIR__ . '/share-phase-lock.php';
        shareAuthority($uid, 'forms', false);
        shareAuthority($uid, 'files', false);
        try {
            $args = ['id' => $id, 'share_id' => $link, 'permissions' => ['submit']];
            $args['_mcp_origin'] = formsNativeOrigin($uid, 'forms_update_share', $args);
            $snapshot = $guard->snapshot($uid, 'forms_update_share', $args);
            $updated = $apply('forms_update_share', ['id' => $id, 'share_id' => $link, 'permissions' => ['submit']]);
            $removed = $apply('forms_unshare', ['id' => $id, 'share_id' => $link]);
            $result = ['no_file_impact' => $snapshot['file_manifest'] === [], 'no_core_events' => $snapshot['impact']['events'] === false, 'updated' => $updated['state'] === 'succeeded', 'removed' => $removed['state'] === 'succeeded'];
        } finally {
            shareAuthority($uid, 'forms', true);
            shareAuthority($uid, 'files', true);
        }
        echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if (in_array($case, ['lock_acquire_ack', 'foreign_session_lock'], true)) {
        $pg = $db->getDatabaseProvider(true) === 'postgres';
        $called = false;
        $refused = false;
        if ($case === 'foreign_session_lock') {
            $pg ? $db->executeQuery('SELECT pg_advisory_lock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT GET_LOCK(?, 60)', ['forms_mcp_revision_v1'])->fetchOne();
        }
        require __DIR__ . '/commit-fault.php';
        $fence = new \OCA\Forms\Mcp\Service\FormsRevisionFence($case === 'lock_acquire_ack' ? formsCommitFault($db, 'lock_ack') : $db);
        try {
            $fence->transaction(function () use (&$called) {
                $called = true;
            });
        } catch (Throwable) {
            $refused = true;
        }
        if ($case === 'foreign_session_lock') {
            $pg ? $db->executeQuery('SELECT pg_advisory_unlock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT RELEASE_LOCK(?)', ['forms_mcp_revision_v1'])->fetchOne();
        }
        $process = proc_open([PHP_BINARY, __DIR__ . '/share-phase-lock-state.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($err);
        }
        $state = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
        // Exact cleanup of this disposable connection also makes the expected RED repeatable.
        if (!$state['free']) {
            $pg ? $db->executeQuery('SELECT pg_advisory_unlock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT RELEASE_LOCK(?)', ['forms_mcp_revision_v1'])->fetchOne();
        }
        echo json_encode(['ok' => true, 'result' => ['refused' => $refused, 'no_work' => !$called, 'released' => $state['free']]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'schema_and_persistent') {
        $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM *PREFIX*migrations WHERE app = ? AND version = ?', ['forms', '050402Date20261113040000']);
            $schemaClosed = !$guard->capabilities()['qualified'];
        } finally {
            $db->rollBack();
        }
        $config = Server::get(\OCP\IConfig::class);
        $old = $config->getSystemValue('dbpersistent', false);
        try {
            $config->setSystemValue('dbpersistent', true);
            $persistentClosed = !$guard->capabilities()['qualified'];
        } finally {
            $config->setSystemValue('dbpersistent', $old);
        }
        echo json_encode(['ok' => true, 'result' => ['schema_closed' => $schemaClosed, 'persistent_closed' => $persistentClosed, 'restored' => $guard->capabilities()['qualified']]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'outer_transaction') {
        $fence = Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class);
        $before = $fence->revision();
        $refused = false;
        $db->beginTransaction();
        try {
            $fence->transaction(fn() => null);
        } catch (RuntimeException) {
            $refused = true;
        } finally {
            $db->rollBack();
        }
        $nested = $fence->transaction(fn() => $fence->transaction(fn() => 42));
        echo json_encode(['ok' => true, 'result' => ['foreign_refused' => $refused, 'native_nested' => $nested === 42, 'one_revision' => (int) $fence->revision() === (int) $before + 1]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if (in_array($case, ['unknown_storage', 'unmanaged_share', 'nonowner'], true)) {
        $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $before = $container->get(\OCA\Forms\Db\ShareMapper::class)->findById($shareId)->getPermissions();
        $refused = false;
        $preserved = true;
        if ($case === 'unknown_storage') {
            $mount = $root->getMountPoint();
            $original = $mount->getStorage();
            $mount->wrapStorage(fn($path, $storage) => new UnknownShareStorage(['storage' => $storage]));
            try {
                $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
            } catch (Throwable) {
                $refused = true;
            } finally {
                $mount->wrapStorage(fn() => $original);
            }
        } elseif ($case === 'nonowner') {
            Server::get(\OCP\IUserSession::class)->setUser($users->get($recipient));
            try {
                (new \OCA\Forms\Mcp\Service\ScopedServices($recipient))->get(\OCA\Forms\Controller\ShareApiController::class)->updateShare($id, $shareId, ['permissions' => ['submit', 'results']]);
            } catch (Throwable) {
                $refused = true;
            } finally {
                Server::get(\OCP\IUserSession::class)->setUser($users->get($uid));
            }
        } else {
            $shares->updateShare($id, $shareId, ['permissions' => ['submit', 'results']]);
            $shares->updateShare($id, $shareId, ['permissions' => ['submit']]);
            $form = $container->get(\OCA\Forms\Db\FormMapper::class)->findById($id);
            $path = $container->get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
            $folder = $root->get($path);
            $manager = Server::get(\OCP\Share\IManager::class);
            $external = $manager->newShare();
            $external->setShareType(0);
            $external->setSharedWith($recipient);
            $external->setSharedBy($uid);
            $external->setShareOwner($uid);
            $external->setNode($folder);
            $external->setPermissions(1);
            $external = $manager->createShare($external);
            try {
                $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
            } catch (Throwable) {
                $refused = true;
            }
            $preserved = $manager->getShareById($external->getFullId())->getFullId() === $external->getFullId();
        }
        $after = $container->get(\OCA\Forms\Db\ShareMapper::class)->findById($shareId)->getPermissions();
        echo json_encode(['ok' => true, 'result' => ['refused' => $refused, 'native_permissions_unchanged' => $before === $after, 'unrelated_preserved' => $preserved, 'not_adopted' => $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne() == 0]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if (in_array($case, ['with_file', 'delete_with_file'], true)) {
        $question = $api->newQuestion($id, 'file', null, 'Shared file')->getData()['id'];
        $uploadArgs = ['id' => $id, 'question_id' => $question];
        $uploadArgs['_mcp_origin'] = formsNativeOrigin($uid, 'forms_upload_response_files', $uploadArgs);
        $snapshot = $guard->snapshot($uid, 'forms_upload_response_files', $uploadArgs);
        $bytes = 'Shared response file';
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $staged = $guard->apply($uid, 'forms_upload_response_files', $uploadArgs, $snapshot['revision'], bin2hex(random_bytes(16)), $snapshot['file_manifest'], fn() => $guard->stageFiles($uid, $id, $question, '', [['name' => 'shared.txt', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'stream' => $stream]]));
        } finally {
            fclose($stream);
        }
        $apply('forms_submit_response', ['id' => $id, 'answers' => [$question => [['mcpHandle' => $staged['data']['files'][0]['handle']]]]]);
    }
    if ($case === 'new_target_excluded') {
        $form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
        $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $path = Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        $config = Server::get(\OCP\IConfig::class);
        $key = \OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY;
        $oldConfig = $config->getAppValue('mcp', $key, '[]');
        $tag = Server::get(\OCP\SystemTag\ISystemTagManager::class)->createTag('forms-share-exclusion-' . bin2hex(random_bytes(5)), true, true);
        $config->setAppValue('mcp', $key, json_encode([(string) $tag->getId()]));
        $viewNode = $root->nodeExists('Forms') ? $root->get('Forms') : $root->newFolder('Forms');
        $view = (new ReflectionProperty($viewNode, 'view'))->getValue($viewNode);
        $property = new ReflectionProperty($view, 'lockingProvider');
        $original = $property->getValue($view);
        $target = rtrim($root->getInternalPath(), '/') . '/' . $path;
        $lockKey = 'files/' . md5($root->getStorage()->getId() . '::' . trim($target, '/'));
        require __DIR__ . '/share-phase-lock.php';
        $locks = new ShareAfterLock($original, $lockKey, function () use ($root, $path, $uid, $tag): bool {
            if (!$root->nodeExists($path)) {
                return false;
            }
            $GLOBALS['share_exclusion'] = ['file_id' => $root->get($path)->getId(), 'tag_id' => $tag->getId()];
            shareAuthority($uid, 'exclusion', false);
            return true;
        });
        $property->setValue($view, $locks);
        try {
            $receipt = $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
        } finally {
            $property->setValue($view, $original);
            if ($locks->changed) {
                shareAuthority($uid, 'exclusion', true);
            }
            $config->setAppValue('mcp', $key, $oldConfig);
        }
        $count = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne();
        echo json_encode(['ok' => true, 'result' => ['tagged_actual_target' => $locks->completed, 'partial' => $receipt['state'] === 'partial', 'not_shared' => $count === 0, 'allocation_retained' => $root->nodeExists($path)]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if (str_starts_with($case, 'revoked_')) {
        $mode = substr($case, 8);
        $shares->updateShare($id, $shareId, ['permissions' => ['submit', 'results']]);
        $shares->updateShare($id, $shareId, ['permissions' => ['submit']]);
        $form = Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
        $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $path = Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        $folder = $root->get($path);
        $view = (new ReflectionProperty($folder, 'view'))->getValue($folder);
        $property = new ReflectionProperty($view, 'lockingProvider');
        $original = $property->getValue($view);
        $lockKey = 'files/' . md5($folder->getStorage()->getId() . '::' . trim($folder->getInternalPath(), '/'));
        require __DIR__ . '/share-phase-lock.php';
        if ($mode === 'target_group') {
            $GLOBALS['share_policy_fixture'] = ['group_id' => $recipient];
            Server::get(\OCP\IGroupManager::class)->groupExists($recipient);
        }
        if ($mode === 'expiration') {
            $config = Server::get(\OCP\IAppConfig::class);
            $oldExpiration = [];
            foreach (['shareapi_default_internal_expire_date' => 'no', 'shareapi_enforce_internal_expire_date' => 'no', 'shareapi_internal_expire_after_n_days' => '7'] as $key => $default) {
                $oldExpiration[$key] = $config->getValueString('core', $key, $default);
            }
            $GLOBALS['share_policy_fixture'] = ['old_expiration' => $oldExpiration];
            $config->setValueString('core', 'shareapi_default_internal_expire_date', 'no');
            Server::get(\OCP\Share\IManager::class)->shareApiInternalDefaultExpireDate();
        }
        $locks = new ShareAfterLock($original, $lockKey, fn() => shareAuthority($uid, $mode, false));
        $property->setValue($view, $locks);
        try {
            if (in_array($mode, ['native', 'expiration'], true)) {
                $refused = false;
                try {
                    $shares->updateShare($id, $shareId, ['permissions' => ['submit', 'results']]);
                } catch (RuntimeException) {
                    $refused = true;
                }
            } else {
                $receipt = $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
                $refused = $receipt['state'] === 'partial' && $receipt['phases']['files'] === 'native_files_shares_uncertain';
            }
        } finally {
            $property->setValue($view, $original);
            if ($locks->changed) {
                shareAuthority($uid, $mode, true);
            }
        }
        $count = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne();
        $actual = Server::get(\OCP\Share\IManager::class)->getSharesBy($uid, $shareType, $folder, false, -1);
        if ($mode === 'expiration') {
            $expiry = $actual[0]->getExpirationDate() ?? null;
            echo json_encode(['ok' => true, 'result' => ['changed_after_lock' => $locks->completed, 'one_share' => $count === 1 && count($actual) === 1, 'current_expiration' => $expiry?->format('Y-m-d') === (new DateTime('today'))->modify('+2 days')->format('Y-m-d'), 'global_cache_preserved' => !Server::get(\OCP\Share\IManager::class)->shareApiInternalDefaultExpireDate()]], JSON_THROW_ON_ERROR) . "\n";
            exit;
        }
        echo json_encode(['ok' => true, 'result' => ['revoked_after_lock' => $locks->completed, 'partial_or_native_error' => $refused, 'no_file_share' => $count === 0 && $actual === [], 'database_committed' => in_array('results', Server::get(\OCA\Forms\Db\ShareMapper::class)->findById($shareId)->getPermissions(), true)]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'circle') {
        $before = Server::get(\OCA\Forms\Db\ShareMapper::class)->findById($shareId)->getPermissions();
        $refused = false;
        try {
            $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
        } catch (Throwable) {
            $refused = true;
        }
        echo json_encode(['ok' => true, 'result' => ['refused' => $refused, 'unchanged' => $before === Server::get(\OCA\Forms\Db\ShareMapper::class)->findById($shareId)->getPermissions()]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    $externalId = null;
    if ($case === 'concurrent_tuple') {
        $injected = false;
        Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCP\Interaction\RestrictInteractionEvent::class, function ($event) use (&$injected, &$externalId, $uid, $recipient): void {
            if ($injected || !$event->action instanceof \OCP\Interaction\Actions\ShareAction) {
                return;
            }
            $injected = true;
            $node = $event->resources[0]->getNode();
            $process = proc_open([PHP_BINARY, __DIR__ . '/share-phase-external.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode(['uid' => $uid, 'recipient' => $recipient, 'file_id' => $node->getId()]));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new \Error('Concurrent native share failed ' . $error);
            }
            $externalId = json_decode($output, true, 16, JSON_THROW_ON_ERROR)['id'];
        });
    }
    $lost = false;
    if (in_array($case, ['lost_ack', 'lost_connection'], true)) {
        Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCP\Share\Events\ShareCreatedEvent::class, static function () use ($case, $db): void {
            if ($case === 'lost_connection') {
                $db->close();
                return;
            }
            throw new \Error('Injected post-persistence acknowledgement loss');
        });
    }
    if ($case === 'native') {
        $shares->updateShare($id, $shareId, ['permissions' => ['submit', 'results']]);
    } else {
        try {
            $outcome = $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
        } catch (Throwable $error) {
            if ($case !== 'lost_connection') {
                throw $error;
            }
            $lost = true;
            $outcome = $guard->lookup($uid, $GLOBALS['share_operation_key'], $GLOBALS['share_original_origin']);
        }
    }
    if (in_array($case, ['lost_ack', 'lost_connection'], true)) {
        $phase = $outcome['phases']['files'];
        $intents = $db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_share_intents WHERE operation_key = ?', [$outcome['operation_key']])->fetchAll();
        $managed = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne();
        $form = $container->get(\OCA\Forms\Db\FormMapper::class)->findById($id);
        $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $path = $container->get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        $folder = $root->get($path);
        $manager = Server::get(\OCP\Share\IManager::class);
        $before = $manager->getSharesBy($uid, $shareType, $folder, false, -1);
        Server::get(\OCA\Forms\Mcp\Service\ManagedSharePhase::class)->drain();
        $process = proc_open([PHP_BINARY, __DIR__ . '/share-phase-lookup.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['uid' => $uid, 'key' => $GLOBALS['share_operation_key'], 'origin' => $GLOBALS['share_original_origin']]));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Fresh lookup failed ' . $error);
        }
        $lookup = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        $after = $manager->getSharesBy($uid, $shareType, $folder, false, -1);
        echo json_encode(['ok' => true, 'result' => ['partial' => $outcome['state'] === 'partial', 'uncertain' => $phase === 'native_files_shares_uncertain', 'persisted' => count($before) === 1, 'not_adopted' => $managed === 0, 'not_replayed' => count($after) === 1, 'claimed' => $intents[0]['phase'] === 'uncertain', 'fresh_lookup' => $lookup['phase'] === 'native_files_shares_uncertain', 'session_released' => $lookup['released'], 'connection_loss_detected' => $case !== 'lost_connection' || $lost]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'concurrent_tuple') {
        $rows = $db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchAll();
        echo json_encode(['ok' => true, 'result' => ['raced' => $externalId !== null, 'partial' => $outcome['state'] === 'partial', 'not_adopted' => $rows === [], 'external_preserved' => Server::get(\OCP\Share\IManager::class)->getShareById($externalId)->getFullId() === $externalId]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    $rows = $db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchAll();
    $actual = count($rows) === 1 ? Server::get(\OCP\Share\IManager::class)->getShareById($rows[0]['files_share_id']) : null;
    $verified = $actual !== null && $actual->getSharedWith() === $recipient && $actual->getPermissions() === 1;
    if ($case === 'event_phases') {
        echo json_encode(['ok' => true, 'result' => ['files_verified' => $verified && $outcome['phases']['files'] === 'native_files_shares_verified', 'partial' => $outcome['state'] === 'partial', 'events_reported' => in_array(['kind' => 'files_share', 'phase' => 'attempted_unverified'], $outcome['phases']['events'], true)]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'already_removed_share') {
        Server::get(\OCP\Share\IManager::class)->deleteShare($actual);
        $args = ['id' => $id];
        $args['_mcp_origin'] = formsNativeOrigin($uid, 'forms_delete_form', $args);
        $preview = $guard->snapshot($uid, 'forms_delete_form', $args);
        $receipt = $apply('forms_delete_form', ['id' => $id]);
        echo json_encode(['ok' => true, 'result' => ['no_files_share_effect' => !$preview['file_manifest']['files_share'], 'mapping_cleaned' => $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne() == 0, 'form_gone' => $db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetchOne() === false, 'no_share_phase' => $receipt['phases']['files'] === 'not_applicable']], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'unresolved_existing_row') {
        $coreId = (int) $actual->getId();
        $manager = Server::get(\OCP\Share\IManager::class);
        $db->executeStatement('UPDATE *PREFIX*share SET share_type = 99 WHERE id = ?', [$coreId]);
        try {
            $unresolved = false;
            try {
                $manager->getShareById($actual->getFullId(), onlyValid: false);
            } catch (\OCP\Share\Exceptions\ShareNotFound) {
                $unresolved = true;
            }
            $absent = \OCA\Forms\Mcp\Service\ManagedShares::isAbsent($actual->getFullId());
            $rows = \OCA\Forms\Mcp\Service\FormsRevisionFence::run(fn() => \OCA\Forms\Mcp\Service\ManagedShares::existingRows($id, $shareId));
        } finally {
            $db->executeStatement('UPDATE *PREFIX*share SET share_type = 0 WHERE id = ?', [$coreId]);
        }
        echo json_encode(['ok' => true, 'result' => ['api_unresolved' => $unresolved, 'row_exists' => !$absent, 'mapping_retained' => count($rows) === 1]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'changed_share') {
        $manager = Server::get(\OCP\Share\IManager::class);
        $actual->setPermissions(3);
        $manager->updateShare($actual);
        $args = ['id' => $id];
        $args['_mcp_origin'] = formsNativeOrigin($uid, 'forms_delete_form', $args);
        $refused = false;
        try {
            $guard->snapshot($uid, 'forms_delete_form', $args);
        } catch (Throwable) {
            $refused = true;
        }
        echo json_encode(['ok' => true, 'result' => ['refused' => $refused, 'changed_share_preserved' => $manager->getShareById($actual->getFullId())->getPermissions() === 3, 'form_preserved' => $db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetchOne() !== false]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'orphan_then_delete') {
        $folder = $actual->getNode();
        $view = (new ReflectionProperty($folder, 'view'))->getValue($folder);
        $property = new ReflectionProperty($view, 'lockingProvider');
        $original = $property->getValue($view);
        $lockKey = 'files/' . md5($folder->getStorage()->getId() . '::' . trim($folder->getInternalPath(), '/'));
        require __DIR__ . '/share-phase-lock.php';
        $locks = new ShareAfterLock($original, $lockKey, fn() => shareAuthority($uid, 'files', false));
        $property->setValue($view, $locks);
        try {
            $partial = $apply('forms_unshare', ['id' => $id, 'share_id' => $shareId]);
        } finally {
            $property->setValue($view, $original);
            if ($locks->changed) {
                shareAuthority($uid, 'files', true);
            }
        }
        $args = ['id' => $id];
        $args['_mcp_origin'] = formsNativeOrigin($uid, 'forms_delete_form', $args);
        $preview = $guard->snapshot($uid, 'forms_delete_form', $args);
        $deleted = $apply('forms_delete_form', ['id' => $id]);
        echo json_encode(['ok' => true, 'result' => ['first_partial' => $partial['state'] === 'partial', 'captured_orphan' => $preview['file_manifest']['files_share'] && count($preview['file_manifest']['share_removals']) === 1, 'removed' => $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne() == 0, 'verified' => $deleted['phases']['files'] === 'native_files_shares_verified']], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if (in_array($case, ['delete_form', 'delete_with_file'], true)) {
        $deleted = $apply('forms_delete_form', ['id' => $id]);
        echo json_encode(['ok' => true, 'result' => ['removed' => $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne() == 0, 'verified' => $deleted['phases']['files'] === 'native_files_shares_verified', 'form_gone' => $db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetchOne() === false]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($case === 'native') {
        $shares->deleteShare($id, $shareId);
    } else {
        $deleted = $apply('forms_unshare', ['id' => $id, 'share_id' => $shareId]);
        $verified = $verified && $outcome['phases']['files'] === 'native_files_shares_verified' && $deleted['phases']['files'] === 'native_files_shares_verified';
    }
    $left = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?', [$id])->fetchOne();
    echo json_encode(['ok' => true, 'result' => ['verified' => $verified, 'removed' => $left === 0, 'form_exists' => $container->get(\OCA\Forms\Db\FormMapper::class)->findById($id)->getId() === $id]], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'class' => $error::class, 'previous' => $error->getPrevious()?->getMessage()], JSON_THROW_ON_ERROR) . "\n";
}
