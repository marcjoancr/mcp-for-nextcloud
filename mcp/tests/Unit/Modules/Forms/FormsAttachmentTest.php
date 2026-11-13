<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Attachments\FormsAnswerUploads;
use OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\{IRootFolder,Folder,File};
use PHPUnit\Framework\TestCase;
final class FormsAttachmentTest extends TestCase {
    private function fixture(bool $visible=true): array {
        $root=$this->createMock(IRootFolder::class);$folder=$this->createMock(Folder::class);$file=$this->createMock(File::class);$guard=$this->createMock(FilesAccessGuard::class);
        $root->method('getUserFolder')->with('actor')->willReturn($folder);$folder->method('get')->with('source.txt')->willReturn($file);
        $folder->method('getById')->with(7)->willReturn([$file]);$file->method('isReadable')->willReturn(true);
        $file->method('getId')->willReturn(7);$file->method('getName')->willReturn('source.txt');$file->method('getSize')->willReturn(5);$file->method('getEtag')->willReturn('etag');$file->method('getMimetype')->willReturn('text/plain');
        $file->method('fopen')->willReturnCallback(static function(){ $stream=fopen('php://memory','w+b');fwrite($stream,'bytes');rewind($stream);return $stream;});
        $guard->method('isVisible')->willReturn($visible);
        return [$root,$file,$guard];
    }
    public function testExcludedSourceCannotBeUploaded(): void {
        [$root,$file,$guard]=$this->fixture();$guard->expects(self::once())->method('assertVisible')->with($file)->willThrowException(new \RuntimeException('hidden'));
        $file->expects(self::never())->method('fopen');$this->expectExceptionMessage('hidden');(new FormsAnswerUploads($root,$guard))->snapshot('actor',['/source.txt']);
    }
    public function testSourceDigestAndLocksAreHeldThroughConsumption(): void {
        [$root,$file,$guard]=$this->fixture();$service=new FormsAnswerUploads($root,$guard);$manifest=$service->snapshot('actor',['source.txt']);$locked=false;
        $file->expects(self::once())->method('lock')->willReturnCallback(static function()use(&$locked){$locked=true;});
        $file->expects(self::once())->method('unlock')->willReturnCallback(static function()use(&$locked){$locked=false;});
        $result=$service->withSources('actor',$manifest,static function($sources)use(&$locked){self::assertTrue($locked);self::assertSame(hash('sha256','bytes'),$sources[0]['sha256']);return stream_get_contents($sources[0]['stream']);});
        self::assertSame('bytes',$result);self::assertFalse($locked);
    }
    public function testFreshSourceMismatchNeverInvokesNativeStaging(): void {
        [$root,$file,$guard]=$this->fixture();$service=new FormsAnswerUploads($root,$guard);$manifest=$service->snapshot('actor',['source.txt']);$manifest[0]['etag']='old';$called=false;
        try {$service->withSources('actor',$manifest,static function()use(&$called){$called=true;});self::fail('Expected stale file refusal');} catch (\RuntimeException $e) {self::assertSame('Forms file source changed after preview',$e->getMessage());}
        self::assertFalse($called);
    }
    public function testResultPermissionDoesNotBypassFileVisibility(): void {
        [$root,$file,$guard]=$this->fixture(false);$this->expectExceptionMessage('Forms file effect is outside the current Files access');
        (new FormsFileImpact($root,$guard))->assertVisible('actor',['files'=>[['file_id'=>7]]]);
    }
    public function testDuplicateSourcesAreRefused(): void {
        [$root,$file,$guard]=$this->fixture();$this->expectExceptionMessage('Duplicate Forms file source');(new FormsAnswerUploads($root,$guard))->snapshot('actor',['source.txt','/source.txt']);
    }
    public function testEveryShareRemovalParentRequiresCurrentFilesVisibilityEvenWithoutAttachments():void {
        $root=$this->createMock(IRootFolder::class);$home=$this->createMock(Folder::class);
        $allowed=$this->createMock(Folder::class);$excluded=$this->createMock(Folder::class);
        $root->method('getUserFolder')->with('actor')->willReturn($home);
        $home->method('getById')->willReturnCallback(static fn(int $id):array=>$id===11?[$allowed]:[$excluded]);
        foreach([$allowed,$excluded] as $node)$node->method('isReadable')->willReturn(true);
        $guard=$this->createMock(FilesAccessGuard::class);$guard->method('isVisible')->willReturnCallback(static fn($node):bool=>$node===$allowed);
        $manifest=['files'=>[],'share_removals'=>[['parent'=>['file_id'=>11]],['parent'=>['file_id'=>12]]]];
        $this->expectExceptionMessage('Forms file effect parent is outside the current Files access');
        (new FormsFileImpact($root,$guard))->assertVisible('actor',$manifest);
    }
    public function testEmptyFileManifestDoesNotBypassProspectiveFolderScope(): void {
        [$root,$file,$guard]=$this->fixture(false);$this->expectExceptionMessage('Forms file effect parent is outside the current Files access');
        (new FormsFileImpact($root,$guard))->assertVisible('actor',['files'=>[],'share_impact'=>['parent'=>['file_id'=>7,'create_folder'=>true]]]);
    }
}
