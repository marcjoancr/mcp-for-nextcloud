<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\Forms\Db\FormMapper;
use OCA\Forms\Helper\FilePathHelper;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\Server;

/** Admission for the exact storage implementations exercised by the companion's native tests. */
final class StorageAdmission {
    private const SOURCES = [
        'OC\Files\Storage\LocalTempFileTrait' => 'fdf96db9c96816123d3425f898d30e8688de50c2bf85d29313ab9c7be07b2949',
        'OC\Files\Storage\Local' => '28519130479f1e7356bb0938671298e439585cb063f5236c0cca73bd11bb6263',
        'OC\Files\Storage\Home' => 'a3194a0cd25c3325b59211cab5f9192580fbd3cf3b2836b304b677bf6454511a',
        'OC\Files\Storage\LocalRootStorage' => '3a32e82f966d1d11d14149843a2cd82b9ca2d28154bf0bb2d1b521ee3bcdd3ab',
        'OC\Files\Storage\Common' => '06cd2432b09ac01d0dddaf4e623e2fc09b017e86bad1718868e33f3cd2bd3d8e',
        'OC\Files\Storage\Wrapper\Wrapper' => '5df69575791a5965fcb5349be379e35c0212f084530184f0cf4459ce77b8fc1b',
        'OC\Files\Storage\Wrapper\Jail' => 'd6ab5be7eacaccb331ae1e59941e20a7ab5c5ceecc76f6ca126fa5abe1df926d',
        'OC\Files\Storage\Wrapper\Quota' => 'd5f4922f6356eda6ae631f28eb9f0142eec6c083e67b3f81215ff314cf6ca54e',
        'OC\Files\Storage\Wrapper\Availability' => 'a3491ad397032b5706e578bb0fce89df7cfaf49b09ec6e7f812018aedc977eb2',
        'OC\Files\Storage\Wrapper\PermissionsMask' => 'ebf2e1c2399901d9632d76be8d798480cf929308d339aa6b92a1ca2620e09752',
        'OCA\Files_Trashbin\Storage' => '02b0cb9c1c6e47d3c231ff4a9f83112c4668011b8a75623c8d527b26d69886af',
        'OCA\FilesLock\Storage\LockWrapper' => '185cc860224f1724691adf56316d4c679060b20472bd0f8f7ae902940487a95d',
        'OCA\Files_Sharing\SharedStorage' => 'daabea358c320e39fae17920cb0f6e673c19c2160482b5440b16c16e2bded17f',
        'OCA\Files_Sharing\SharedMount' => '1408ed20fae4df74907db4a2496c40cf4f9427d1c0b34ea16b6e0f1a046b879d',
        'OC\Files\Mount\MountPoint' => '6affaab2526d599c0cacbfb544cfb54429427af73646ec26561d53dd07eb13f8',
        'OC\Share20\Manager' => '4a13c43b201fc1458657c44e731f1814d937d97e935cc49222860c1e80dfaa44',
        'OC\Share20\ProviderFactory' => 'e64b4715b48e5c7a9c3ddc9e05c7edfa1414fc2173f35f7717ae917b6036bda9',
        'OC\Share20\DefaultShareProvider' => '9810df22fd4780bd96ef6e89c27daf1dca21125c735875726628ee101f5e012c',
        'OC\Share20\Share' => 'fceef3f3db15f93652bc44ff7e97811adbeee3856c311637736b580233ef7c66',
        'OCA\Files_External\Lib\Storage\AmazonS3' => '08bfefc524ffe4a95df4d91554641357f6c8639b3059907da1a8c34e0147ce9e',
        'OC\Files\ObjectStore\S3ConnectionTrait' => 'cbb4f365396db28e4aadaa851e183a3419647a9acf11b99b772028a5138b3599',
        'OC\Files\ObjectStore\S3ConfigTrait' => '26230c2c6c5b87901f906784d765483d240dc41b7b05e11b970229711373eb13',
        'OC\Files\ObjectStore\S3ObjectTrait' => 'c4a5ad58ffb109d3f384738f1e1cf55ddaae4a2a03928358f149b55cc4541d4a',
    ];
    private const WRAPPERS = [
        'OCA\Files_Trashbin\Storage' => 1,
        'OCA\FilesLock\Storage\LockWrapper' => 2,
        'OC\Files\Storage\Wrapper\Quota' => 3,
        'OC\Files\Storage\Wrapper\Availability' => 3,
        'OC\Files\Storage\Wrapper\PermissionsMask' => 4,
    ];
    private const BACKENDS = [
        'OC\Files\Storage\Local',
        'OC\Files\Storage\Home',
        'OC\Files\Storage\LocalRootStorage',
        'OCA\Files_External\Lib\Storage\AmazonS3',
    ];

    /** Validate all prospective paths before backup allocation or the native database callback. */
    public static function operation(string $uid, string $action, array $input): void {
        if ($action === 'forms_restore') {
            self::recovery();
            // Admission of a restore must remain metadata-only; a fresh recipient Home is prepared by the
            // one-shot child only after the sealed backup and exact file inventory are known.
            self::userPath($uid, 'Forms', true, true);
            return;
        }
        $fileActions = [
            'forms_upload_response_files', 'forms_save_response_file', 'forms_submit_response',
            'forms_update_response', 'forms_update_own_response', 'forms_delete_response',
            'forms_delete_own_response', 'forms_delete_responses', 'forms_delete_form',
            'forms_delete_question', 'forms_transfer', 'forms_update_share', 'forms_unshare',
        ];
        if (!in_array($action, $fileActions, true) && !($action === 'forms_update' && isset($input['fields']['title']))) {
            return;
        }
        if (in_array($action, ['forms_update_share', 'forms_unshare'], true)) {
            $share = Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$input['share_id']);
            if (!in_array($share->getShareType(), [0, 1, 2, 7], true)
                || ($action === 'forms_update_share' && !isset($input['permissions']))
                || ($action === 'forms_unshare' && !in_array('results', $share->getPermissions(), true))) {
                return;
            }
        }
        if ($action === 'forms_upload_response_files') {
            foreach ($input['paths'] ?? [] as $path) {
                self::userPath($uid, $path);
            }
        }
        if ($action === 'forms_save_response_file') {
            self::userPath($uid, $input['path']);
            $nodes = Server::get(IRootFolder::class)->getUserFolder($uid)->getById((int)$input['file_id']);
            if ($nodes === []) {
                throw new \RuntimeException('Forms file storage is unavailable');
            }
            foreach ($nodes as $node) {
                self::node($node);
            }
            return;
        }
        $form = Server::get(FormMapper::class)->findById((int)$input['id']);
        $path = Server::get(FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        self::userPath($form->getOwnerId(), $path, true);
        if (in_array($action, ['forms_upload_response_files', 'forms_submit_response', 'forms_update_response', 'forms_update_own_response'], true)) {
            self::userPath($form->getOwnerId(), 'Forms/unsubmitted', true);
        }
        if ($action === 'forms_transfer') {
            self::userPath($input['owner_id'], $path, true, true, true);
        }
        if ($action !== 'forms_upload_response_files') {
            self::recovery();
        }
    }

    public static function userPath(string $uid, string $path, bool $descendants = false, bool $prospective = false, bool $checkCollision = false): void {
        $segments = explode('/', str_replace('\\', '/', $path));
        if (in_array('..', $segments, true) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new \RuntimeException('Forms storage path is unavailable');
        }
        if ($prospective) {
            self::prospectiveUserPath($uid, $segments, $descendants, $checkCollision);
            return;
        }
        $root = Server::get(IRootFolder::class)->getUserFolder($uid);
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        self::path($root->getPath() . '/' . implode('/', $segments), $descendants);
    }

    /** Admit a target Home from already materialized mount metadata, never from Root::getUserFolder. */
    private static function prospectiveUserPath(string $uid, array $segments, bool $descendants, bool $checkCollision): void {
        $full = NativeHomePath::prospectiveFilesPath($uid);
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        if ($segments !== []) { $full .= '/' . implode('/', $segments); }
        $manager = Server::get(IMountManager::class);
        if ($manager::class !== 'OC\\Files\\Mount\\Manager') {
            throw new \RuntimeException('Forms Home mount metadata is unavailable');
        }
        $mounts = $manager->getAll();
        if (!is_array($mounts)) { throw new \RuntimeException('Forms Home mount metadata is unavailable'); }
        $home = NativeHomePath::selectedHomeMount($uid, $mounts);
        if ($home === null) {
            $prefix = '/' . $uid . '/';
            foreach ($mounts as $mount) {
                if (!is_object($mount) || !method_exists($mount, 'getMountPoint')) {
                    throw new \RuntimeException('Forms Home mount metadata is unavailable');
                }
                $point = $mount->getMountPoint();
                if (!is_string($point) || $point === '' || str_contains($point, "\0")) {
                    throw new \RuntimeException('Forms Home mount metadata is unavailable');
                }
                if (str_starts_with($point, $prefix)) {
                    throw new \RuntimeException('Forms Home mount metadata is unavailable');
                }
            }
            $homePath = self::prospectiveHomePath($uid);
            if ($checkCollision) { NativeHomePath::assertNoPathCollision($homePath, 'files/' . implode('/', $segments)); }
            return;
        }
        $homeStorage = $home->getStorage();
        if (!$homeStorage instanceof IStorage) { throw new \RuntimeException('Forms storage mount is unavailable'); }
        self::storage($homeStorage);
        $mount = NativeHomePath::mountForPath($full, $mounts) ?? $home;
        $storage = $mount->getStorage();
        if (!$storage instanceof IStorage) { throw new \RuntimeException('Forms storage mount is unavailable'); }
        self::storage($storage);
        if ($descendants) {
            $count = 0;
            foreach ($mounts as $child) {
                $point = $child->getMountPoint();
                if (!is_string($point)) { throw new \RuntimeException('Forms Home mount metadata is unavailable'); }
                if ($point !== $full && str_starts_with($point, rtrim($full, '/') . '/')) {
                    if (++$count > 100) { throw new \RuntimeException('Forms storage mount limit exceeded'); }
                    $childStorage = $child->getStorage();
                    if (!$childStorage instanceof IStorage) { throw new \RuntimeException('Forms storage mount is unavailable'); }
                    self::storage($childStorage);
                }
            }
        }
    }

    /** Pure default Database/Local Home path from the fresh native user record and system config. */
    public static function prospectiveHomePath(string $uid): string {
        $user = (new FreshCore($uid))->user();
        $config = Server::get(IConfig::class);
        $data = $config->getSystemValue('datadirectory', '');
        if (!is_string($data)) { throw new \RuntimeException('Forms local Home profile is unavailable'); }
        return NativeHomePath::assertDefaultLocalConfig(
            $uid,
            $user->getHome(),
            $data,
            $config->getSystemValue('objectstore', false),
            $config->getSystemValue('objectstore_multibucket', false),
        );
    }

    /** Resolving mounts does not create the appdata directory. */
    public static function recovery(): void {
        $instance = Server::get(IConfig::class)->getSystemValueString('instanceid');
        if ($instance === '' || !preg_match('/^[a-zA-Z0-9]+$/D', $instance)) {
            throw new \RuntimeException('Forms recovery storage is unavailable');
        }
        self::path('/appdata_' . $instance . '/forms_mcp_recovery', true);
    }

    public static function bulkRecovery(): string {
        $instance=Server::get(IConfig::class)->getSystemValueString('instanceid');
        if($instance===''||!preg_match('/^[a-zA-Z0-9]+$/D',$instance)){throw new \RuntimeException('Bulk recovery storage is unavailable');}
        $path='appdata_'.$instance.'/forms_mcp_bulk_recovery';self::path('/'.$path,true);return $path;
    }

    public static function node(Node $node): void {
        self::storage($node->getStorage());
        // Resolve the live mount too. Cached FileInfo objects can retain a previous wrapper stack.
        self::path($node->getPath());
    }

    /** Admit a shared Files mount only as the original linked export destination. */
    public static function linkedDestination(Node $node, string $uid, string $path, bool $create, string $originTool): void {
        if ($originTool !== 'forms_link_results_file' || $uid === '' || str_contains($uid, '/') || str_contains($uid, "\0")) {
            throw new \RuntimeException('Forms linked destination authority is unavailable');
        }
        $nodePath = rtrim($node->getPath(), '/') ?: '/';
        $expectedPath = $create ? rtrim($nodePath, '/') . '/' . basename($path) : $nodePath;
        if ($path !== $expectedPath) {
            throw new \RuntimeException('Forms linked destination identity changed');
        }
        $mounts = Server::get(IMountManager::class);
        $mount = $mounts->find($nodePath);
        if ($mount === null || $mounts->find($path) !== $mount) {
            throw new \RuntimeException('Forms linked destination mount is unavailable');
        }
        if ($mount::class !== 'OCA\Files_Sharing\SharedMount') {
            self::node($node);
            self::path($path);
            return;
        }
        $homeRoot = '/' . $uid . '/files';
        if ($nodePath !== $homeRoot && !str_starts_with($nodePath, $homeRoot . '/')) {
            throw new \RuntimeException('Forms linked shared destination is outside the actor home');
        }
        self::source(new \ReflectionClass($mount::class));
        $storage = $mount->getStorage();
        $nodeStorage = $node->getStorage();
        if (!$storage instanceof IStorage || !$nodeStorage instanceof IStorage
            || $mount->getUser()->getUID() !== $uid
            || ($path !== rtrim($mount->getMountPoint(), '/') && !str_starts_with($path, rtrim($mount->getMountPoint(), '/') . '/'))
            || ($nodePath !== rtrim($mount->getMountPoint(), '/') && !str_starts_with($nodePath, rtrim($mount->getMountPoint(), '/') . '/'))) {
            throw new \RuntimeException('Forms linked shared mount identity changed');
        }
        $shared = self::sharedStorage($storage);
        if ($shared !== self::sharedStorage($nodeStorage)) {
            throw new \RuntimeException('Forms linked shared node mount changed');
        }
        $share = $mount->getShare();
        if ($shared->getShare()->getFullId() !== $share->getFullId() || $mount->getGroupedShares() !== []) {
            throw new \RuntimeException('Forms linked shared mount is not a direct share');
        }
        $required = \OCP\Constants::PERMISSION_READ
            | ($create ? \OCP\Constants::PERMISSION_CREATE : \OCP\Constants::PERMISSION_UPDATE);
        self::validateStorage($storage, $uid, $required);
    }

    public static function path(string $path, bool $descendants = false): void {
        self::configuration();
        $manager = Server::get(IMountManager::class);
        $mount = $manager->find($path);
        if ($mount === null || $mount->getStorage() === null) {
            throw new \RuntimeException('Forms storage mount is unavailable');
        }
        self::storage($mount->getStorage());
        if ($descendants) {
            $children = $manager->findIn($path);
            if (count($children) > 100) {
                throw new \RuntimeException('Forms storage mount limit exceeded');
            }
            foreach ($children as $child) {
                $storage = $child->getStorage();
                if ($storage === null) {
                    throw new \RuntimeException('Forms storage mount is unavailable');
                }
                self::storage($storage);
            }
        }
    }

    public static function storage(IStorage $storage): void {
        self::validateStorage($storage, null, 0);
    }

    private static function validateStorage(IStorage $storage, ?string $recipientUid, int $requiredPermissions): void {
        self::configuration();
        $seen = [];
        $rank = 0;
        $wrappers = [];
        for ($depth = 0; $depth < 256; $depth++) {
            if (isset($seen[spl_object_id($storage)])) {
                throw new \RuntimeException('Forms storage wrapper cycle is unavailable');
            }
            $seen[spl_object_id($storage)] = true;
            $class = $storage::class;
            if ($class === ManagedFileStorage::class || $class === NewFileStorage::class || $class === MutationBoundaryStorage::class || $class === NativeHomeStorage::class) {
                // These exact final classes are covered by the independently pinned companion manifest.
                $storage = $storage->getWrapperStorage();
                continue;
            }
            if ($class === 'OCA\Files_Sharing\SharedStorage') {
                if ($recipientUid === null || $requiredPermissions === 0) {
                    throw new \RuntimeException('Forms shared storage is unqualified outside linked destinations');
                }
                self::shared($storage, $recipientUid, $requiredPermissions);
                $maskStorage = $storage->getWrapperStorage();
                if ($maskStorage::class !== 'OC\Files\Storage\Wrapper\PermissionsMask') {
                    throw new \RuntimeException('Forms shared permission mask is unqualified');
                }
                self::source(new \ReflectionClass($maskStorage::class));
                self::validateStorage($maskStorage->getWrapperStorage(), null, 0);
                return;
            }
            if (isset(self::WRAPPERS[$class])) {
                if (self::WRAPPERS[$class] <= $rank) {
                    throw new \RuntimeException('Forms storage wrapper configuration is unqualified');
                }
                self::source(new \ReflectionClass($class));
                if ($class === 'OC\Files\Storage\Wrapper\PermissionsMask') {
                    $mask = (new \ReflectionProperty($storage, 'mask'))->getValue($storage);
                    if (!is_int($mask) || $mask < 0 || $mask > \OCP\Constants::PERMISSION_ALL) {
                        throw new \RuntimeException('Forms storage permissions configuration is unqualified');
                    }
                }
                $rank = self::WRAPPERS[$class];
                $wrappers[] = $class;
                $storage = $storage->getWrapperStorage();
                continue;
            }
            if (!in_array($class, self::BACKENDS, true)) {
                throw new \RuntimeException('Forms storage implementation is unqualified');
            }
            self::source(new \ReflectionClass($class));
            if ($class === 'OCA\Files_External\Lib\Storage\AmazonS3') {
                if (in_array('OC\Files\Storage\Wrapper\Quota', $wrappers, true)) {
                    throw new \RuntimeException('Forms S3 wrapper configuration is unqualified');
                }
                self::s3($storage);
            } else {
                if (in_array('OC\Files\Storage\Wrapper\Availability', $wrappers, true)) {
                    throw new \RuntimeException('Forms local wrapper configuration is unqualified');
                }
            }
            return;
        }
        throw new \RuntimeException('Forms storage wrapper limit exceeded');
    }

    private static function sharedStorage(IStorage $storage): \OCA\Files_Sharing\SharedStorage {
        $seen = [];
        for ($depth = 0; $depth < 16; $depth++) {
            if (isset($seen[spl_object_id($storage)])) {
                throw new \RuntimeException('Forms shared destination wrapper cycle');
            }
            $seen[spl_object_id($storage)] = true;
            if ($storage::class === 'OCA\Files_Sharing\SharedStorage') {
                return $storage;
            }
            if (!in_array($storage::class, [ManagedFileStorage::class, NewFileStorage::class, MutationBoundaryStorage::class, NativeHomeStorage::class], true)) {
                throw new \RuntimeException('Forms linked destination wrapper is unqualified');
            }
            $storage = $storage->getWrapperStorage();
        }
        throw new \RuntimeException('Forms linked destination wrapper limit exceeded');
    }

    private static function shared(IStorage $storage, string $uid, int $requiredPermissions): void {
        self::source(new \ReflectionClass($storage::class));
        $share = $storage->getShare();
        $grouped = (new \ReflectionProperty($storage, 'groupedShares'))->getValue($storage);
        $recipient = (new \ReflectionProperty($storage, 'user'))->getValue($storage);
        $permissions = $share->getPermissions();
        if (!is_array($grouped) || $grouped !== [] || $recipient !== $uid
            || $share->getShareType() !== \OCP\Share\IShare::TYPE_USER
            || $share->getSharedWith() !== $uid || !is_int($permissions)
            || $share->getExpirationDate() !== null
            || ($permissions & $requiredPermissions) !== $requiredPermissions) {
            throw new \RuntimeException('Forms linked shared destination permissions are unavailable');
        }
        $manager = Server::get(\OCP\Share\IManager::class);
        if ($manager::class !== 'OC\Share20\Manager') {
            throw new \RuntimeException('Forms native share reader is unqualified');
        }
        self::source(new \ReflectionClass($manager::class));
        $factory = (new \ReflectionProperty($manager, 'factory'))->getValue($manager);
        if ($factory::class !== 'OC\Share20\ProviderFactory') {
            throw new \RuntimeException('Forms native share provider factory is unqualified');
        }
        self::source(new \ReflectionClass($factory::class));
        $provider = $factory->getProvider('ocinternal');
        if ($provider::class !== 'OC\Share20\DefaultShareProvider') {
            throw new \RuntimeException('Forms native share provider is unqualified');
        }
        self::source(new \ReflectionClass($provider::class));
        $fresh = $manager->getShareById($share->getFullId(), $uid);
        if ($fresh::class !== 'OC\Share20\Share') {
            throw new \RuntimeException('Forms native share identity is unqualified');
        }
        self::source(new \ReflectionClass($fresh::class));
        $identity = static fn ($value): array => [
            $value->getFullId(), $value->getId(), $value->getShareType(), $value->getSharedWith(),
            $value->getShareOwner(), $value->getSharedBy(), $value->getNodeId(), $value->getNodeType(),
            $value->getTarget(), $value->getPermissions(),
        ];
        if ($fresh::class !== $share::class || $fresh->getExpirationDate() !== null || $identity($fresh) !== $identity($share)) {
            throw new \RuntimeException('Forms linked shared destination ACL changed');
        }
        $maskStorage = $storage->getWrapperStorage();
        if ($maskStorage::class !== 'OC\Files\Storage\Wrapper\PermissionsMask') {
            throw new \RuntimeException('Forms shared permission mask is unqualified');
        }
        self::source(new \ReflectionClass($maskStorage::class));
        $mask = (new \ReflectionProperty($maskStorage, 'mask'))->getValue($maskStorage);
        if ($mask !== $fresh->getPermissions()) {
            throw new \RuntimeException('Forms linked shared permission mask changed');
        }
    }

    private static function configuration(): void {
        $config = Server::get(IConfig::class);
        if ($config->getSystemValue('filelocking.enabled', true) !== true
            || $config->getSystemValue('memcache.locking', '') !== ''
            || $config->getSystemValue('filelocking.excluded', []) !== []
            || $config->getSystemValue('filelocking.debug', false) !== false
            || $config->getSystemValue('objectstore', []) !== []
            || $config->getSystemValue('objectstore_multibucket', []) !== []
            || $config->getSystemValue('localstorage.case_insensitive', false) !== false
            || $config->getSystemValue('localstorage.unlink_on_truncate', false) !== false
            || $config->getSystemValue('localstorage.umask', 0022) !== 0022
            || Server::get(\OCP\Encryption\IManager::class)->isEnabled()) {
            throw new \RuntimeException('Forms storage runtime configuration is unqualified');
        }
    }

    private static function source(\ReflectionClass $class): void {
        $name = $class->getName();
        $file = $class->getFileName();
        if (!isset(self::SOURCES[$name]) || $file === false || is_link($file)
            || !hash_equals(self::SOURCES[$name], hash_file('sha256', $file))) {
            throw new \RuntimeException('Forms storage source is unqualified');
        }
        foreach ($class->getTraits() as $trait) {
            self::source($trait);
        }
        $parent = $class->getParentClass();
        if ($parent !== false) {
            self::source($parent);
        }
    }

    /** Inspect only the pinned backend's effective configuration. Credentials never leave this method. */
    private static function s3(IStorage $storage): void {
        $params = (new \ReflectionProperty($storage, 'params'))->getValue($storage);
        $allowed = ['bucket', 'hostname', 'port', 'region', 'key', 'secret', 'use_ssl', 'use_path_style',
            'legacy_auth', 'useMultipartCopy', 'use_presigned_url', 'storageClass', 'sse_c_key', 'proxy',
            's3-accelerate', 'verify_bucket_exists', 'concurrency', 'connect_timeout', 'timeout',
            'uploadPartSize', 'putSizeLimit', 'copySizeLimit', 'retriesMaxAttempts'];
        if (array_diff(array_keys($params), $allowed) !== []
            || empty($params['bucket']) || empty($params['hostname']) || empty($params['key']) || empty($params['secret'])
            || ($params['use_path_style'] ?? false) !== true
            || !is_bool($params['use_ssl'] ?? true)
            || !empty($params['legacy_auth']) || !empty($params['use_presigned_url'])
            || !empty($params['sse_c_key']) || !empty($params['proxy']) || !empty($params['s3-accelerate'])
            || ($params['verify_bucket_exists'] ?? true) !== true) {
            throw new \RuntimeException('Forms S3 configuration is unqualified');
        }
        $bounds = ['concurrency' => [1, 5], 'connectTimeout' => [0.1, 5], 'timeout' => [1, 15],
            'retriesMaxAttempts' => [1, 5], 'putSizeLimit' => [AnswerFiles::MAX_BYTES, 104857600],
            'copySizeLimit' => [AnswerFiles::MAX_BYTES, 5368709120], 'uploadPartSize' => [AnswerFiles::MAX_BYTES, 524288000]];
        foreach ($bounds as $property => [$minimum, $maximum]) {
            $value = (new \ReflectionProperty($storage, $property))->getValue($storage);
            if (!is_numeric($value) || $value < $minimum || $value > $maximum) {
                throw new \RuntimeException('Forms S3 transfer configuration is unqualified');
            }
        }
        if ((new \ReflectionProperty($storage, 'storageClass'))->getValue($storage) !== 'STANDARD'
            || (new \ReflectionProperty($storage, 'test'))->getValue($storage) !== false) {
            throw new \RuntimeException('Forms S3 transfer configuration is unqualified');
        }
    }
}
