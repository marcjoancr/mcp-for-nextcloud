<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationHarnessTest extends TestCase {
    /** @return array{int,string,string} */
    private function invoke(array $arguments): array {
        $app = dirname(__DIR__, 3);
        $process = proc_open(['sh', $app . '/tests/Integration/ci/run-local.sh', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }

    public function testPlanResolvesPinnedCellsWithoutStartingContainers(): void {
        foreach ([[32, 'mariadb', 'fresh'], [33, 'mariadb', 'upgrade'], [34, 'mariadb', 'fresh'],
            [35, 'pgsql', 'upgrade'], [35, 'sqlite', 'fresh'], [35, 'mariadb', 'upgrade-local'],
            [35, 'mariadb', 'replace-upstream'], [35, 'mariadb', 'upgrade-upstream'], [35, 'mariadb', 'upgrade-upstream-111']] as [$nc, $db, $mode]) {
            [$status, $out, $error] = $this->invoke(['--plan', (string)$nc, $db, $mode]);
            self::assertSame(0, $status, $error);
            $plan = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame((string)$nc, $plan['nc']);
            self::assertSame($db, $plan['db']);
            self::assertSame($mode, $plan['mode']);
            self::assertMatchesRegularExpression('/@sha256:[a-f0-9]{64}$/', $plan['nc_image']);
            self::assertFalse($plan['publishes_ports']);
            self::assertTrue($plan['disposable']);
            if ($mode === 'upgrade') self::assertSame('a1a7911cc72dcf2487d17e06e23edfa89b0ecf92', $plan['baseline_commit']);
            if ($mode === 'upgrade-local') self::assertSame('1f0b4232e18b2c778d3585b878116d1cabb93d05', $plan['baseline_commit']);
            if ($mode === 'replace-upstream') self::assertSame('27da5e107ca06d92b7af9b8a80ad9c77c12ba91e', $plan['baseline_commit']);
            if ($mode === 'upgrade-upstream-111') self::assertSame('65e4e08b68c3f8d1ba78398a082ec567b0b0f4e4', $plan['baseline_commit']);
            if ($mode === 'upgrade-upstream') self::assertSame('0911f9123a661968e707fc4c391adcf9d37c3e84', $plan['baseline_commit']);
        }
    }

    public function testNativeProbeRequiresExplicitCompletionEvenWhenHostHandlerExitsSuccessfully(): void {
        $guard = dirname(__DIR__, 3) . '/tests/Integration/Support/ProbeCompletion.php';
        foreach ([false, true] as $complete) {
            $code = 'require ' . var_export($guard, true) . '; $probe = new OCA\\Mcp\\Tests\\Integration\\Support\\ProbeCompletion();'
                . ($complete ? '$probe->complete();' : 'try { throw new RuntimeException("host-handled failure"); } catch (Throwable $e) { exit(0); }');
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame($complete ? 0 : 1, proc_close($process), $error);
            self::assertSame('', $out);
            self::assertSame($complete ? '' : "Native probe did not complete.\n", $error);
        }
    }

    /** @return array{int,string,string,string,list<list<string>>,string} */
    private function installWithAppStoreControl(string $mode): array {
        $app = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/mcp-install-guard-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        mkdir($directory . '/mcp-it', 0700);
        file_put_contents($directory . '/plan.json', '{}');
        file_put_contents($directory . '/mcp-it/credentials', "DB_PASSWORD=unused\nDB_ROOT_PASSWORD=unused\nADMIN_PASSWORD=unused\n");
        file_put_contents($directory . '/appstore', 'true');
        file_put_contents($directory . '/effects', '');
        // Replace only Docker: package extraction and the actual harness shell still run.
        file_put_contents($directory . '/bin/docker', '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$directory = getenv('MCP_GUARD_DIRECTORY');
$mode = getenv('MCP_GUARD_MODE');
$args = array_slice($argv, 1);
$position = array_search('/var/www/html/occ', $args, true);
$occ = $position === false ? [] : array_slice($args, $position + 1);
if (($occ[0] ?? '') === 'config:system:set' && ($occ[1] ?? '') === 'appstoreenabled') {
    if ($mode === 'set-fails') exit(23);
    if (in_array('--value=false', $occ, true) && in_array('--type=boolean', $occ, true) && $mode !== 'ignored') {
        file_put_contents($directory . '/appstore', $mode === 'string' ? '"false"' : 'false');
    }
    exit(0);
}
if (($occ[0] ?? '') === 'config:system:get' && ($occ[1] ?? '') === 'appstoreenabled') {
    if ($mode === 'unreadable') exit(24);
    echo file_get_contents($directory . '/appstore'), "\n";
    exit(0);
}
file_put_contents($directory . '/effects', json_encode($args) . "\n", FILE_APPEND);
if (file_get_contents($directory . '/appstore') !== 'false') {
    fwrite(STDERR, "App operation reached Docker before App Store was disabled.\n");
    exit(25);
}
switch ($occ[0] ?? '') {
    case 'app:enable': file_put_contents($directory . '/version', '1.0.8'); break;
    case 'upgrade': file_put_contents($directory . '/version', '1.1.2'); break;
    case 'config:app:get': echo file_get_contents($directory . '/version'), "\n"; break;
    case 'app:list': echo "  - mcp: enabled\n"; break;
    case 'status': echo '{"installed":true}', "\n"; break;
}
PHP);
        chmod($directory . '/bin/docker', 0700);
        try {
            foreach (['baseline' => '1.0.8', 'branch' => '1.1.2'] as $package => $version) {
                $archive = new \PharData($directory . '/mcp-it/mcp-' . $package . '.tar');
                $archive->addFromString('mcp/appinfo/info.xml', '<info><version>' . $version . '</version></info>');
                $archive->compress(\Phar::GZ);
                unset($archive);
            }
            $environment = array_merge(getenv(), [
                'PATH' => $directory . '/bin:' . getenv('PATH'),
                'MCP_IT_LOCAL' => '1', 'RUNNER_TEMP' => $directory,
                'MCP_IT_RUN_ID' => 'guard-test', 'MCP_IT_NET' => 'guard-test-net',
                'MCP_IT_NC_NAME' => 'guard-test-nc', 'MCP_IT_DB_NAME' => 'guard-test-db',
                'MCP_IT_NC_IMAGE' => 'unused', 'MCP_IT_BASELINE' => 'fixture', 'MCP_IT_MODE' => 'upgrade',
                'MCP_IT_QUALIFICATION_VERSION' => '', 'MCP_IT_PHPUNIT_ARGS' => '',
                'MCP_GUARD_DIRECTORY' => $directory, 'MCP_GUARD_MODE' => $mode,
            ]);
            $process = proc_open(['sh', $app . '/tests/Integration/ci/harness.sh', 'install-app'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $status = proc_close($process);
            $effects = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                file($directory . '/effects', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            return [$status, $out, $error, file_get_contents($directory . '/appstore'), $effects,
                is_file($directory . '/mcp-it/expected-version') ? file_get_contents($directory . '/mcp-it/expected-version') : ''];
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) rmdir($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testInstallDisablesAppStoreBeforeBaselineAndKeepsActualUpgrade(): void {
        [$status, $out, $error, $store, $effects, $expected] = $this->installWithAppStoreControl('applied');
        self::assertSame(0, $status, $error);
        self::assertSame('false', $store);
        self::assertStringContainsString('mcp 1.0.8 is installed and enabled', $out);
        self::assertSame("1.1.2\n", $expected);
        self::assertContains(['exec', '-u', 'www-data', 'guard-test-nc', 'php', '/var/www/html/occ', 'upgrade'], $effects);
    }

    public static function rejectedAppStoreControl(): array {
        return ['write failure' => ['set-fails'], 'ignored write' => ['ignored'],
            'string instead of boolean' => ['string'], 'unreadable setting' => ['unreadable']];
    }

    #[DataProvider('rejectedAppStoreControl')]
    public function testInstallStopsBeforeAppOperationsWhenAppStoreControlIsUnverified(string $mode): void {
        [$status, , , , $effects, $expected] = $this->installWithAppStoreControl($mode);
        self::assertNotSame(0, $status);
        self::assertSame([], $effects, 'No baseline or candidate operation may start without verified App Store control.');
        self::assertSame('', $expected);
    }

    public static function invalid(): array {
        return ['empty' => [[]], 'unsupported host' => [['--plan', '31', 'sqlite', 'fresh']],
            'unsupported combination' => [['--plan', '32', 'pgsql', 'fresh']],
            'shell input' => [['--plan', '35; exit 0', 'sqlite', 'fresh']],
            'shared daemon shutdown' => [['--shutdown']], 'image pruning' => [['35', 'sqlite', 'fresh', '--prune-images']],
            'unknown mode' => [['--plan', '35', 'sqlite', 'deploy']]];
    }

    #[DataProvider('invalid')]
    public function testInvalidRequestsFailBeforeAnyExecution(array $args): void {
        [$status, , $error] = $this->invoke($args);
        self::assertNotSame(0, $status);
        self::assertNotSame('', $error);
    }
}
