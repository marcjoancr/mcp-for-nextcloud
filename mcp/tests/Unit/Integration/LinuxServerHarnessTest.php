<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinuxServerHarnessTest extends TestCase {
    private string $directory;
    private array $profile;
    private string $digest;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/mcp-l0-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/kit', 0700);
        mkdir($this->directory . '/bin', 0700);
        $this->profile = json_decode(file_get_contents($this->root() . '/tests/Integration/ci/linuxserver/profile.json'), true, 512, JSON_THROW_ON_ERROR);
        // Tiny trusted kit exercises planning, not archive reconstruction or native acceptance.
        $files = [];
        foreach (['BUILDING.md', 'source.bundle', 'runtime-dependencies.tar.gz', 'mcp-2.0.0.tar.gz'] as $name) {
            $bytes = 'synthetic ' . $name;
            file_put_contents($this->directory . '/kit/' . $name, $bytes);
            $files[$name] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $manifest = ['schemaVersion' => 1, 'sourceCommit' => $this->profile['candidate']['sourceCommit'], 'version' => '2.0.0',
            'files' => $files, 'runtimeFiles' => ['appinfo/info.xml' => ['bytes' => 1, 'sha256' => hash('sha256', 'x')]]];
        file_put_contents($this->directory . '/kit/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->digest = hash_file('sha256', $this->directory . '/kit/manifest.json');
        $this->profile['candidate']['manifestSha256'] = $this->digest;
        // Any accidental infrastructure/network subprocess leaves an observable tripwire.
        foreach (['docker', 'curl', 'wget', 'ssh'] as $name) {
            file_put_contents($this->directory . '/bin/' . $name, "#!/bin/sh\n: > \"\$MCP_L0_TRIPWIRE\"\nexit 99\n");
            chmod($this->directory . '/bin/' . $name, 0700);
        }
    }
    private function root(): string { return dirname(__DIR__, 3); }
    private function arguments(): array {
        return ['--plan', '--profile', $this->directory . '/profile.json', '--kit', $this->directory . '/kit', '--manifest-sha256', $this->digest];
    }
    private function invoke(?array $arguments = null): array {
        file_put_contents($this->directory . '/profile.json', json_encode($this->profile, JSON_THROW_ON_ERROR));
        $environment = getenv();
        $environment['PATH'] = $this->directory . '/bin:' . $environment['PATH'];
        $environment['MCP_L0_TRIPWIRE'] = $this->directory . '/called';
        $process = proc_open(['sh', $this->root() . '/tests/Integration/ci/linuxserver/run-local.sh', ...($arguments ?? $this->arguments())],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        self::assertFileDoesNotExist($this->directory . '/called', 'Planning called an infrastructure/network tool');
        return [$status, $out, $err];
    }
    protected function tearDown(): void {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }
    public function testPlanVerifiesKitWithoutCreatingResourcesOrClaimingRuntimeReadiness(): void {
        [$status, $out, $err] = $this->invoke();
        self::assertSame(0, $status, $err);
        $plan = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('profile-and-source-kit', $plan['validationScope']);
        self::assertFalse($plan['executionAvailable']);
        self::assertSame([], $plan['profile']['isolation']['publishedPorts']);
        self::assertSame($this->digest, $plan['sourceKit']['manifestSha256']);
        self::assertSame($this->profile['candidate']['sourceCommit'], $plan['sourceKit']['sourceCommit']);
        self::assertContains('native-upgrade-and-recovery', $plan['pending']);
        self::assertDoesNotMatchRegularExpression('/' . preg_quote($this->directory, '/') . '/', $out . $err);
        self::assertSame(['bin', 'kit', 'profile.json'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }
    public static function unsafeProfiles(): iterable {
        yield 'mutable image' => [['images', 'seaweedfs', 'reference'], 'chrislusf/seaweedfs:latest'];
        yield 'newline in image' => [['images', 'seaweedfs', 'reference'], 'chrislusf/seaweedfs:4.48@sha256:' . str_repeat('a', 64) . "\n"];
        yield 'wrong platform' => [['platform'], 'linux/arm64'];
        yield 'baseline version' => [['baseline', 'version'], '1.1.2'];
        yield 'candidate version' => [['candidate', 'version'], '1.2.0'];
        yield 'outside resource' => [['container'], 'production-nextcloud'];
        yield 'external endpoint' => [['objectStore', 'endpoint'], 'https://production.invalid'];
        yield 'published port' => [['isolation', 'publishedPorts'], [443]];
        yield 'egress' => [['isolation', 'runtimeEgress'], true];
        yield 'resource exhaustion' => [['limits', 'memoryMiB'], 65536];
        yield 'disabled deadline' => [['limits', 'runSeconds'], 0];
        yield 'compatibility range' => [['apps', 0, 'nextcloud', 'max-version'], '34'];
        yield 'foreign archive' => [['apps', 0, 'url'], 'https://untrusted.invalid/app.tar.gz'];
        yield 'unknown secret' => [['password'], 'do-not-print-this-value'];
        yield 'shell payload' => [['images', 'nextcloud', 'reference'], '$(touch unsafe)'];
        yield 'wrong typed bool' => [['isolation', 'internalNetwork'], 'true'];
    }
    #[DataProvider('unsafeProfiles')]
    public function testRejectsUnsafeProfileBeforeAnyExternalAction(array $keys, mixed $value): void {
        $field =& $this->profile;
        foreach ($keys as $key) $field =& $field[$key];
        $field = $value;
        [$status, $out, $err] = $this->invoke();
        self::assertNotSame(0, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('Invalid profile', $err);
        self::assertStringNotContainsString('do-not-print-this-value', $err);
    }
    public function testRejectsMissingInputsAndExecutionInsteadOfStartingAnything(): void {
        foreach ([[], ['--plan'], ['--run'], [...$this->arguments(), '--container', 'existing'], [...$this->arguments(), '--plan']] as $args) {
            [$status, $out, $err] = $this->invoke($args);
            self::assertNotSame(0, $status);
            self::assertSame('', $out);
            self::assertStringContainsString('Usage:', $err);
        }
    }
    public function testRejectsMissingRequiredProfileField(): void {
        unset($this->profile['candidate']);
        [$status, $out, $err] = $this->invoke();
        self::assertNotSame(0, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('Invalid profile', $err);
    }
    public function testRejectsWrongTrustedDigestAndChangedKit(): void {
        $args = $this->arguments(); $args[count($args)-1] = str_repeat('a', 64);
        [$status, $out] = $this->invoke($args);
        self::assertNotSame(0, $status); self::assertSame('', $out);
        file_put_contents($this->directory . '/kit/source.bundle', 'altered');
        [$status, $out, $err] = $this->invoke();
        self::assertNotSame(0, $status); self::assertSame('', $out);
        self::assertStringContainsString('Source kit verification failed', $err);
    }
    public function testRejectsVerifiedKitWhoseCommitDoesNotMatchProfile(): void {
        $this->profile['candidate']['sourceCommit'] = str_repeat('b', 40);
        [$status, $out, $err] = $this->invoke();
        self::assertNotSame(0, $status); self::assertSame('', $out);
        self::assertStringContainsString('Source kit identity', $err);
    }
    public function testRejectsOversizedAndSymlinkedProfilesWithoutLeakingInput(): void {
        $other = $this->directory . '/other.json';
        file_put_contents($other, str_repeat('x', 65537));
        $args = $this->arguments(); $args[2] = $other;
        [$status, $out, $err] = $this->invoke($args);
        self::assertNotSame(0, $status); self::assertSame('', $out);
        self::assertStringContainsString('Invalid profile', $err);
        unlink($other);
        symlink($this->directory . '/profile.json', $other);
        [$status, $out, $err] = $this->invoke($args);
        self::assertNotSame(0, $status); self::assertSame('', $out);
        self::assertStringContainsString('Invalid profile', $err);
    }
    public function testRejectsSymlinkedKitWithoutFollowingIt(): void {
        symlink($this->directory . '/kit', $this->directory . '/linked');
        $args = $this->arguments(); $args[4] = $this->directory . '/linked';
        [$status, $out] = $this->invoke($args);
        self::assertNotSame(0, $status); self::assertSame('', $out);
    }
}
