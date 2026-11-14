<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Platform\FileAccess\BackupMessages;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Verifies that backup privacy is established before any sensitive bytes are copied. */
final class FileBackupPrivacyTest extends FilesToolsTestCase {
    /** @dataProvider tagFailures */
    public function testTagFailureLeavesAnEmptyBackupAndOriginalUntouched(string $failure): void {
        $backup = $this->backup($failure);
        try {
            $backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'),
                '/Documentos/ata.md', 'alice', null);
            $this->fail('An unprotected backup must abort the edit');
        } catch (ToolFailure $e) {
            $this->assertSame(BackupMessages::failed(), $e->getMessage());
        }
        $copies = array_filter($this->tree->nodes, fn (array $node, string $path): bool =>
            str_ends_with($path, '.bak'), ARRAY_FILTER_USE_BOTH);
        $this->assertCount(1, $copies);
        $this->assertSame('', array_values($copies)[0]['content']);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertCount(3, $this->tree->ops, 'Only folders and the empty backup may be created');
    }

    /** @return iterable<string, array{0:string}> */
    public static function tagFailures(): iterable {
        yield 'assignment' => ['assign'];
        yield 'lookup' => ['lookup'];
    }

    public function testTagsAreAssignedWhileBackupIsEmpty(): void {
        $backup = $this->backup(null);
        $path = $backup->prepare($this->tree->rootFolder(), $this->tree->node('/alice/files/Documentos/ata.md'),
            '/Documentos/ata.md', 'alice', null);
        $this->assertSame("# Ata\nolá", $this->tree->nodes['/alice/files' . $path]['content']);
    }

    public function testTrustedBoundaryCanRefuseBeforeAnyBackupAllocation():void {
        $backup=$this->backup(null,false);
        try {
            $backup->prepare($this->tree->rootFolder(),$this->tree->node('/alice/files/Documentos/ata.md'),'/Documentos/ata.md','alice',null,false,
                static function():never {throw new \RuntimeException('original authority revoked');});
            self::fail('Revoked backup allocation admitted');
        } catch(ToolFailure) {}
        self::assertSame([],$this->tree->ops);
    }
    public function testTrustedBoundaryCoversEachFolderAllocationAndBackupByteWrite():void {
        $backup=$this->backup(null);$calls=[];
        $path=$backup->prepare($this->tree->rootFolder(),$this->tree->node('/alice/files/Documentos/ata.md'),'/Documentos/ata.md','alice',null,false,
            static function($node,?string $child,\Closure $policy,\Closure $write)use(&$calls):mixed {
                $policy();$calls[]=['path'=>$node->getPath(),'child'=>$child];return $write();
            });
        self::assertCount(4,$calls);
        self::assertSame('MCP backups',$calls[0]['child']);self::assertSame('Documentos',$calls[1]['child']);
        self::assertStringEndsWith('.bak',$calls[2]['child']);self::assertNull($calls[3]['child']);
        self::assertSame("# Ata\nolá",$this->tree->nodes['/alice/files'.$path]['content']);
    }
    public function testRevocationAtBackupContentBoundaryKeepsOriginalAndEmptyCopy():void {
        $backup=$this->backup(null);
        try {
            $backup->prepare($this->tree->rootFolder(),$this->tree->node('/alice/files/Documentos/ata.md'),'/Documentos/ata.md','alice',null,false,
                static function($node,?string $child,\Closure $policy,\Closure $write):mixed {
                    if($child===null)throw new \RuntimeException('original authority revoked after allocation');
                    return $write();
                });
            self::fail('Revoked backup byte write admitted');
        } catch(ToolFailure) {}
        $copies=array_filter($this->tree->nodes,static fn(array $node,string $path):bool=>str_ends_with($path,'.bak'),ARRAY_FILTER_USE_BOTH);
        self::assertCount(1,$copies);self::assertSame('',array_values($copies)[0]['content']);
        self::assertSame("# Ata\nolá",$this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
    }

    public function testCompletedBackupAdmissionRunsBeforeAnyOriginalByteWrite():void {
        $file=$this->tree->node('/alice/files/Documentos/ata.md');$root=$this->tree->rootFolder();
        $boundary=static fn()=>null;$backup=$this->createMock(FileBackup::class);
        $backup->expects(self::once())->method('prepare')->with($root,$file,'/Documentos/ata.md','alice',null,false,$boundary)->willReturn('/copy.bak');
        $writer=new \OCA\Mcp\Modules\Files\Editing\ContentWriter(
            \OCA\Mcp\Tests\Support\Files\FileTypePolicies::guard($this),$backup,
            new \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo($this->users),$this->visibilityGuard);
        try {
            $writer->commit($root,'alice',$file,'/Documentos/ata.md','replacement',null,'',false,null,null,false,$boundary,
                static function(string $copy):void {self::assertSame('/copy.bak',$copy);throw new \RuntimeException('Recovery proof failed');});
            self::fail('Unverified recovery admitted');
        } catch(\RuntimeException $error) {self::assertSame('Recovery proof failed',$error->getMessage());}
        self::assertSame("# Ata\nolá",$this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        self::assertSame([],$this->tree->ops);
    }

    private function backup(?string $failure,bool $assign=true): FileBackup {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids) use ($failure): array {
            if ($failure === 'lookup') {
                throw new \RuntimeException('Unavailable');
            }
            return array_fill_keys($ids, ['42']);
        });
        $mapper->expects($failure === 'lookup' || !$assign ? $this->never() : $this->once())->method('assignTags')
            ->willReturnCallback(function (string $id, string $type, array $tags) use ($failure): void {
                $this->assertSame('files', $type);
                $this->assertSame(['42'], $tags);
                foreach ($this->tree->nodes as $node) {
                    if ((string)$node['id'] === $id) {
                        $this->assertSame('', $node['content']);
                    }
                }
                if ($failure === 'assign') {
                    throw new \RuntimeException('Unavailable');
                }
            });
        $guard = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $this->config->mock($this), $mapper, $this->tree->filesystemRoot());
        return new FileBackup(\OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture::location($this), \OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture::copies($this, \OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture::location($this)), $this->apps, $this->users, $this->time, $this->config->mock($this), $guard);
    }
}
