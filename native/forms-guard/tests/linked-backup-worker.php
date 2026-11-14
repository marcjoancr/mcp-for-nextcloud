<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCP\Lock\ILockingProvider;
use OCA\Forms\Db\{Submission, SubmissionMapper};
final class UnknownBackupStorage extends \OC\Files\Storage\Wrapper\Wrapper
{
}
/** Only schedules an external committed change. The native provider still performs every lock. */
final class BackupLockBarrier implements ILockingProvider
{
    public bool $waited = false;
    public function __construct(private ILockingProvider $inner, private string $key, private array $request)
    {
    }
    public function isLocked(string $path, int $type): bool
    {
        return $this->inner->isLocked($path, $type);
    }
    public function acquireLock(string $path, int $type, ?string $readablePath = null): void
    {
        if ($this->waited || $path !== $this->key) {
            $this->inner->acquireLock($path, $type, $readablePath);
            return;
        }
        $process = proc_open([PHP_BINARY, __DIR__ . '/linked-backup-authority.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($this->request + ['lock_key' => $path]));
        fclose($pipes[0]);
        $ready = fgets($pipes[1]);
        if ($ready !== "LOCKED\n") {
            throw new RuntimeException('Lock holder failed ' . (string) $ready . stream_get_contents($pipes[2]));
        }
        try {
            $this->inner->acquireLock($path, $type, $readablePath);
        } finally {
            $out = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('Lock-wait authority writer failed ' . $error . ' ' . $out);
            }
            $state = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
            $this->waited = $state['waited'] && $state['committed'];
        }
    }
    public function changeLock(string $path, int $type): void
    {
        $this->inner->changeLock($path, $type);
    }
    public function releaseLock(string $path, int $type): void
    {
        $this->inner->releaseLock($path, $type);
    }
    public function releaseAll(): void
    {
        $this->inner->releaseAll();
    }
}
function backupAuthority(array $request): void
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/linked-backup-authority.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || (json_decode($output, true)['committed'] ?? false) !== true) {
        throw new RuntimeException('Authority writer failed ' . $error . ' ' . $output);
    }
}
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $case = $input['case'];
    $guard = Server::get(\OCA\Forms\Mcp\Guard::class);
    $claim = $guard->linkedClaim($input['association_id']);
    if ($claim === null) {
        throw new RuntimeException('Fixture association cannot be claimed');
    }
    $uid = $claim['original_owner_uid'];
    $origin = $claim['origin'];
    $db = Server::get(\OCP\IDBConnection::class);
    $session = Server::get(\OCP\IUserSession::class);
    $roots = Server::get(\OCP\Files\IRootFolder::class);
    $guard->initializeLinkedFiles($origin);
    $root = $roots->getUserFolder($uid);
    $file = $root->get($claim['last_verified']['path']);
    $backupName = $input['backup_name'];
    $backup = $root->get($backupName . '.csv');
    $storage = $backup->getStorage();
    $backupPath = $backup->getInternalPath();
    $outputPath = $file->getInternalPath();
    $refused = static function (callable $work): bool {
        try {
            $work();
            return false;
        } catch (Throwable) {
            return true;
        }
    };
    if (in_array($case, ['context', 'wrong_root'], true)) {
        $calls = 0;
        $callback = function () use (&$calls): void {
            $calls++;
        };
        $checks = [];
        if ($case === 'context') {
            $checks['outside_cycle_refused'] = $refused(fn() => $guard->deferredBackupBoundary($origin, $backup, null, $callback, $callback));
            $other = Server::get(\OCP\IUserManager::class)->get('guard-other');
            $session->setUser($other);
            $checks['foreign_session_refused'] = $refused(fn() => $guard->deferredBackupBoundary($origin, $backup, null, $callback, $callback));
            $checks['session_preserved'] = $session->getUser() === $other;
        } else {
            \OC\Files\Filesystem::tearDown();
            \OC\Files\Filesystem::init('guard-other', '/guard-other/files');
            $view = \OC\Files\Filesystem::getView();
            $checks['foreign_root_refused'] = $refused(fn() => $guard->deferredBackupBoundary($origin, $backup, null, $callback, $callback));
            $checks['foreign_view_preserved'] = \OC\Files\Filesystem::getView() === $view && \OC\Files\Filesystem::getRoot() === '/guard-other/files';
            $checks['null_session'] = $session->getUser() === null;
        }
        $checks['no_callbacks'] = $calls === 0;
        $checks['backup_empty'] = $storage->file_get_contents($backupPath) === '';
        echo json_encode(['ok' => true, 'result' => $checks], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    $cycle = ['job_id' => hash('sha256', 'forms-job-v1:' . hash('sha256', 'forms-linked-cycle-v1:' . $claim['association_id'] . ':' . $claim['dirty_generation'])), 'generation' => $claim['dirty_generation']];
    $claim = $guard->linkedCheckpoint($claim, $cycle);
    $source = $guard->linkedRead($claim, 'snapshot', ['id' => $claim['form_id']]);
    $view = (new ReflectionProperty($backup, 'view'))->getValue($backup);
    $property = new ReflectionProperty($view, 'lockingProvider');
    $locks = $property->getValue($view);
    $originalView = \OC\Files\Filesystem::getView();
    $stages = [];
    $calls = 0;
    $barrier = null;
    $backupCompleted = false;
    $admissionCalls = 0;
    $authority = ['uid' => $uid, 'connection' => $origin['connection'], 'mode' => $case === 'wait_connection' ? 'connection' : 'grant'];
    if (str_starts_with($case, 'wait_')) {
        // The fixture's empty file seeded the native lock row before this sync transaction starts.
        $key = 'files/' . md5($storage->getId() . '::' . trim($backupPath, '/'));
        $barrier = new BackupLockBarrier($locks, $key, $authority + ['backend_pid' => (int) $db->executeQuery('SELECT pg_backend_pid()')->fetchOne()]);
        $property->setValue($view, $barrier);
    }
    $mount = $backup->getMountPoint();
    $originalStorage = $mount->getStorage();
    $boundary = function ($node, ?string $child, callable $write, string $label) use ($guard, $origin, $claim, $locks, $case, &$stages, &$admissionCalls): mixed {
        $path = $child === null ? $node->getInternalPath() : $node->getInternalPath() . '/' . $child;
        $key = 'files/' . md5($node->getStorage()->getId() . '::' . trim($path, '/'));
        return $guard->deferredBackupBoundary($origin, $node, $child, function (string $stage) use ($guard, $claim, $locks, $key, $case, $label, &$stages, &$admissionCalls): void {
            $admissionCalls++;
            $stages[$label][] = $stage;
            $guard->linkedRead($claim, 'snapshot', ['id' => $claim['form_id']]);
            if ($stage === 'content') {
                if (!$locks->isLocked($key, ILockingProvider::LOCK_EXCLUSIVE)) {
                    throw new RuntimeException('Backup admission must hold its actual exclusive lock');
                }
                if ($case === $label . '_refused') {
                    throw new RuntimeException('Trusted backup policy refused ' . $label);
                }
            }
        }, $write);
    };
    $sink = function () use ($guard, $origin, $claim, $file, $backup, $root, $backupName, $case, $boundary, $mount, $originalStorage, $authority, &$calls, &$backupCompleted): array {
        if ($case === 'unknown_storage') {
            $mount->wrapStorage(fn($path, $storage) => new UnknownBackupStorage(['storage' => $storage]));
        }
        if ($case === 'output_changed') {
            $file->putContent('manual original edit');
        }
        if ($case === 'mkdir_refused') {
            $boundary($root, $backupName, fn() => $root->newFolder($backupName), 'mkdir');
        } elseif ($case === 'empty_collision') {
            $boundary($root, $backupName . '.csv', fn() => $root->newFile($backupName . '.csv'), 'empty');
        } elseif ($case === 'empty_refused') {
            $boundary($root, $backupName, fn() => $root->newFile($backupName), 'empty');
        } else {
            if (in_array($case, ['copy', 'copy_stream'], true)) {
                $folder = $boundary($root, $backupName, fn() => $root->newFolder($backupName), 'mkdir');
                $nested = $boundary($folder, 'nested', fn() => $folder->newFolder('nested'), 'nested');
                $backup = $boundary($nested, 'recovery.csv', fn() => $nested->newFile('recovery.csv'), 'empty');
            }
            $payload = $case === 'copy_stream' ? $file->fopen('rb') : 'original private results';
            if ($case === 'copy_stream' && !is_resource($payload)) {
                throw new RuntimeException('Original backup source stream is unavailable');
            }
            try {
                $boundary($backup, null, function () use ($backup, $payload, &$calls): void {
                    $calls++;
                    $backup->putContent($payload);
                }, 'content');
            } finally {
                if (is_resource($payload)) {
                    fclose($payload);
                }
            }
            $backupCompleted = true;
        }
        if ($case === 'after_source') {
            $submission = new Submission();
            $submission->setFormId($claim['form_id']);
            $submission->setUserId($claim['original_owner_uid']);
            $submission->setTimestamp(time());
            Server::get(SubmissionMapper::class)->insert($submission);
        }
        if ($case === 'after_auth') {
            backupAuthority($authority);
        }
        return $guard->deferredFileBoundary($origin, $file, null, static fn() => null, function () use ($file): array {
            $file->putContent('new verified results');
            return ['file_id' => (int) $file->getId(), 'path' => $file->getName(), 'etag' => (string) $file->getEtag(), 'size' => (int) $file->getSize(), 'sha256' => hash('sha256', $file->getContent())];
        });
    };
    $failed = false;
    $error = '';
    try {
        $guard->syncLinkedFile($origin, $claim['association_id'], $claim['version'], $source['export_revision'], hash('sha256', 'forms-linked-write-v1:' . $claim['association_id'] . ':' . $cycle['generation']), ['lease_token' => $claim['lease_token'], 'active_cycle' => $cycle, 'destination' => []], $sink);
    } catch (Throwable $failure) {
        $failed = true;
        $error = $failure->getMessage();
    } finally {
        $property->setValue($view, $locks);
        if ($case === 'unknown_storage') {
            $mount->wrapStorage(fn() => $originalStorage);
        }
        if (in_array($case, ['wait_grant', 'after_auth'], true)) {
            backupAuthority($authority + ['allow' => true]);
        }
    }
    $checks = ['null_session' => $session->getUser() === null, 'original_view_preserved' => \OC\Files\Filesystem::getView() === $originalView && \OC\Files\Filesystem::getRoot() === '/' . $uid . '/files'];
    if (in_array($case, ['copy', 'copy_stream'], true)) {
        $copy = $root->get($backupName . '/nested/recovery.csv');
        $checks += ['successful' => !$failed, 'exact_recovery_bytes' => $copy->getContent() === 'original private results', 'original_updated' => $file->getContent() === 'new verified results'];
        foreach (['mkdir', 'nested', 'empty', 'content'] as $label) {
            $checks[$label . '_both_stages'] = ($stages[$label] ?? []) === ['preflight', 'content'];
        }
    } else {
        $checks['refused'] = $failed;
        $checks['original_untouched'] = $storage->file_get_contents($outputPath) === ($case === 'output_changed' ? 'manual original edit' : 'original private results');
        $checks['backup_bytes'] = $storage->file_get_contents($backupPath) === (str_starts_with($case, 'after_') ? 'original private results' : '');
        if ($barrier !== null) {
            $checks['actual_native_lock_wait'] = $barrier->waited;
        }
        if ($case === 'unknown_storage') {
            $checks['before_callback'] = $admissionCalls === 0 && $calls === 0;
        }
        if (in_array($case, ['mkdir_refused', 'empty_refused'], true)) {
            $checks['allocation_absent'] = !$storage->file_exists($root->getInternalPath() . '/' . $backupName);
            $checks['post_lock_admission'] = in_array('content', $stages[explode('_', $case)[0]] ?? [], true);
        }
        if ($case === 'content_refused') {
            $checks['post_lock_admission'] = in_array('content', $stages['content'] ?? [], true);
        }
        if (str_starts_with($case, 'after_')) {
            $checks['copy_completed'] = $backupCompleted;
        }
    }
    echo json_encode(['ok' => true, 'result' => $checks, 'diagnostic' => $error], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error::class . ': ' . $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
