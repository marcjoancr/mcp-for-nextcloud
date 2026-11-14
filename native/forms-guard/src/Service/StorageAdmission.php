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
        'OC\Files\Storage\Wrapper\Quota' => 'd5f4922f6356eda6ae631f28eb9f0142eec6c083e67b3f81215ff314cf6ca54e',
        'OC\Files\Storage\Wrapper\Availability' => 'a3491ad397032b5706e578bb0fce89df7cfaf49b09ec6e7f812018aedc977eb2',
        'OC\Files\Storage\Wrapper\PermissionsMask' => 'ebf2e1c2399901d9632d76be8d798480cf929308d339aa6b92a1ca2620e09752',
        'OCA\Files_Trashbin\Storage' => '02b0cb9c1c6e47d3c231ff4a9f83112c4668011b8a75623c8d527b26d69886af',
        'OCA\FilesLock\Storage\LockWrapper' => '185cc860224f1724691adf56316d4c679060b20472bd0f8f7ae902940487a95d',
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
            self::userPath($uid, 'Forms', true);
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
            self::userPath($input['owner_id'], $path, true);
        }
        if ($action !== 'forms_upload_response_files') {
            self::recovery();
        }
    }

    public static function userPath(string $uid, string $path, bool $descendants = false): void {
        $segments = explode('/', str_replace('\\', '/', $path));
        if (in_array('..', $segments, true) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new \RuntimeException('Forms storage path is unavailable');
        }
        $root = Server::get(IRootFolder::class)->getUserFolder($uid);
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        self::path($root->getPath() . '/' . implode('/', $segments), $descendants);
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
            if ($class === ManagedFileStorage::class || $class === NewFileStorage::class || $class === MutationBoundaryStorage::class) {
                // These exact final classes are covered by the independently pinned companion manifest.
                $storage = $storage->getWrapperStorage();
                continue;
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
