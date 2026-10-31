<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// All paths refer to the disposable LinuxServer app container.
const APPS = '/config/www/nextcloud/apps';
const MCP = APPS . '/mcp';
const HOLD = '/config/www/nextcloud/mount-trial-displaced';
const STAGE = '/config/www/nextcloud/mount-trial-staged';
const SHADOW = '/config/www/nextcloud/mount-trial-shadow/mcp';
const OVERRIDE = '/config/www/nextcloud/config/zz-mount-trial.config.php';
const CLASS_FILE = '/lib/AppInfo/Application.php';

function must(bool $ok): void {
    if (!$ok) throw new RuntimeException('Fixture preparation or reversal failed');
}
function directMutations(): array {
    $manifest = MCP . '/appinfo/info.xml';
    $bytes = file_get_contents($manifest);
    must(is_string($bytes) && $bytes !== '');
    $new = MCP . '/.mount-trial-create';
    $moved = MCP . '/appinfo/.mount-trial-moved';
    $displaced = APPS . '/.mount-trial-mcp';
    foreach ([$new, $moved, $displaced] as $file) must(!file_exists($file) && !is_link($file));
    $result['append'] = @file_put_contents($manifest, "\n", FILE_APPEND) === 1;
    if ($result['append']) must(file_put_contents($manifest, $bytes) === strlen($bytes));
    $result['create'] = @file_put_contents($new, 'test') === 4;
    if ($result['create']) must(unlink($new));
    $result['renameFile'] = @rename($manifest, $moved);
    if ($result['renameFile']) must(rename($moved, $manifest));
    $result['unlink'] = @unlink($manifest);
    if ($result['unlink']) must(file_put_contents($manifest, $bytes) === strlen($bytes));
    $result['renameMountpoint'] = @rename(MCP, $displaced);
    if ($result['renameMountpoint']) must(rename($displaced, MCP));
    must(file_get_contents($manifest) === $bytes);
    return $result;
}
function makeAppRoot(string $root, string $otherApps): void {
    must(mkdir($root));
    foreach (new DirectoryIterator(APPS) as $entry) {
        if ($entry->isDot()) continue;
        $name = $entry->getFilename();
        must(symlink($name === 'mcp' ? SHADOW : $otherApps . '/' . $name, $root . '/' . $name));
    }
}
function removeAppRoot(string $root): void {
    foreach (new DirectoryIterator($root) as $entry) {
        if (!$entry->isDot()) must($entry->isLink() && unlink($entry->getPathname()));
    }
    must(rmdir($root));
}
function boot(): \OCP\App\IAppManager {
    define('OC_CONSOLE', true);
    require '/app/www/public/lib/base.php';
    return \OCP\Server::get(\OCP\App\IAppManager::class);
}

if (posix_geteuid() !== 1000 || !preg_match('/^CapEff:\s*0+$/m', file_get_contents('/proc/self/status'))) {
    throw new RuntimeException('Expected the unprivileged fixture service account');
}
switch ($argv[1]) {
    case 'resolve':
        $manager = boot();
        $manager->loadApp('mcp');
        $dir = realpath($manager->getAppPath('mcp'));
        $class = realpath((new ReflectionClass(\OCA\Mcp\AppInfo\Application::class))->getFileName());
        must($dir !== false && $class === $dir . CLASS_FILE);
        $result = ['path' => $dir, 'loadedClassSha256' => hash_file('sha256', $class),
            'version' => $manager->getAppVersion('mcp'), 'uid' => posix_geteuid()];
        break;
    case 'direct':
        $result = directMutations();
        break;
    case 'swap-parent':
        must(!file_exists(HOLD) && !is_link(HOLD));
        makeAppRoot(STAGE, HOLD);
        $swapped = @rename(APPS, HOLD);
        if ($swapped) {
            if (!rename(STAGE, APPS)) { must(rename(HOLD, APPS)); throw new RuntimeException('Cannot activate staged root'); }
        } else {
            removeAppRoot(STAGE);
        }
        $result = ['swapped' => $swapped];
        break;
    case 'undo-swap':
        must(is_link(APPS . '/mcp') && readlink(APPS . '/mcp') === SHADOW && is_dir(HOLD));
        must(rename(APPS, STAGE) && rename(HOLD, APPS));
        removeAppRoot(STAGE);
        $result = ['restored' => true];
        break;
    case 'redirect':
        boot();
        $roots = \OC::$APPSROOTS;
        $changed = false;
        makeAppRoot(STAGE, APPS);
        foreach ($roots as &$root) {
            if (realpath($root['path'] . '/mcp') === MCP) {
                $root['path'] = STAGE;
                $changed = true;
            }
        }
        unset($root);
        must($changed && !file_exists(OVERRIDE) && !is_link(OVERRIDE));
        $config = "<?php\n\$CONFIG = " . var_export(['apps_paths' => $roots], true) . ";\n";
        $result = ['redirected' => @file_put_contents(OVERRIDE, $config) === strlen($config)];
        if (!$result['redirected']) removeAppRoot(STAGE);
        break;
    case 'undo-redirect':
        must(unlink(OVERRIDE));
        removeAppRoot(STAGE);
        $result = ['restored' => true];
        break;
    default:
        throw new RuntimeException('Unexpected mount probe');
}
echo 'MCP_MOUNT=' . json_encode($result, JSON_THROW_ON_ERROR) . "\n";
