<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Tests\Integration\Support\Http;
use OCP\IConfig;
use OCP\Server;
use Throwable;

/**
 * Diagnostic, not a scenario: where the restore of a groupfolder version is refused ("Resource locked by another
 * operation") on NC 34 and 35 and not on NC 32 and 33. It restores the same file through the MCP tool (over HTTP), through
 * the server's own versions endpoint (what the Files app does) and through the version manager in this process, and
 * records every answer and the lock exceptions, with the debug log on, in observations.txt. Run it alone with
 * MCP_IT_PHPUNIT_ARGS="--filter GroupfolderRestoreDiagnosticTest", also against the released baseline (mode baseline).
 */
final class GroupfolderRestoreDiagnosticTest extends ExclusionTestCase {
    private mixed $previousLogLevel = null;

    protected function setUp(): void {
        parent::setUp();
        $config = Server::get(IConfig::class);
        $this->previousLogLevel = $config->getSystemValue('loglevel', 2);
        $config->setSystemValue('loglevel', 0);
    }

    protected function tearDown(): void {
        Server::get(IConfig::class)->setSystemValue('loglevel', $this->previousLogLevel);
        parent::tearDown();
    }

    public function testRecordsTheRestoreThroughEveryPath(): void {
        $app = Server::get(\OCP\App\IAppManager::class);
        $this->observe('restore diagnostic: mcp ' . $app->getAppVersion('mcp') . ', groupfolders ' . $app->getAppVersion('groupfolders')
            . ', files_versions ' . $app->getAppVersion('files_versions') . ', files_lock '
            . ($app->isEnabledForAnyone('files_lock') ? 'enabled' : 'not installed'));

        $this->makeFile('groupfolder', 'v.txt', 'first');
        $alice = $this->mcp();
        $path = $this->path('groupfolder', 'v.txt');
        foreach (['second', 'third'] as $content) {
            $edit = $alice->write('files_edit', ['path' => $path, 'content' => $content]);
            $this->observe("files_edit to $content -> " . ($edit->ok() ? 'ok' : $edit->describe()));
        }
        $list = $alice->tool('files_versions_list', ['path' => $path]);
        $versions = $list->json()['versions'] ?? [];
        $this->observe('versions listed: ' . count($versions) . ' ' . json_encode(array_column($versions, 'revision')));
        $revision = $versions[0]['revision'] ?? null;
        if (!is_string($revision)) {
            $this->observe('no version to restore');
            $this->addToAssertionCount(1);
            return;
        }
        $fileId = (int)$this->node(self::ALICE, $path)->getId();

        // A. the MCP tool, over HTTP.
        $mcp = $alice->write('files_version_restore', ['path' => $path, 'version' => $revision]);
        $this->observe('A. MCP files_version_restore over HTTP -> ' . ($mcp->ok() ? 'ok' : substr($mcp->text(), 0, 160)));

        // B. the versions endpoint of the server: restore = MOVE of the version onto the restore folder, as the Files app does.
        $uid = self::ALICE;
        $base = Http::BASE . '/remote.php/dav/versions/' . $uid;
        $dav = Http::request('MOVE', "$base/versions/$fileId/$revision", [
            'Authorization: ' . $this->login(self::ALICE)->authorization(),
            "Destination: $base/restore/target",
        ]);
        $this->observe("B. native restore (WebDAV MOVE of the version) -> HTTP {$dav['status']} " . substr(preg_replace('/\s+/', ' ', $dav['body']), 0, 220));

        // C. the version manager in this process, with the exception and where it came from.
        try {
            $manager = Server::get(\OCA\Files_Versions\Versions\IVersionManager::class);
            $user = Server::get(\OCP\IUserManager::class)->get($uid);
            $node = $this->node(self::ALICE, $path);
            $found = null;
            foreach ($manager->getVersionsForFile($user, $node) as $version) {
                if ((string)$version->getRevisionId() === $revision) {
                    $found = $version;
                }
            }
            $this->observe('C. version manager: version ' . ($found === null ? 'not found' : 'found (' . $found::class . ')'));
            if ($found !== null) {
                $manager->rollback($found);
                $this->observe('C. version manager rollback -> ok');
            }
        } catch (Throwable $e) {
            $this->describeFailure('C. version manager rollback FAILED (no user logged in is expected here)', $e);
        }

        // F. what the tool does, step by step, in this process as the user (files_lock is enabled, so it writes the version
        // over the file with putContent while the version stream is open): where exactly is the lock refused?
        try {
            Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
            $manager = Server::get(\OCA\Files_Versions\Versions\IVersionManager::class);
            $user = Server::get(\OCP\IUserManager::class)->get($uid);
            $node = $this->node(self::ALICE, $path);
            $found = null;
            foreach ($manager->getVersionsForFile($user, $node) as $version) {
                if ((string)$version->getRevisionId() === $revision) {
                    $found = $version;
                }
            }
            $stream = $manager->read($found);
            $this->observe('F1. stream open on the version file: ' . (is_resource($stream) ? 'yes' : 'no'));
            try {
                $node->putContent($stream);
                $this->observe('F1. putContent(version stream) -> ok');
            } catch (Throwable $e) {
                $this->describeFailure('F1. putContent(open version stream) FAILED', $e);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $stream = $manager->read($found);
            $bytes = is_resource($stream) ? (string)stream_get_contents($stream) : '';
            if (is_resource($stream)) {
                fclose($stream);
            }
            try {
                $this->node(self::ALICE, $path)->putContent($bytes);
                $this->observe('F2. putContent(bytes read from the version, stream closed first) -> ok');
            } catch (Throwable $e) {
                $this->describeFailure('F2. putContent(bytes, stream closed) FAILED', $e);
            }
            try {
                $manager->rollback($found);
                $this->observe('F3. manager->rollback as the logged-in user -> ok');
            } catch (Throwable $e) {
                $this->describeFailure('F3. manager->rollback FAILED', $e);
            }
        } catch (Throwable $e) {
            $this->describeFailure('F. setup FAILED', $e);
        } finally {
            Server::get(\OCP\IUserSession::class)->setUser(null);
        }

        // D. the same restore again through the tool, to see whether the first attempts changed anything.
        $again = $alice->write('files_version_restore', ['path' => $path, 'version' => $revision]);
        $this->observe('D. MCP restore again -> ' . ($again->ok() ? 'ok' : substr($again->text(), 0, 160)));

        // E. a plain edit and a home-folder restore for comparison.
        $home = $this->makeFile('home', 'h.txt', 'first');
        $hpath = $this->path('home', 'h.txt');
        foreach (['second', 'third'] as $content) {
            $alice->write('files_edit', ['path' => $hpath, 'content' => $content]);
        }
        $hlist = $alice->tool('files_versions_list', ['path' => $hpath])->json()['versions'] ?? [];
        $hrev = $hlist[0]['revision'] ?? null;
        $this->observe('E. home file restore -> ' . (is_string($hrev)
            ? ($alice->write('files_version_restore', ['path' => $hpath, 'version' => $hrev])->ok() ? 'ok' : 'refused')
            : 'no version'));
        $this->addToAssertionCount(1);
    }

    /**
     * Does the restore fail only when it follows the last write within the same second? Versions are named by the second
     * (the revision is a timestamp), so a restore that makes a version of the current content right after a write can meet a
     * version of the same name. Each round writes, waits the given seconds and restores.
     */
    public function testRecordsWhetherTheSecondOfTheLastWriteMatters(): void {
        $file = $this->makeFile('groupfolder', 'timing.txt', 'start');
        $alice = $this->mcp();
        $path = $this->path('groupfolder', 'timing.txt');
        $alice->write('files_edit', ['path' => $path, 'content' => 'one']);
        $results = [];
        foreach ([0, 0, 0, 0, 2, 2, 2, 2] as $round => $pause) {
            $alice->write('files_edit', ['path' => $path, 'content' => "round $round"]);
            if ($pause > 0) {
                sleep($pause);
            }
            $versions = $alice->tool('files_versions_list', ['path' => $path])->json()['versions'] ?? [];
            $mtime = $this->node(self::ALICE, $path)->getMTime();
            $line = "r$round(+{$pause}s, now " . time() . ", mtime $mtime, revisions " . implode('/', array_column($versions, 'revision')) . ')';
            foreach (['newest' => $versions[0]['revision'] ?? null, 'oldest' => $versions[count($versions) - 1]['revision'] ?? null] as $which => $revision) {
                if (!is_string($revision)) {
                    $line .= " $which: none";
                    continue;
                }
                $restore = $alice->write('files_version_restore', ['path' => $path, 'version' => $revision]);
                $line .= " $which($revision): " . ($restore->ok() ? 'ok' : (str_contains($restore->text(), 'locked') ? 'LOCKED' : substr($restore->text(), 0, 40)));
            }
            $results[] = $line;
        }
        $this->observe("timing of the groupfolder restore after a write:\n    " . implode("\n    ", $results));
        $this->addToAssertionCount(1);
    }

    private function describeFailure(string $label, Throwable $e): void {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 10) as $frame) {
            $frames[] = basename((string)($frame['file'] ?? '?')) . ':' . ($frame['line'] ?? '?') . ' ' . ($frame['class'] ?? '') . '::' . ($frame['function'] ?? '');
        }
        $this->observe("$label: " . $e::class . ': ' . $e->getMessage() . "\n    at " . basename($e->getFile()) . ':' . $e->getLine() . "\n    " . implode("\n    ", $frames));
    }
}
