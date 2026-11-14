<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Admission only. Native Files setup stays owned by the isolated original-actor process. */
final class DeferredFileContext {
    private static function session(string $uid): void {
        if (PHP_SAPI!=='cli' || $uid==='' || Server::get(\OCP\IUserSession::class)->getUser()!==null) {
            throw new \RuntimeException('Deferred linked Forms requires an isolated null-session CLI process');
        }
    }
    private static function sources(string $uid): void {
        $sources=[
            \OC\Files\Filesystem::class=>'6d0ac1886352c2e60af7f7055cf46a9388a11d1b3d2977acd81ae92dfe4fa8e9',
            \OC\Files\View::class=>'244363c3cafb9f3e9a4c2e7b0bdcbf5cf3aa369bec1e33411322104b4d723e97',
            \OC\Files\SetupManager::class=>'02c54af1b5faf40268dad0a7c00405cb1dc8e7b8b9869b88979528616075fc3e',
            \OC\Files\Node\Root::class=>'6ef05f209edb13bd7d0e125b3ddff5d37cd02cf4b1afc3b3695ba00ea3691c4f',
        ];
        foreach ($sources as $class=>$hash) {
            $file=(new \ReflectionClass($class))->getFileName();
            if ($file===false || is_link($file) || !hash_equals($hash,hash_file('sha256',$file))) {throw new \RuntimeException('Deferred Files runtime changed');}
        }
        $apps=Server::get(\OCP\App\IAppManager::class);
        $user=Server::get(\OCP\IUserManager::class)->get($uid);
        if ($user!==null && $apps->isEnabledForUser('files_versions',$user)) {
            $versionRoot=$apps->getAppPath('files_versions').'/lib/';
            $versionSources=[
                'Versions/VersionManager.php'=>'767fd5ef116935d18d5a9c8c88bb3926a11bc3bb757ffc86e91be47ef54784f6',
                'Versions/LegacyVersionsBackend.php'=>'a64e7d21976a07678618a0b3a3464b4e1e2d96b4142f4a9f4e94ca1dd920d30f',
                'Versions/IVersionManager.php'=>'7d3f8fdfdb8bc4f382156f077c975499f35f039021114d2ac48f0a6692a96740',
                'Versions/IVersionBackend.php'=>'ade449bfe3c820fd7e11e07e231e7142d9f8fd80f25213478dfd850979d27c81',
                'Listener/FileEventsListener.php'=>'187b1f6e102629bcbb40316d18f955edd8f8bd0c52cb5da14ca53a1cb8963433',
                'Listener/VersionAuthorListener.php'=>'b84daca1a31bdd299ae160c6be9f0714369101210a15ac841c21f97ca909d19f',
                'Storage.php'=>'7d8dc6c7142b4774c8f563ee581057d4e116c67988b6e7cbd3347224a16dfbf8',
                'Events/CreateVersionEvent.php'=>'11fc21ab922d574f45536727a766ad98b6c6bcdeaf5b3203a1f3aabad5d73fd5',
            ];
            foreach ($versionSources as $relative=>$hash) {
                $file=$versionRoot.$relative;
                if (is_link($file) || !is_file($file) || !hash_equals($hash,hash_file('sha256',$file))) {throw new \RuntimeException('Deferred native Versions source changed');}
            }
        }
    }
    public static function initialize(string $uid): void {
        self::session($uid);self::sources($uid);
        $root=\OC\Files\Filesystem::getRoot();
        if ($root!==null && $root!=='/'.$uid.'/files') {throw new \RuntimeException('Deferred linked Forms has an unrelated Files root');}
        // Force native lazy mount setup once, retaining an already initialized original root.
        Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->getId();
        self::assert($uid);
    }
    public static function assert(string $uid): void {
        self::session($uid);self::sources($uid);
        if (\OC\Files\Filesystem::getRoot()!=='/'.$uid.'/files') {
            throw new \RuntimeException('Deferred linked Forms original Files root is unavailable');
        }
        $view=\OC\Files\Filesystem::getView();
        if ($view::class!==\OC\Files\View::class || $view->getOwner('')!==$uid) {
            throw new \RuntimeException('Deferred linked Forms original Files root is unavailable');
        }
    }
}
