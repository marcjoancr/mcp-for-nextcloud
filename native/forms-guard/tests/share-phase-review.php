<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
use OCP\Server;
if ($case === 'concurrent_folder') {
    class RaceFolder extends \OC\Files\Node\Folder
    {
        public string $target;
        public ?Closure $race = null;
        public function nodeExists($path)
        {
            $exists = parent::nodeExists($path);
            if (!$exists && $path === $this->target && $this->race !== null) {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (($frame['class'] ?? '') === \OCA\Forms\Mcp\Service\ManagedSharePhase::class && ($frame['function'] ?? '') === 'execute') {
                        $race = $this->race;
                        $this->race = null;
                        $race();
                        break;
                    }
                }
            }
            return $exists;
        }
    }
    $root = Server::get(\OCP\Files\IRootFolder::class);
    $original = $root->getUserFolder($uid);
    $form = $container->get(\OCA\Forms\Db\FormMapper::class)->findById($id);
    $path = $container->get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
    $resolved = $root->get($original->getPath());
    $view = (new ReflectionProperty($resolved, 'view'))->getValue($resolved);
    $folder = new RaceFolder($root, $view, $original->getPath(), $original->getFileInfo());
    $folder->target = $path;
    $createdId = null;
    $folder->race = function () use ($uid, $path, &$createdId): void {
        $p = proc_open([PHP_BINARY, __DIR__ . '/share-phase-external.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['uid' => $uid, 'create_folder' => $path]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0) {
            throw new RuntimeException('Independent folder creation failed ' . $err);
        }
        $createdId = json_decode($out, true, 16, JSON_THROW_ON_ERROR)['id'];
    };
    $cacheRoot = $root instanceof \OC\Files\Node\LazyRoot ? (new ReflectionMethod($root, 'getRealFolder'))->invoke($root) : $root;
    $cache = (new ReflectionProperty($cacheRoot, 'userFolderCache'))->getValue($cacheRoot);
    $cache->set($uid, $folder);
    try {
        $receipt = $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
    } finally {
        $cache->set($uid, $original);
    }
    $registered = $db->executeQuery('SELECT folder_id FROM *PREFIX*forms_mcp_folders WHERE form_id = ? AND folder_id = ?', [$id, $createdId])->fetchOne() !== false;
    $sharesCount = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE file_source = ?', [$createdId])->fetchOne();
    $result = ['raced' => $createdId !== null, 'not_registered' => !$registered, 'not_shared' => $sharesCount === 0, 'foreign_folder_preserved' => $original->get($path)->getId() === $createdId, 'uncertain' => $receipt['phases']['files'] === 'native_files_shares_uncertain'];
} else {
    Server::get(\OCP\App\IAppManager::class)->loadApp('sharebymail');
    Server::get(\OCP\App\IAppManager::class)->loadApp('federatedfilesharing');
    class MailRoutingSentinel extends \OCA\ShareByMail\ShareByMailProvider
    {
        public static bool $called = false;
        public function create(\OCP\Share\IShare $share): \OCP\Share\IShare
        {
            self::$called = true;
            throw new Error('Unsupported mail provider reached; external effects prevented by test');
        }
    }
    class FederationRoutingSentinel extends \OCA\FederatedFileSharing\FederatedShareProvider
    {
        public static bool $called = false;
        public function create(\OCP\Share\IShare $share): \OCP\Share\IShare
        {
            self::$called = true;
            throw new Error('Unsupported federation provider reached; network effects prevented by test');
        }
    }
    $before = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share')->fetchOne();
    $activityBefore = (int) $db->executeQuery("SELECT COUNT(*) FROM *PREFIX*activity WHERE app IN ('sharebymail','federatedfilesharing')")->fetchOne();
    $reached = false;
    $routesClosed = true;
    Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCP\Share\Events\BeforeShareCreatedEvent::class, function ($event) use ($case, &$reached, &$routesClosed): void {
        $reached = true;
        $factory = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            if (($frame['object'] ?? null) instanceof \OC\Share20\Manager) {
                $factory = (new ReflectionProperty($frame['object'], 'factory'))->getValue($frame['object']);
                break;
            }
        }
        if (!$factory instanceof \OCA\Forms\Mcp\Service\SharingProviderFactory) {
            throw new Error('Scoped native factory missing');
        }
        (new ReflectionProperty(\OC\Share20\ProviderFactory::class, 'shareByMailProvider'))->setValue($factory, (new ReflectionClass(MailRoutingSentinel::class))->newInstanceWithoutConstructor());
        (new ReflectionProperty(\OC\Share20\ProviderFactory::class, 'federatedProvider'))->setValue($factory, (new ReflectionClass(FederationRoutingSentinel::class))->newInstanceWithoutConstructor());
        if ($case === 'routing_all') {
            foreach ([2, 3, 4, 6, 7, 8, 9, 10, -1, '0'] as $type) {
                try {
                    $factory->getProviderForType($type);
                    $routesClosed = false;
                } catch (Throwable) {
                }
            }
            foreach (['ocMailShare', 'ocFederatedSharing', 'ocCircleShare', 'ocRoomShare', 'deck', 'unknown'] as $provider) {
                try {
                    $factory->getProvider($provider);
                    $routesClosed = false;
                } catch (Throwable) {
                }
            }
            $all = $factory->getAllProviders();
            $routesClosed = $routesClosed && count($all) === 1 && $all[0] instanceof \OCA\Forms\Mcp\Service\SharingDefaultProvider;
            try {
                $factory->registerProvider(MailRoutingSentinel::class);
                $routesClosed = false;
            } catch (Throwable) {
            }
        }
        $event->getShare()->setShareType($case === 'routing_federated' ? 6 : 4);
        $event->getShare()->setSharedWith('nobody@example.invalid');
    });
    $receipt = $apply('forms_update_share', ['id' => $id, 'share_id' => $shareId, 'permissions' => ['submit', 'results']]);
    $result = ['event_reached' => $reached, 'unsupported_provider_not_called' => !MailRoutingSentinel::$called && !FederationRoutingSentinel::$called, 'all_routes_closed' => $routesClosed, 'sql_unchanged' => (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share')->fetchOne() === $before, 'activity_unchanged' => (int) $db->executeQuery("SELECT COUNT(*) FROM *PREFIX*activity WHERE app IN ('sharebymail','federatedfilesharing')")->fetchOne() === $activityBefore, 'uncertain' => $receipt['phases']['files'] === 'native_files_shares_uncertain'];
}
echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
