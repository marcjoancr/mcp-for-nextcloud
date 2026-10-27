<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;
use PHPUnit\Framework\TestCase;

/** A new native or delegated filename sink needs an explicit reviewed inventory entry. */
final class FileWritePolicySinksTest extends TestCase {
    private const SINKS = [
        'Modules/Files/Creation/FileCreation.php' => ['newFile' => 1, 'putContent' => 1],
        'Modules/Files/Editing/ContentWriter.php' => ['putContent' => 1],
        'Modules/Files/Editing/FileBackup.php' => ['copy' => 2],
        'Modules/Files/Editing/FileBackupCopy.php' => ['newFile' => 1, 'putContent' => 1],
        'Modules/Files/Checkout/CheckoutWriter.php' => ['putContent' => 1],
        'Modules/Files/Versions/VersionRestorer.php' => ['rollback' => 1, 'putContent' => 1],
        'Modules/Files/Reorganization/ListedTreeCopy.php' => ['copy' => 1],
        'Modules/Files/Reorganization/CopyMove.php' => ['move' => 1],
        'Modules/Files/Reorganization/BatchMover.php' => ['move' => 1],
        'Modules/Files/Reorganization/BatchUndo.php' => ['move' => 1],
        'Modules/Files/Tools/CopyFile.php' => ['copy' => 1],
        'Modules/Files/Tools/MoveFile.php' => ['move' => 1],
        'Modules/Notes/Creation/NoteCreator.php' => ['newFile' => 1],
        'Modules/Notes/Editing/NoteEditor.php' => ['putContent' => 1, 'move' => 1],
        'Modules/Notes/Editing/PartialEditConfirmation.php' => ['putContent' => 1],
        'Modules/Notes/Moving/NoteMover.php' => ['move' => 1],
        'Modules/Contacts/Deletion/ContactBackup.php' => ['newFile' => 1],
    ];
    public function testEveryFilenameSinkHasAnExplicitInventoryEntry(): void {
        $lib = dirname(__DIR__, 2) . '/lib/'; $actual = [];
        foreach (['Modules/Files', 'Modules/Notes', 'Modules/Contacts', 'Platform/FileAccess'] as $subtree) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib . $subtree, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') continue;
                $source = file_get_contents($file->getPathname());
                // Tokenization excludes comments and quoted example text before counting method calls.
                $tokens = \PhpToken::tokenize($source);
                $significant = array_values(array_filter($tokens, static fn($token) => !$token->isIgnorable()));
                $counts = [];
                foreach ($significant as $i => $token) {
                    if ($token->id === T_OBJECT_OPERATOR && isset($significant[$i + 2]) && $significant[$i + 2]->text === '(' && in_array($significant[$i + 1]->text, ['newFile', 'putContent', 'copy', 'move', 'rollback'], true)) {
                        $method = $significant[$i + 1]->text; $counts[$method] = ($counts[$method] ?? 0) + 1;
                    }
                }
                if ($counts !== []) { ksort($counts); $actual[substr($file->getPathname(), strlen($lib))] = $counts; }
            }
        }
        $expected = self::SINKS;
        foreach ($expected as &$methods) ksort($methods);
        ksort($expected); ksort($actual);
        self::assertSame($expected, $actual, 'Review final names, aliases, previews, callbacks and the documentation before adding a sink.');
    }
    public function testGuardedAndInternalOwnersRemainExplicit(): void {
        $lib = dirname(__DIR__, 2) . '/lib/';
        foreach (self::SINKS as $file => $methods) {
            $source = file_get_contents($lib . $file);
            if (in_array($file, ['Modules/Files/Editing/FileBackup.php', 'Modules/Files/Editing/FileBackupCopy.php', 'Modules/Contacts/Deletion/ContactBackup.php'], true)) {
                self::assertStringNotContainsString('FileTypeGuard', $source, 'Mandatory internal backups must remain available.');
            } elseif (str_contains($file, '/Tools/')) {
                self::assertStringContainsString('$this->operation->', $source);
            } elseif (isset($methods['move']) && !str_contains($file, '/Notes/')) {
                self::assertStringContainsString('$this->moveExecution->locked', $source);
            } elseif (str_ends_with($file, '/ListedTreeCopy.php')) {
                self::assertStringContainsString('$this->fileTypes->writeName', $source);
                self::assertStringContainsString('$this->listed($root, $identity)', $source);
            } else {
                self::assertStringContainsString('$this->fileTypes->write', $source);
            }
        }
        $move = file_get_contents($lib . 'Modules/Files/Reorganization/MoveExecution.php');
        self::assertStringContainsString('$this->fileTypes->snapshot()', $move);
        self::assertStringContainsString('new FileTypePreflight', $move);
        foreach (['Files', 'Notes'] as $module) {
            $class = 'OCA\\Mcp\\Modules\\' . $module . '\\' . $module . 'ToolCatalog';
            foreach ($class::definitions() as $definition) {
                foreach (array_keys((array)$definition['inputSchema']['properties']) as $argument) {
                    self::assertDoesNotMatchRegularExpression('/(?:bypass|exempt|file.?types|file.?policy)/i', $argument);
                }
            }
        }
    }
}
