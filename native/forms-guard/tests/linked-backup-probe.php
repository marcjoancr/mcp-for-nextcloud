<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Db\{FormMapper, SubmissionMapper, Submission};
use OCA\Forms\Mcp\Service\{FormsRevisionFence, LinkedMarkers};
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $guard = Server::get(\OCA\Forms\Mcp\Guard::class);
    if (!method_exists($guard, 'deferredBackupBoundary') && !str_starts_with($request['case'], 'interactive_')) {
        echo json_encode(['ok' => true, 'result' => ['native_backup_api' => false]]);
        exit;
    }
    if ($request['case'] === 'capability') {
        $caps = $guard->capabilities();
        $db = Server::get(\OCP\IDBConnection::class);
        $db->beginTransaction();
        try {
            $removed = $db->executeStatement('DELETE FROM *PREFIX*migrations WHERE app = ? AND version = ?', ['forms', '050402Date20261113060000']);
            if ($removed !== 1) {
                throw new RuntimeException('Capability fixture requires its applied marker migration');
            }
            $unqualified = $guard->capabilities();
        } finally {
            $db->rollBack();
        }
        echo json_encode(['ok' => true, 'result' => ['native_qualified' => $caps['qualified'], 'linked_qualified' => ($caps['linked_exports'] ?? null) === true, 'missing_schema_closed' => $unqualified['qualified'] === false && ($unqualified['linked_exports'] ?? null) === false]], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    $uid = 'guard-owner';
    $session = Server::get(\OCP\IUserSession::class);
    $session->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    $config = Server::get(\OCP\IConfig::class);
    $config->deleteUserValue($uid, 'mcp', 'forms_native_test_ready');
    $db = Server::get(\OCP\IDBConnection::class);
    $fence = Server::get(FormsRevisionFence::class);
    $api = (new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $questionType = 'short';
    $id = $api->newForm()->getData()['id'];
    $question = $api->newQuestion($id, $questionType, null, 'Linked fixture question')->getData()['id'];
    require __DIR__ . '/origin-fixture.php';
    formsNativeOrigin($uid, 'forms_summarize_results', ['id' => $id]);
    // Issue a real persistent OAuth connection, then retain its exact selected tools in the native proof.
    $clients = Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class);
    if (!\OCA\Mcp\OAuth\ClientResolver::nativeEnabled($clients)) {
        Server::get(\OCA\Mcp\OAuth\OAuthClientsService::class)->update(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'], null, true, null);
    }
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = \OCA\Mcp\OAuth\NativeClient::REDIRECT_URIS[0];
    $resource = 'http://localhost/apps/mcp/';
    $tokens = Server::get(\OCA\Mcp\OAuth\TokenService::class);
    $authorization = new \OCA\Mcp\OAuth\AuthorizationRequest(\OCA\Mcp\OAuth\NativeClient::metadata((string) \OCA\Mcp\OAuth\ClientResolver::nativeGeneration($clients)), $redirect, 'linked-fixture', $challenge, $resource, 'mcp');
    $code = $tokens->consent($authorization, $uid, ['forms_summarize_results'], []);
    $tokens->exchangeCode(['code' => $code, 'code_verifier' => $verifier, 'client_id' => \OCA\Mcp\OAuth\NativeClient::CLIENT_ID, 'redirect_uri' => $redirect, 'resource' => $resource]);
    $connection = (int) $db->executeQuery('SELECT MAX(id) FROM *PREFIX*mcp_oauth_tokens WHERE user_id = ?', [$uid])->fetchOne();
    $selection = Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);
    $saved = $selection->get($uid, $connection);
    $operations = ['link_results'];
    $selection->save($uid, $connection, ['forms_link_results_file', 'forms_unlink_results_file', 'forms_summarize_results'], $saved['revision'], ['forms_link_results_file' => $operations]);
    $origin = Server::get(\OCA\Mcp\Modules\Forms\Access\FormsOriginProof::class)->create($uid, $connection, 'forms_link_results_file', $operations, ['form_id' => $id, 'owner_id' => $uid, 'files_operations' => ['read', 'edit', 'create']]);
    $metadata = $guard->deferredRead($origin, 'forms_results_metadata', ['id' => $id]);
    $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
    $name = 'native-backup-' . bin2hex(random_bytes(6)) . '.csv';
    $file = $root->newFile($name, 'original private results');
    $backupName = 'native-recovery-' . bin2hex(random_bytes(6));
    $backup = $root->newFile($backupName . '.csv', '');
    $receipt = static fn() => ['file_id' => (int) $file->getId(), 'path' => $name, 'etag' => (string) $file->getEtag(), 'size' => (int) $file->getSize(), 'sha256' => hash('sha256', $file->getContent())];
    $input = ['id' => $id, 'format' => 'csv', '_mcp_origin' => $origin, '_mcp_link_approval' => ['arguments' => ['id' => $id, 'format' => 'csv', 'path' => $name, 'overwrite' => true, 'etag' => $file->getEtag()], 'native_runtime' => LinkedMarkers::runtime(), 'schema_digest' => LinkedMarkers::schemaDigest($metadata), 'source_revision' => $metadata['export_revision'], 'destination' => ['path' => $name, 'file_id' => (int) $file->getId(), 'overwrite' => true]]];
    $case = $request['case'];
    $stages = [];
    $refused = false;
    if (str_starts_with($case, 'interactive_')) {
        $view = (new ReflectionProperty($file, 'view'))->getValue($file);
        $locks = (new ReflectionProperty($view, 'lockingProvider'))->getValue($view);
        $target = $root->getInternalPath() . '/' . $backupName;
        $key = 'files/' . md5($root->getStorage()->getId() . '::' . trim($target, '/'));
        $authorize = function (string $stage) use (&$stages, $locks, $key, $case): void {
            $stages[] = $stage;
            if ($stage === 'content') {
                if (!$locks->isLocked($key, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE)) {
                    throw new RuntimeException('Folder content stage requires its actual exclusive lock');
                }
                if ($case === 'interactive_refused') {
                    throw new RuntimeException('Trusted backup policy refused allocation');
                }
            }
        };
        try {
            $guard->apply($uid, 'forms_link_results_file', $input, $fence->revision(), bin2hex(random_bytes(32)), [], function () use ($guard, $uid, $origin, $root, $backupName, $authorize, $receipt): array {
                $guard->fileBoundary($uid, $origin, $root, $backupName, $authorize, fn() => $root->newFolder($backupName));
                return $receipt();
            });
        } catch (Throwable $error) {
            $refused = true;
        }
        $exists = $root->getStorage()->file_exists($target);
        $result = ['content_admitted' => in_array('content', $stages, true), 'allocation_result' => $exists === ($case === 'interactive_mkdir'), 'refusal_result' => $refused === ($case === 'interactive_refused')];
    } else {
        $linked = $guard->apply($uid, 'forms_link_results_file', $input, $fence->revision(), bin2hex(random_bytes(32)), [], $receipt);
        $submission = new Submission();
        $submission->setFormId($id);
        $submission->setUserId($uid);
        $submission->setTimestamp(time());
        Server::get(SubmissionMapper::class)->insert($submission);
        $process = proc_open([PHP_BINARY, __DIR__ . '/linked-backup-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['association_id' => $linked['references']['association_id'], 'case' => $case, 'backup_name' => $backupName]));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Backup worker failed ' . $error . ' ' . $output);
        }
        $child = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        if (!$child['ok']) {
            throw new RuntimeException('Backup worker refused fixture ' . json_encode($child));
        }
        $result = $child['result'];
    }
    echo json_encode(['ok' => true, 'result' => $result, 'diagnostic' => $child['diagnostic'] ?? ''], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error::class . ': ' . $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
