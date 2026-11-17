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
        'OC\Files\Node\LazyFolder' => '6e87c27666bfad3ab06d11e4181100ccc33f3d4a1e398055243f493e94ce6472',
        'OC\Files\Node\LazyRoot' => '027b3ed3a3611cce899cf93203d0d65b8d5cb0f4930efccf0aee18aa71d3cea7',
        'OC\Files\Node\LazyUserFolder' => 'd8f789d2ad73fb5f8ead28d44ea1d13cbf0fa0840822a1df47fa29e69089d024',
        'OC\Files\Node\File' => '3917379cde9cf1fec2fe672d24efdeb02c61276d1c9b4acaea2c33782dc6171d',
        'OC\Files\Node\Folder' => '8fb20d65fe53d0c8788225daff6365c49d679d463e0b379d6cc12dbbd65f542d',
        'OC\Files\Node\Node' => '74e4207bb794d9845f51c1b8ddb35a6f7fd168f50271ed90a96891ce2f53754e',
        'OC\Files\Node\Root' => '6ef05f209edb13bd7d0e125b3ddff5d37cd02cf4b1afc3b3695ba00ea3691c4f',
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
    /** Exact native prefix measured on qualified SharedMounts; all other stacks stay closed. */
    private const SHARED_MOUNT_WRAPPERS = [
        'OCA\Files_Trashbin\Storage' => 1,
        'OCA\FilesLock\Storage\LockWrapper' => 2,
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

    /** Verify a previously initialized default Local Home without resolving or creating a user folder. */
    public static function assertExistingHome(string $uid): void {
        self::userPath($uid, '', false, true);
        $home = self::prospectiveHomePath($uid);
        $files = $home . '/files';
        foreach ([$home, $files] as $path) {
            $stat = @lstat($path);
            $real = @realpath($path);
            if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
                || !is_string($real) || $real !== $path) {
                throw new \RuntimeException('Forms initialized Home metadata is unavailable');
            }
        }
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
        $required = \OCP\Constants::PERMISSION_READ
            | ($create ? \OCP\Constants::PERMISSION_CREATE : \OCP\Constants::PERMISSION_UPDATE);
        $shared = self::sharedStorage($storage);
        $nodeShared = self::sharedStorage($nodeStorage);
        $mountShare = self::shared($shared, $uid, $required);
        $nodeShare = self::shared($nodeShared, $uid, $required);
        if (!self::sameShareTuple(self::shareTuple($mountShare), self::shareTuple($nodeShare))) {
            throw new \RuntimeException('Forms linked shared node mount changed');
        }
        $storageGroupedShares = (new \ReflectionProperty($shared, 'groupedShares'))->getValue($shared);
        $mountGroupedShares = $mount->getGroupedShares();
        if ($shared->getShare() !== $mount->getShare() || !is_array($storageGroupedShares)
            || count($storageGroupedShares) !== 1 || !array_key_exists(0, $storageGroupedShares)
            || !is_array($mountGroupedShares) || $mountGroupedShares !== $storageGroupedShares) {
            throw new \RuntimeException('Forms linked shared mount is not a direct share');
        }
        self::validateStorage($storage, $uid, $required);
    }

    /** Resolve only an already initialized direct SharedStorage into its physical owner ancestry. */
    public static function linkedSharedAudience(Node $actorNode, string $uid, string $targetPath, bool $create): array {
        $mountPath=$targetPath;
        self::linkedDestination($actorNode,$uid,$targetPath,$create,'forms_link_results_file');
        $mounts=Server::get(IMountManager::class);$mount=$mounts->find($mountPath);
        if($mount===null||$mount::class!=='OCA\Files_Sharing\SharedMount')throw new \RuntimeException('Forms shared audience mount is unavailable');
        self::source(new \ReflectionClass($mount::class));
        $storage=$mount->getStorage();$shared=self::sharedStorage($storage);$syntheticShare=$shared->getShare();
        if($syntheticShare::class!=='OC\Share20\Share')throw new \RuntimeException('Forms shared audience share is unqualified');
        self::source(new \ReflectionClass($syntheticShare::class));
        $share=self::shared($shared,$uid,\OCP\Constants::PERMISSION_READ|($create?\OCP\Constants::PERMISSION_CREATE:\OCP\Constants::PERMISSION_UPDATE));
        $user=(new \ReflectionProperty($shared,'user'))->getValue($shared);
        $grouped=(new \ReflectionProperty($shared,'groupedShares'))->getValue($shared);
        $initialized=(new \ReflectionProperty($shared,'initialized'))->getValue($shared);
        $ownerFolder=(new \ReflectionProperty($shared,'ownerUserFolder'))->getValue($shared);
        $sourcePath=(new \ReflectionProperty($shared,'sourcePath'))->getValue($shared);
        $nonMasked=(new \ReflectionProperty($shared,'nonMaskedStorage'))->getValue($shared);
        $permissions=$share->getPermissions();$ownerUid=$share->getShareOwner();$sourceId=$share->getNodeId();
        if($initialized!==true||!is_string($user)||$user!==$uid||!is_array($grouped)||count($grouped)!==1||!array_key_exists(0,$grouped)
            ||!is_string($ownerUid)||$ownerUid===''||$ownerUid===$uid||!is_string($sourcePath)||$sourcePath===''||!is_int($sourceId)||$sourceId<1
            ||!is_int($permissions)||$permissions<0||$share->getShareType()!==\OCP\Share\IShare::TYPE_USER||$share->getSharedWith()!==$uid
            ||$share->getExpirationDate()!==null||!$nonMasked instanceof IStorage||!is_object($ownerFolder)
            ||!in_array($ownerFolder::class,['OC\Files\Node\Folder','OC\Files\Node\LazyUserFolder'],true))throw new \RuntimeException('Forms shared audience source is unavailable');
        self::source(new \ReflectionClass($ownerFolder::class));
        if($ownerFolder::class==='OC\Files\Node\Folder'){$realHome=$ownerFolder;}
        else{
            $folderProperty=new \ReflectionProperty(\OC\Files\Node\LazyFolder::class,'folder');
            $cachedHome=$folderProperty->getValue($ownerFolder);
            if($cachedHome!==null){$realHome=$cachedHome;}
            else{$realHome=$ownerFolder->get('');if($folderProperty->getValue($ownerFolder)!==null)throw new \RuntimeException('Forms shared audience lazy Home was materialized through a fallback');}
        }
        if(!$realHome instanceof \OC\Files\Node\Folder||$realHome::class!=='OC\Files\Node\Folder')throw new \RuntimeException('Forms shared audience owner Home is not already materialized');
        self::source(new \ReflectionClass($realHome::class));
        $homePath='/'.$ownerUid.'/files';$homeId=(int)$realHome->getId();
        if($homeId<1||$realHome->getPath()!==$homePath||!str_starts_with($sourcePath,$homePath.'/'))throw new \RuntimeException('Forms shared audience owner Home identity changed');
        $sourceRelative=ltrim(substr($sourcePath,strlen($homePath)),'/');
        $ownerMounts=Server::get(IMountManager::class);$sourceMount=$ownerMounts->find($sourcePath);
        if($sourceMount===null||$sourceMount->getStorage()!==$nonMasked)throw new \RuntimeException('Forms shared audience owner source mount is not already present');
        $sourceRoot=$realHome->get($sourceRelative);
        if((int)$sourceRoot->getId()!==$sourceId||$sourceRoot->getPath()!==$sourcePath||$sourceRoot->getOwner()?->getUID()!==$ownerUid
            ||$sourceRoot->getStorage()!==$nonMasked)throw new \RuntimeException('Forms shared audience source root changed');
        $mountPoint=rtrim($mount->getMountPoint(),'/');$actorPath=rtrim($actorNode->getPath(),'/');
        $absoluteTarget=$actorPath;
        if($mountPoint===''||($absoluteTarget!==$mountPoint&&!str_starts_with($absoluteTarget,$mountPoint.'/'))
            ||$actorPath!==dirname($targetPath)&&$create||$actorPath!==$targetPath&&!$create)throw new \RuntimeException('Forms shared audience target path changed');
        $suffix=substr($absoluteTarget,strlen($mountPoint));
        $physicalPath=rtrim($sourcePath,'/').$suffix;
        if($ownerMounts->find($physicalPath)!==$sourceMount)throw new \RuntimeException('Forms shared audience target crosses an unqualified owner mount');
        if($physicalPath===$sourcePath)$physicalNode=$sourceRoot;
        else {
            $relative=ltrim(substr($physicalPath,strlen($homePath)),'/');
            $physicalNode=$realHome->get($relative);
        }
        $expectedNodeType=$create?\OCP\Files\Folder::class:\OCP\Files\File::class;
        if(!$physicalNode instanceof Node||!$physicalNode instanceof $expectedNodeType||(int)$physicalNode->getId()!==(int)$actorNode->getId()
            ||$physicalNode->getPath()!==$physicalPath||$physicalNode->getOwner()?->getUID()!==$ownerUid||$physicalNode->getStorage()!==$nonMasked)
            throw new \RuntimeException('Forms shared audience physical target changed');
        $ids=[];$bindings=[];$node=$physicalNode;
        for($depth=0;;$depth++){
            if($depth>=100||$node->getOwner()?->getUID()!==$ownerUid||($node->getPath()!==$homePath&&!str_starts_with($node->getPath(),$homePath.'/')))throw new \RuntimeException('Forms shared audience ancestry is unavailable');
            $id=(int)$node->getId();if($id<1||isset($ids[$id]))throw new \RuntimeException('Forms shared audience ancestry is ambiguous');
            $ids[$id]=$id;$bindings[]=['id'=>$id,'path'=>$node->getPath()];
            if($id===$homeId)break;
            $parentPath=dirname($node->getPath());
            if($parentPath===$node->getPath())throw new \RuntimeException('Forms shared audience ancestry is unavailable');
            $relative=ltrim(substr($parentPath,strlen($homePath)),'/');
            $node=$relative===''?$realHome:$realHome->get($relative);
        }
        self::linkedDestination($actorNode,$uid,$targetPath,$create,'forms_link_results_file');
        if(Server::get(IMountManager::class)->find($mountPath)!==$mount||$mount->getStorage()!==$storage)throw new \RuntimeException('Forms shared audience mount changed');
        $generation=self::existingOwnerGeneration($ownerUid);
        $incarnation=hash('sha256',json_encode([$generation,$homeId,(string)$nonMasked->getId(),$sourceId],JSON_THROW_ON_ERROR));
        $destinationPermissions=$actorNode->getPermissions();
        if(!is_int($destinationPermissions)||$destinationPermissions<0||$destinationPermissions>\OCP\Constants::PERMISSION_ALL)throw new \RuntimeException('Forms shared audience permissions are unavailable');
        $destinationPermissions &= $permissions;
        $homePathForActor='/'.$uid.'/files';if(!str_starts_with($targetPath,$homePathForActor.'/'))throw new \RuntimeException('Forms shared audience path is unavailable');
        $identity=['share_id'=>(int)$share->getId(),'source_id'=>$sourceId,'mount'=>$mountPoint,'storage_id'=>(string)$storage->getId(),'path'=>substr($targetPath,strlen($homePathForActor)),'permissions'=>$permissions];
        return ['owner_uid'=>$ownerUid,'owner_incarnation_digest'=>$incarnation,'destination_permissions'=>$destinationPermissions,'ids'=>array_values($ids),'bindings'=>$bindings,'share_identity'=>$identity];
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
        $rank = 0;
        for ($depth = 0; $depth < 16; $depth++) {
            if (isset($seen[spl_object_id($storage)])) {
                throw new \RuntimeException('Forms shared destination wrapper cycle');
            }
            $seen[spl_object_id($storage)] = true;
            if ($storage::class === 'OCA\Files_Sharing\SharedStorage') {
                return $storage;
            }
            $class = $storage::class;
            if (in_array($class, [ManagedFileStorage::class, NewFileStorage::class, MutationBoundaryStorage::class, NativeHomeStorage::class], true)) {
                $storage = $storage->getWrapperStorage();
                continue;
            }
            if (!isset(self::SHARED_MOUNT_WRAPPERS[$class]) || self::SHARED_MOUNT_WRAPPERS[$class] <= $rank) {
                throw new \RuntimeException('Forms linked destination wrapper is unqualified');
            }
            self::source(new \ReflectionClass($class));
            $rank = self::SHARED_MOUNT_WRAPPERS[$class];
            $storage = $storage->getWrapperStorage();
        }
        throw new \RuntimeException('Forms linked destination wrapper limit exceeded');
    }

    /** Read an existing lifecycle witness; audience preview never enrolls or rotates identities. */
    private static function existingOwnerGeneration(string $uid):string {
        if(!NativeHistoryIdentity::ready())throw new \RuntimeException('Forms shared audience owner incarnation is unavailable');
        $result=Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT uid,generation FROM *PREFIX*forms_mcp_incarnations WHERE uid_hash=? LIMIT 2',[hash('sha256',"forms-user\0".$uid)]);
        try{$rows=$result->fetchAll();}finally{$result->closeCursor();}
        if(count($rows)!==1||($rows[0]['uid']??null)!==$uid||!is_string($rows[0]['generation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$rows[0]['generation']))throw new \RuntimeException('Forms shared audience owner incarnation is unavailable');
        NativeHistoryIdentity::assertCurrent($uid,$rows[0]['generation']);return $rows[0]['generation'];
    }

    private static function shared(IStorage $storage, string $uid, int $requiredPermissions): \OC\Share20\Share {
        self::source(new \ReflectionClass($storage::class));
        $share = $storage->getShare();
        $grouped = (new \ReflectionProperty($storage, 'groupedShares'))->getValue($storage);
        $recipient = (new \ReflectionProperty($storage, 'user'))->getValue($storage);
        $permissions = $share->getPermissions();
        $rawId = $share->getId();
        $component = is_array($grouped) && count($grouped) === 1 && array_key_exists(0, $grouped) ? $grouped[0] : null;
        if (!is_array($grouped) || count($grouped) !== 1 || !array_key_exists(0, $grouped)
            || !$component instanceof \OCP\Share\IShare || $recipient !== $uid
            || $share::class !== 'OC\Share20\Share' || !is_string($rawId)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', $rawId) || (string)(int)$rawId !== $rawId
            || $share->getShareType() !== \OCP\Share\IShare::TYPE_USER || !is_int($permissions)
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
        // SharedMount's pinned buildSuperShares() creates an ephemeral Share with an ID
        // but no provider ID. The pinned Manager resolves the canonical ID through this
        // provider while preserving its recipient and current-validity checks.
        $canonicalId = 'ocinternal:' . $rawId;
        $fresh = $manager->getShareById($canonicalId, $uid);
        if (!$fresh instanceof \OCP\Share\IShare || $fresh::class !== 'OC\Share20\Share') {
            throw new \RuntimeException('Forms native share identity is unqualified');
        }
        self::source(new \ReflectionClass($fresh::class));
        if ($fresh->getFullId() !== $canonicalId || $fresh->getId() !== $rawId
            || $fresh->getShareType() !== \OCP\Share\IShare::TYPE_USER || $fresh->getSharedWith() !== $uid
            || $fresh->getShareOwner() !== $share->getShareOwner()
            || $fresh->getNodeId() !== $share->getNodeId()
            || $fresh->getTarget() !== $share->getTarget() || $fresh->getPermissions() !== $permissions
            || $fresh->getStatus() !== $share->getStatus()
            || $fresh->getExpirationDate() !== null) {
            throw new \RuntimeException('Forms linked shared destination ACL changed');
        }
        if ($component::class !== 'OC\Share20\Share' || $component->getFullId() !== $canonicalId
            || $component->getId() !== $rawId || $component->getShareType() !== \OCP\Share\IShare::TYPE_USER
            || $component->getSharedWith() !== $uid || $component->getShareOwner() !== $fresh->getShareOwner()
            || $component->getNodeId() !== $fresh->getNodeId() || $component->getTarget() !== $fresh->getTarget()
            || $component->getPermissions() !== $fresh->getPermissions() || $component->getStatus() !== $fresh->getStatus()
            || $component->getExpirationDate() !== null) {
            throw new \RuntimeException('Forms linked shared component identity changed');
        }
        self::initializeLinkedSharedStorage($storage, $fresh, $uid);
        $maskStorage = $storage->getWrapperStorage();
        if ($maskStorage::class !== 'OC\Files\Storage\Wrapper\PermissionsMask') {
            throw new \RuntimeException('Forms shared permission mask is unqualified');
        }
        self::source(new \ReflectionClass($maskStorage::class));
        $mask = (new \ReflectionProperty($maskStorage, 'mask'))->getValue($maskStorage);
        if ($mask !== $fresh->getPermissions()) {
            throw new \RuntimeException('Forms linked shared permission mask changed');
        }
        return $fresh;
    }

    /** Stable identity for two separately materialized views of the same current direct share. */
    private static function shareTuple(\OCP\Share\IShare $share): array {
        if ($share::class !== 'OC\\Share20\\Share') {
            throw new \RuntimeException('Forms linked share tuple is unqualified');
        }
        return [
            'full_id' => $share->getFullId(),
            'id' => $share->getId(),
            'type' => $share->getShareType(),
            'recipient' => $share->getSharedWith(),
            'shared_by' => $share->getSharedBy(),
            'owner' => $share->getShareOwner(),
            'node_id' => $share->getNodeId(),
            'target' => $share->getTarget(),
            'permissions' => $share->getPermissions(),
            'status' => $share->getStatus(),
            'expiration' => $share->getExpirationDate(),
        ];
    }

    /** Require the same complete actor/share/source/ACL tuple; no class-only alias is admitted. */
    private static function sameShareTuple(array $left, array $right): bool {
        $keys = ['full_id','id','type','recipient','shared_by','owner','node_id','target','permissions','status','expiration'];
        return array_keys($left) === $keys && array_keys($right) === $keys && $left === $right;
    }

    /** Initialize only the selected direct share, with native Home lookup unable to create a missing owner root. */
    private static function initializeLinkedSharedStorage(\OCA\Files_Sharing\SharedStorage $storage, \OC\Share20\Share $share, string $uid): void {
        $initialized = new \ReflectionProperty($storage, 'initialized');
        if ($initialized->getValue($storage) === true) {return;}
        $ownerUid = $share->getShareOwner();
        if ($initialized->getValue($storage) !== false || $share->getShareType() !== \OCP\Share\IShare::TYPE_USER
            || $share->getSharedWith() !== $uid || !is_string($ownerUid) || $ownerUid === $uid || $ownerUid === ''
            || $share->getNodeId() < 1 || $share->getExpirationDate() !== null) {
            throw new \RuntimeException('Forms linked shared source identity is unavailable');
        }
        self::source(new \ReflectionClass($storage::class));
        $rootProperty = new \ReflectionProperty($storage, 'rootFolder');
        $root = $rootProperty->getValue($storage);
        $serverRoot = Server::get(IRootFolder::class);
        if (!self::qualifiedSharedRootBinding($root, $serverRoot)) {
            throw new \RuntimeException('Forms linked shared root binding is unqualified');
        }
        self::source(new \ReflectionClass($root::class));
        $homePath = '/' . $ownerUid . '/files';
        $home = $root->get($homePath);
        if (!$home instanceof \OC\Files\Node\Folder || $home::class !== \OC\Files\Node\Folder::class
            || $home->getPath() !== $homePath || $home->getOwner()?->getUID() !== $ownerUid
            || (int)$home->getId() < 1) {
            throw new \RuntimeException('Forms linked shared owner Home is unavailable');
        }
        self::source(new \ReflectionClass($home::class));
        $boundedRoot = new FinalShareHomeRoot($ownerUid, $home);
        $rootProperty->setValue($storage, $boundedRoot);
        try {
            $masked = $storage->getWrapperStorage();
        } finally {
            $rootProperty->setValue($storage, $root);
        }
        if ($masked::class !== 'OC\Files\Storage\Wrapper\PermissionsMask'
            || $initialized->getValue($storage) !== true
            || (new \ReflectionProperty($storage, 'ownerUserFolder'))->getValue($storage) !== $home) {
            throw new \RuntimeException('Forms linked shared initialization did not bind the existing Home');
        }
        self::source(new \ReflectionClass($masked::class));
        $sourcePath = (new \ReflectionProperty($storage, 'sourcePath'))->getValue($storage);
        $nonMasked = (new \ReflectionProperty($storage, 'nonMaskedStorage'))->getValue($storage);
        if (!is_string($sourcePath) || !str_starts_with($sourcePath, $homePath . '/')
            || !$nonMasked instanceof IStorage || $masked->getWrapperStorage() !== $nonMasked) {
            throw new \RuntimeException('Forms linked shared source storage is unavailable');
        }
        $mount = Server::get(IMountManager::class)->find($sourcePath);
        if ($mount === null || $mount->getStorage() !== $nonMasked) {
            throw new \RuntimeException('Forms linked shared source mount is unavailable');
        }
    }

    private static function qualifiedSharedRootBinding(mixed $root, mixed $serverRoot): bool {
        return is_object($root) && $root === $serverRoot
            && in_array($root::class, [\OC\Files\Node\Root::class, \OC\Files\Node\LazyRoot::class], true);
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
