<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck\Files;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Deck\Files\CardFileNodeAccess;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCP\Files\{File,Folder,IRootFolder,Node};
use PHPUnit\Framework\TestCase;
final class CardFileAliasesTest extends TestCase {
    private function access(array $nodes, array $visible): CardFileNodeAccess {
        $guard=$this->createMock(FilesAccessGuard::class);
        $guard->method('isVisible')->willReturnCallback(static fn(Node $n):bool=>in_array($n,$visible,true));
        $folder=$this->createMock(Folder::class);$folder->method('getById')->with(99)->willReturn($nodes);
        $folder->method('getRelativePath')->willReturn('/visible.txt');
        $root=$this->createMock(IRootFolder::class);$root->method('getUserFolder')->with('alice')->willReturn($folder);
        return new CardFileNodeAccess($guard,$root);
    }
    private function node(string $class, int $id=99): Node {
        $node=$this->createMock($class);$node->method('getId')->willReturn($id);$node->method('isReadable')->willReturn(true);
        return $node;
    }
    public function testCleanAliasCannotBypassAHiddenAliasAndNoMetadataIsRead(): void {
        $clean=$this->node(File::class);$hidden=$this->node(File::class);
        foreach([$clean,$hidden] as $node){$node->expects(self::never())->method('getName');}
        $this->expectException(DeckRefusalException::class);
        $this->access([$clean,$hidden],[$clean])->resolve('alice',99);
    }
    public function testLinkRequiresSharePermissionOnEveryVisibleAliasBeforeNamingIt(): void {
        $clean=$this->node(File::class); $alias=$this->node(File::class);
        $clean->method('isShareable')->willReturn(true); $clean->method('getPermissions')->willReturn(17);
        $alias->method('isShareable')->willReturn(false);
        foreach ([$clean,$alias] as $node) { $node->expects(self::never())->method('getName'); }
        $this->expectException(DeckRefusalException::class);
        $this->access([$clean,$alias],[$clean,$alias])->resolve('alice',99,true);
    }

    public function testFolderSpecificAnswerRequiresVisibilityAndExactIdentityForEveryAlias(): void {
        $visible=$this->node(Folder::class);$hidden=$this->node(Folder::class);$wrong=$this->node(Folder::class,100);
        foreach([[[$visible,$hidden],[$visible]],[[$visible,$wrong],[$visible,$wrong]]] as [$nodes,$allowed]){
            try{$this->access($nodes,$allowed)->resolve('alice',99);self::fail('Invalid alias admitted');}
            catch(DeckRefusalException $e){self::assertStringNotContainsString('Folders',$e->getMessage());}
        }
        try{$this->access([$visible],[$visible])->resolve('alice',99);self::fail('Folder admitted');}
        catch(DeckRefusalException $e){self::assertStringContainsString('Folders',$e->getMessage());}
    }
    public function testReadableAliasNeedsNoSharePermissionForUnlink(): void {
        $node=$this->node(File::class);$node->method('getPath')->willReturn('/alice/files/visible.txt');
        $node->expects(self::never())->method('isShareable');$node->method('getName')->willReturn('visible.txt');
        $node->method('getSize')->willReturn(0);$node->method('getEtag')->willReturn('etag');
        $resolved=$this->access([$node],[$node])->resolve('alice',99);
        self::assertSame(99,$resolved['metadata']['fileId']);self::assertSame($node,$resolved['node']);
    }
}
