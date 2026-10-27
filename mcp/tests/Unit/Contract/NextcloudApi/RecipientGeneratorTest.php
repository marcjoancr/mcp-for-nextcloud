<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/** Updating recipient surfaces must not silently discard another capability's provenance. */
final class RecipientGeneratorTest extends TestCase {
    public function testRegeneratingRecipientsPreservesMailPinsAndUnrelatedSurfaces(): void {
        $root = sys_get_temp_dir() . '/mcp-recipient-generator-' . bin2hex(random_bytes(6));
        $directory = $root . '/tests/fixtures/nextcloud-api';
        mkdir($directory, 0700, true);
        mkdir($root . '/vendor');
        mkdir($root . '/archives');
        mkdir($root . '/output');
        try {
            $app = dirname(__DIR__, 4);
            file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export($app . '/vendor/autoload.php', true) . ';');
            copy($app . '/tests/fixtures/nextcloud-api/generate-recipients.php', $directory . '/generate-recipients.php');
            copy($app . '/tests/fixtures/nextcloud-api/35.php', $directory . '/35.php');
            $before = require $directory . '/35.php';
            $pins = [];
            $classes = ['deck' => ['OCA\\Deck\\Db\\AclMapper', 'OCA\\Deck\\Service\\CirclesService'],
                'circles' => ['OCA\\Circles\\CirclesManager', 'OCA\\Circles\\Model\\Member', 'OCA\\Circles\\Model\\Circle']];
            foreach ($classes as $name => $entries) {
                $archive = new \PharData($root . '/archives/' . $name . '-1.0.0.tar');
                $archive->addFromString($name . '/appinfo/info.xml', '<info><version>1.0.0</version></info>');
                foreach ($entries as $class) {
                    $parts = explode('\\', $class);
                    $short = array_pop($parts);
                    $relative = implode('/', array_slice($parts, 2));
                    $archive->addFromString($name . '/lib/' . ($relative === '' ? '' : $relative . '/') . $short . '.php',
                        '<?php namespace ' . implode('\\', $parts) . '; class ' . $short . ' {}');
                }
                $archive->compress(\Phar::GZ);
                unset($archive);
                $pins[$name] = ['version' => '1.0.0', 'sha256' => hash_file('sha256', $root . '/archives/' . $name . '-1.0.0.tar.gz')];
            }
            file_put_contents($directory . '/recipient-sources.json', json_encode(['35' => $pins], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, $directory . '/generate-recipients.php', '35', $root . '/archives', $root . '/output'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $out . $error);
            $after = require $root . '/output/35.php';
            self::assertSame($before['mailSources'], $after['mailSources'] ?? null);
            self::assertSame($before['sources'], $after['sources']);
            self::assertSame($pins, $after['recipientSources']);
            foreach (array_diff(array_keys($before['classes']), [...$classes['deck'], ...$classes['circles']]) as $class) {
                self::assertSame($before['classes'][$class], $after['classes'][$class], $class);
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($root);
        }
    }
}
