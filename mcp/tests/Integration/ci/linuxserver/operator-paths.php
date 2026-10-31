<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// All paths refer to the disposable LinuxServer app container.
const APPS = '/config/www/nextcloud/apps';
const MCP = APPS . '/mcp';
const HOLD = '/config/www/nextcloud/mount-trial-displaced';
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
    case 'anchors':
        $result = [];
        foreach (['/', '/app', '/app/www', '/app/www/public', '/app/www/public/config', '/config/nginx', '/config/php'] as $dir) {
            $test = rtrim($dir, '/') . '/.operator-path-write-test';
            must(is_dir($dir) && !file_exists($test) && !is_link($test));
            $fd = @fopen($test, 'xb');
            if ($fd !== false) { fclose($fd); must(unlink($test)); }
            $result[$dir] = ['ownedByService' => fileowner($dir) === posix_geteuid(), 'createdFile' => $fd !== false];
        }
        break;
    case 'paths':
        boot();
        // Test the same filesystem operations with valid sources on a disposable B1.
        $swap = @rename(APPS, HOLD);
        if ($swap) must(rename(HOLD, APPS));
        $roots = \OC::$APPSROOTS;
        $alternate = $roots;
        foreach ($alternate as &$entry) $entry['path'] = '/tmp/alternate-root';
        unset($entry);
        $fragment = "<?php\n\$CONFIG = " . var_export(['apps_paths' => $alternate], true) . ";\n";
        must(!file_exists(OVERRIDE));
        $redirect = @file_put_contents(OVERRIDE, $fragment) === strlen($fragment);
        if ($redirect) must(unlink(OVERRIDE));
        $config = dirname(OVERRIDE) . '/config.php';
        $stream = @fopen($config, 'r+b');
        $configWrite = $stream !== false;
        if ($configWrite) fclose($stream);
        $result = ['swapParent' => $swap, 'injectConfig' => $redirect, 'openConfigForWrite' => $configWrite,
            'canonicalConfig' => realpath(\OC::$configDir), 'appRoots' => array_map(
                static fn ($entry) => realpath($entry['path']), $roots)];
        break;
    default:
        throw new RuntimeException('Unexpected mount probe');
}
echo 'MCP_PATHS=' . json_encode($result, JSON_THROW_ON_ERROR) . "\n";
