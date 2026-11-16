<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
final class FormsDestinationAccessTest extends TestCase {
    public function testActualWriteParentUsesCurrentScopeAfterMetadataTraversal():void {
        $root=$this->createMock(IRootFolder::class);$root->expects(self::never())->method('getUserFolder');
        $guard=$this->createMock(FilesAccessGuard::class);$parent=$this->createMock(Folder::class);
        $parent->method('isReadable')->willReturn(true);$parent->method('isCreatable')->willReturn(true);
        $guard->method('isTraversable')->with($parent)->willReturn(true);$guard->method('isVisible')->with($parent)->willReturn(false);
        $guard->expects(self::exactly(2))->method('clearCache');$guard->expects(self::exactly(2))->method('assertScopeReadable');
        $impact=new FormsFileImpact($root,$guard);$impact->assertDestination($parent,false);
        $this->expectExceptionMessage('Forms destination is outside the current Files access');$impact->assertDestination($parent,true);
    }
    public function testSelectedDestinationCanBeInAnotherNativeOwnerTree():void {
        $root=$this->createMock(IRootFolder::class);$root->expects(self::never())->method('getUserFolder');
        $guard=$this->createMock(FilesAccessGuard::class);$parent=$this->createMock(Folder::class);
        $parent->method('isReadable')->willReturn(true);$parent->method('isCreatable')->willReturn(true);
        $guard->expects(self::once())->method('isVisible')->with($parent)->willReturn(true);
        $guard->expects(self::never())->method('isTraversable');
        (new FormsFileImpact($root,$guard))->assertDestination($parent,true);
    }
    public function testFutureHomeRequiresAnExplicitUnrestrictedReadableFilesScope():void {
        $guard=$this->createMock(FilesAccessGuard::class);
        $guard->expects(self::once())->method('clearCache')->with(true);
        $guard->expects(self::once())->method('assertScopeReadable');
        $guard->expects(self::once())->method('assertUnrestrictedScope')->with('actor');
        (new FormsFileImpact($this->createMock(IRootFolder::class),$guard))->assertUnrestrictedDestinationScope('actor');
    }
    public function testUnreadableUncreatableOrHiddenParentsCannotReceiveBytes():void {
        foreach([[false,true,true],[true,false,true],[true,true,false]] as [$read,$create,$visible]){
            $guard=$this->createMock(FilesAccessGuard::class);$parent=$this->createMock(Folder::class);
            $parent->method('isReadable')->willReturn($read);$parent->method('isCreatable')->willReturn($create);$guard->method('isVisible')->willReturn($visible);
            try{(new FormsFileImpact($this->createMock(IRootFolder::class),$guard))->assertDestination($parent,true);self::fail('Destination admitted.');}
            catch(\RuntimeException $e){self::assertSame('Forms destination is outside the current Files access',$e->getMessage());}
        }
    }
    public function testUnreadableScopeOrExcludedAncestorCannotBeTraversed():void {
        $guard=$this->createMock(FilesAccessGuard::class);$parent=$this->createMock(Folder::class);$parent->method('isReadable')->willReturn(true);
        $guard->method('isTraversable')->willReturn(false);$impact=new FormsFileImpact($this->createMock(IRootFolder::class),$guard);
        try{$impact->assertDestination($parent,false);self::fail('Excluded ancestor traversed.');}catch(\RuntimeException){self::assertTrue(true);}
        $guard->method('assertScopeReadable')->willThrowException(new \RuntimeException('Policy unavailable.'));
        $guard->expects(self::never())->method('isVisible');$this->expectExceptionMessage('Policy unavailable.');$impact->assertDestination($parent,true);
    }
}
