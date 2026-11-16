<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Guard;
use OCA\Forms\Mcp\Service\StorageAdmission;
class UnqualifiedStorageWrapper extends \OC\Files\Storage\Wrapper\Wrapper {}
class UnqualifiedHomeStorage extends \OC\Files\Storage\Home {}
/** Test-only interceptor delegates every lock to the real provider, then commits a scope change in another process. */
final class ScopeAfterLock implements \OCP\Lock\ILockingProvider {
    public bool $revoked = false;
    public function __construct(private \OCP\Lock\ILockingProvider $inner, private ?string $key, private Closure $revoke) {}
    public function isLocked(string $path, int $type): bool {return $this->inner->isLocked($path, $type);}
    public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
        $this->inner->acquireLock($path, $type, $readablePath);
        $this->after($path, $type);
    }
    public function changeLock(string $path, int $targetType): void {
        $this->inner->changeLock($path, $targetType);
        $this->after($path, $targetType);
    }
    private function after(string $path, int $type): void {
        if (!$this->revoked && $type === self::LOCK_EXCLUSIVE && ($this->key === null || $path === $this->key)) {
            if (!$this->inner->isLocked($path, $type)) {throw new RuntimeException('Test requires a real exclusive lock');}
            ($this->revoke)(); $this->revoked = true;
        }
    }
    public function releaseLock(string $path, int $type): void {$this->inner->releaseLock($path, $type);}
    public function releaseAll(): void {$this->inner->releaseAll();}
}
function commitStorageScope(string $uid, bool $deny): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/storage-scope-writer.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['uid' => $uid, 'deny' => $deny])); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($output) !== 'scope committed') {throw new RuntimeException('Scope writer failed: ' . $error);}
}
function commitSharedAcl(string $owner, string $shareId, ?int $permissions): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/storage-share-acl-writer.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['owner' => $owner, 'share_id' => $shareId, 'permissions' => $permissions])); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($output) !== 'shared ACL committed') {throw new RuntimeException('Shared ACL writer failed: ' . $error);}
}

try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $uid = $request['uid'] ?? 'guard-owner';
    $user = Server::get(\OCP\IUserManager::class)->get($uid);
    Server::get(\OCP\IUserSession::class)->setUser($user);
    require __DIR__ . '/origin-fixture.php';
    $container = (new \OCA\Forms\AppInfo\Application())->getContainer();
    $api = $container->get(\OCA\Forms\Controller\ApiController::class);
    $guard = $container->get(Guard::class);
    $roots = Server::get(\OCP\Files\IRootFolder::class);
    $root = $roots->getUserFolder($uid);
    $db = Server::get(\OCP\IDBConnection::class);
    $config = Server::get(\OCP\IConfig::class);
    $execute = function (string $action, array $input) use ($guard, $uid): array {
        $input['_mcp_origin'] = formsNativeOrigin($uid, $action, $input);
        $snapshot = $guard->snapshot($uid, $action, $input);
        return $guard->apply($uid, $action, $input, $snapshot['revision'], bin2hex(random_bytes(16)), $snapshot['file_manifest']);
    };
    $id = $api->newForm()->getData()['id'];
    $api->updateForm($id, ['title' => 'Storage admission ' . bin2hex(random_bytes(6)), 'submitMultiple' => true]);
    $question = $api->newQuestion($id, 'file', null, 'Proof')->getData()['id'];
    $input = ['id' => $id, 'question_id' => $question];
    $input['_mcp_origin'] = formsNativeOrigin($uid, 'forms_upload_response_files', $input);
    $snapshot = $guard->snapshot($uid, 'forms_upload_response_files', $input);
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, 'immutable storage proof'); rewind($stream);
    $stage = $guard->apply($uid, 'forms_upload_response_files', $input, $snapshot['revision'], bin2hex(random_bytes(16)), $snapshot['file_manifest'],
        fn () => $guard->stageFiles($uid, $id, $question, '', [['name' => 'proof.txt', 'size' => 23, 'sha256' => hash('sha256', 'immutable storage proof'), 'stream' => $stream]]));
    if (is_resource($stream)) {fclose($stream);}
    $submission = $execute('forms_submit_response', ['id' => $id, 'answers' => [$question => [['mcpHandle' => $stage['data']['files'][0]['handle']]]]]);
    $submissionId = $submission['references']['submission_id'];
    $fileId = $guard->read($uid, 'forms_list_response_files', ['id' => $id, 'submission_id' => $submissionId])['files'][0]['file_id'];
    $file = $root->getById($fileId)[0];
    $expected = hash('sha256', $file->getContent());
    if ($request['case'] === 'public_link_without_files') {
        $share = $container->get(\OCA\Forms\Controller\ShareApiController::class)->newShare($id, 3, '', ['submit'])->getData();
        $mount = $file->getMountPoint(); $original = $mount->getStorage();
        $mount->wrapStorage(fn ($path, $storage) => new UnqualifiedStorageWrapper(['storage' => $storage]));
        try {$removed = $execute('forms_unshare', ['id' => $id, 'share_id' => $share['id']]);}
        finally {$mount->wrapStorage(fn () => $original);}
        $result = ['public_link_removed' => !$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_shares WHERE id = ?', [$share['id']])->fetchOne(),
            'files_preserved' => hash('sha256', $root->getById($fileId)[0]->getContent()) === $expected,
            'no_recovery_allocated' => !isset($removed['references']['backup_id'])];
    } elseif ($request['case'] === 'post_lock_upload_source') {
        $source = $root->newFile('scope-source-' . bin2hex(random_bytes(6)) . '.txt', 'immutable storage proof');
        $relative = '/' . $root->getRelativePath($source->getPath());
        $sourceMeta = ['path' => $relative, 'file_id' => (int)$source->getId(), 'name' => $source->getName(), 'size' => 23,
            'etag' => $source->getEtag(), 'mime' => $source->getMimetype(), 'sha256' => hash('sha256', 'immutable storage proof')];
        $action = 'forms_upload_response_files';
        $input = ['id' => $id, 'question_id' => $question, 'paths' => [$relative], '_mcp_sources' => [$sourceMeta]];
        $input['_mcp_origin'] = formsNativeOrigin($uid, $action, $input);
        $approved = $guard->snapshot($uid, $action, $input);
        $count = (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ?', [$id])->fetchOne();
        $sourceStream = $source->fopen('rb');
        $view = (new ReflectionProperty($source, 'view'))->getValue($source);
        $property = new ReflectionProperty($view, 'lockingProvider');
        $originalLocks = $property->getValue($view);
        $locks = new ScopeAfterLock($originalLocks, null, fn () => commitStorageScope($uid, true));
        $property->setValue($view, $locks);
        $failed = false;
        try {
            $guard->apply($uid, $action, $input, $approved['revision'], bin2hex(random_bytes(16)), $approved['file_manifest'],
                fn () => $guard->stageFiles($uid, $id, $question, '', [$sourceMeta + ['stream' => $sourceStream]]));
        } catch (Throwable) {$failed = true;}
        finally {
            $property->setValue($view, $originalLocks);
            if (is_resource($sourceStream)) {fclose($sourceStream);}
            if ($locks->revoked) {commitStorageScope($uid, false);}
        }
        $result = ['real_exclusive_lock_acquired' => $locks->revoked, 'staging_refused' => $failed,
            'source_preserved' => hash('sha256', $source->getContent()) === $sourceMeta['sha256'],
            'no_upload_row' => $count === (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ?', [$id])->fetchOne()];
    } elseif ($request['case'] === 'post_lock_scope') {
        $action = 'forms_delete_form';
        $input = ['id' => $id, '_mcp_origin' => formsNativeOrigin($uid, $action, ['id' => $id])];
        $approved = $guard->snapshot($uid, $action, $input);
        $view = (new ReflectionProperty($file, 'view'))->getValue($file);
        $property = new ReflectionProperty($view, 'lockingProvider');
        $originalLocks = $property->getValue($view);
        $lockKey = 'files/' . md5($file->getStorage()->getId() . '::' . trim($file->getInternalPath(), '/'));
        $locks = new ScopeAfterLock($originalLocks, $lockKey, fn () => commitStorageScope($uid, true));
        $property->setValue($view, $locks);
        $failed = false;
        try {
            $guard->apply($uid, $action, $input, $approved['revision'], bin2hex(random_bytes(16)), $approved['file_manifest']);
        } catch (Throwable) {$failed = true;}
        finally {
            $property->setValue($view, $originalLocks);
            if ($locks->revoked) {commitStorageScope($uid, false);}
        }
        $nodes = $root->getById($fileId);
        $result = ['real_exclusive_lock_acquired' => $locks->revoked, 'mutation_refused' => $failed,
            'bytes_preserved' => $nodes !== [] && hash('sha256', $nodes[0]->getContent()) === $expected,
            'native_form_preserved' => (bool)$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetchOne()];
    } elseif ($request['case'] === 'linked_shared_destination_acl') {
        $sourceOwner = 'shared-destination-' . bin2hex(random_bytes(5));
        $sourceUser = Server::get(\OCP\IUserManager::class)->createUser($sourceOwner, bin2hex(random_bytes(24)));
        $sourceFolder = $roots->getUserFolder($sourceOwner)->newFolder('linked-' . bin2hex(random_bytes(4)));
        $shares = Server::get(\OCP\Share\IManager::class);
        $permissions = \OCP\Constants::PERMISSION_ALL;
        $share = $shares->createShare($shares->newShare()->setNode($sourceFolder)->setShareType(\OCP\Share\IShare::TYPE_USER)
            ->setSharedWith($uid)->setSharedBy($sourceOwner)->setPermissions($permissions));
        $sharedFolder = $root->get($share->getTarget());
        if (!$sharedFolder instanceof \OCP\Files\Folder) {throw new RuntimeException('Shared destination folder did not mount');}
        $origin = formsNativeOrigin($uid, 'forms_link_results_file', ['id' => $id]);
        $write = static fn(string $name, string $bytes) => $guard->fileBoundary(
            $uid, $origin, $sharedFolder, $name, static fn() => null,
            static fn() => $sharedFolder->newFile($name, $bytes),
        );
        $first = $write('linked-destination.txt', 'recoverable shared output');
        $revocationRejected = false;
        $revoked = false;
        try {
            $guard->fileBoundary($uid, $origin, $sharedFolder, 'denied-after-acl.txt',
                function (string $stage) use ($sourceOwner, $share, &$revoked): void {
                    if ($stage === 'content' && !$revoked) {
                        commitSharedAcl($sourceOwner, $share->getFullId(), \OCP\Constants::PERMISSION_READ);
                        $revoked = true;
                    }
                },
                static fn() => $sharedFolder->newFile('denied-after-acl.txt', 'must not be written'));
        } catch (Throwable) {$revocationRejected = $revoked;}
        $preflightRejected = false;
        try {StorageAdmission::linkedDestination($sharedFolder, $uid, $sharedFolder->getPath() . '/denied-after-acl.txt', true, 'forms_link_results_file');}
        catch (RuntimeException) {$preflightRejected = true;}
        $preserved = $sourceFolder->get('linked-destination.txt')->getContent() === 'recoverable shared output'
            && !$sourceFolder->nodeExists('denied-after-acl.txt');
        commitSharedAcl($sourceOwner, $share->getFullId(), $permissions);
        $sharedFolder = $root->get($share->getTarget());
        $restored = $guard->fileBoundary(
            $uid, $origin, $sharedFolder, 'linked-restored.txt', static fn() => null,
            static fn() => $sharedFolder->newFile('linked-restored.txt', 'restored after acl'),
        );
        $unscopedRejected = false;
        try {StorageAdmission::storage($sharedFolder->getStorage());} catch (RuntimeException) {$unscopedRejected = true;}
        $result = ['positive_sink' => $first instanceof \OCP\Files\File, 'acl_revocation_rejected' => $revocationRejected,
            'acl_preflight_rejected' => $preflightRejected, 'old_output_preserved' => $preserved, 'restored_sink' => $restored instanceof \OCP\Files\File,
            'unscoped_storage_still_rejected' => $unscopedRejected];
        commitSharedAcl($sourceOwner, $share->getFullId(), null);
        Server::get(\OCP\IUserManager::class)->deleteUser($sourceUser);
    } elseif ($request['case'] === 'positive') {
        StorageAdmission::node($file);
        $deleted = $execute('forms_delete_form', ['id' => $id]);
        $restored = $execute('forms_restore', ['backup_id' => $deleted['references']['backup_id']]);
        $copy = $root->getById($restored['data']['recovered_file_ids'][0])[0];
        $result = ['verified' => hash('sha256', $copy->getContent()) === $expected, 'recovery_available' => isset($deleted['references']['backup_id'])];
    } else {
        $action = 'forms_delete_form';
        $input = ['id' => $id];
        if ($request['case'] === 'unknown_destination') {
            $targetUid = 'storage-target-' . bin2hex(random_bytes(5));
            Server::get(\OCP\IUserManager::class)->createUser($targetUid, bin2hex(random_bytes(24)));
            $destinationRoot = $roots->getUserFolder($targetUid);
            $action = 'forms_transfer';
            $input['owner_id'] = $targetUid;
        }
        $input['_mcp_origin'] = formsNativeOrigin($uid, $action, $input);
        $approved = $guard->snapshot($uid, $action, $input);
        $key = bin2hex(random_bytes(16));
        $backupId = hash('sha256', 'forms-recovery:' . $key);
        $privatePath = '/appdata_' . $config->getSystemValueString('instanceid') . '/forms_mcp_recovery/' . $backupId;
        $sourceMount = Server::get(\OCP\Files\Mount\IMountManager::class)->find($file->getPath());
        $mount = $request['case'] === 'private_recovery' ? Server::get(\OCP\Files\Mount\IMountManager::class)->find($privatePath) : $sourceMount;
        if ($request['case'] === 'unknown_destination') {$mount = $destinationRoot->getMountPoint();}
        $original = $mount->getStorage();
        $oldConfiguration = null;
        $s3Parameters = null;
        $sourceFile = null;
        if ($request['case'] === 'unknown_backend') {
            $mount->wrapStorage(fn () => new UnqualifiedHomeStorage(['user' => $user]));
        } elseif (in_array($request['case'], ['unknown_wrapper', 'private_recovery', 'unknown_destination'], true)) {
            $mount->wrapStorage(fn ($path, $storage) => new UnqualifiedStorageWrapper(['storage' => $storage]));
        } elseif ($request['case'] === 'configuration') {
            $backend = $original;
            while ($backend instanceof \OC\Files\Storage\Wrapper\Wrapper) {$backend = $backend->getWrapperStorage();}
            if ($backend::class === 'OCA\Files_External\Lib\Storage\AmazonS3') {
                $s3Property = new ReflectionProperty($backend, 'params');
                $s3Parameters = $s3Property->getValue($backend);
                $s3Property->setValue($backend, array_replace($s3Parameters, ['use_presigned_url' => true]));
            } else {
                $oldConfiguration = $config->getSystemValue('localstorage.unlink_on_truncate', false);
                $config->setSystemValue('localstorage.unlink_on_truncate', true);
            }
        } elseif ($request['case'] === 'source_drift') {
            $sourceFile = (new ReflectionClass(\OC\Files\Storage\Wrapper\Wrapper::class))->getFileName();
            $originalSource = file_get_contents($sourceFile);
            file_put_contents($sourceFile, $originalSource . "\n");
        } else {throw new RuntimeException('Unknown test case');}
        try {
            $snapshotRejected = false;
            try {$guard->snapshot($uid, $action, $input);} catch (Throwable) {$snapshotRejected = true;}
            $applyRejected = false;
            try {$guard->apply($uid, $action, $input, $approved['revision'], $key, $approved['file_manifest']);}
            catch (\OCA\Forms\Mcp\BeforeEffect) {$applyRejected = true;}
            $nativeUiAllowed = $api->newForm()->getData()['id'] > 0;
            if ($request['case'] === 'unknown_wrapper') {
                $sourceAliasesRejected = true;
                $relative = $root->getRelativePath($file->getPath());
                foreach ([str_replace('/', '\\', $relative), str_replace('/', '//./', $relative)] as $alias) {
                    try {StorageAdmission::userPath($uid, $alias); $sourceAliasesRejected = false;}
                    catch (RuntimeException) {}
                }
            }
        } finally {
            $mount->wrapStorage(fn () => $original);
            if ($s3Parameters !== null) {$s3Property->setValue($backend, $s3Parameters);}
            if ($oldConfiguration !== null) {$config->setSystemValue('localstorage.unlink_on_truncate', $oldConfiguration);}
            if ($sourceFile !== null) {file_put_contents($sourceFile, $originalSource);}
        }
        $nodes = $root->getById($fileId);
        $result = ['snapshot_rejected' => $snapshotRejected, 'apply_rejected_before_effect' => $applyRejected,
            'files_preserved' => $nodes !== [] && hash('sha256', $nodes[0]->getContent()) === $expected,
            'form_preserved' => (bool)$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetchOne(),
            'owner_preserved' => $uid === $db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetchOne(),
            'recovery_absent' => !$roots->nodeExists($privatePath), 'native_ui_allowed' => $nativeUiAllowed];
        if (isset($sourceAliasesRejected)) {$result['source_aliases_rejected'] = $sourceAliasesRejected;}
    }
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'class' => $error::class,
        'error_class' => $error::class, 'public_file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n";
}
