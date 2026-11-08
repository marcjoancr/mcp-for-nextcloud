<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Files\MailFilesSource;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\{File, Folder, IRootFolder};
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

final class MailFilesSourceTest extends TestCase {
    private string $bytes = 'approved bytes';
    private bool $visible = true;
    private int $created = 0;
    private bool $allowed = true;
    private bool $download = true;
    private ?int $associatedDraft = null;
    private function source(): MailFilesSource {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(50);
        $file->method('getName')->willReturn('budget.pdf');
        $file->method('getMimetype')->willReturn('application/pdf');
        $file->method('getEtag')->willReturn('etag');
        $file->method('getSize')->willReturnCallback(fn(): int => strlen($this->bytes));
        $file->method('isReadable')->willReturn(true);
        $file->method('fopen')->willReturnCallback(function() { $r=fopen('php://memory','r+'); fwrite($r,$this->bytes); rewind($r); return $r; });
        $storage=$this->createMock(IStorage::class);
        $shared=$this->createMock(\OCP\Files\Storage\ISharedStorage::class);
        $share=$this->createMock(\OCP\Share\IShare::class);
        $attributes=$this->createMock(\OCP\Share\IAttributes::class);
        $attributes->method('getAttribute')->willReturnCallback(fn(): bool => $this->download);
        $share->method('getAttributes')->willReturn($attributes);
        $shared->method('getShare')->willReturn($share);
        $shared->method('instanceOfStorage')->willReturn(true);
        $storage=$shared;
        $file->method('getStorage')->willReturn($storage);
        $folder=$this->createMock(Folder::class);
        $folder->method('get')->willReturn($file);
        $roots=$this->createMock(IRootFolder::class);
        $roots->method('getUserFolder')->willReturn($folder);
        $guard=$this->createMock(FilesAccessGuard::class);
        $guard->method('assertVisible')->willReturnCallback(function(): void { if (!$this->visible) throw new ToolFailure('Unavailable'); });
        $backups=\OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture::location($this);
        $tools=$this->createMock(ToolRegistry::class);
        $tools->method('allowsTool')->willReturnCallback(fn(): bool => $this->allowed);
        $attachments=new class($this->created,$this->associatedDraft) {
            public array $bytes=[];
            public function __construct(public int &$created,public ?int &$associatedDraft) {}
            public function addFileFromString(string $uid,string $name,string $mime,string $bytes,?string $cid,?string $disposition): object {
                $id=++$this->created; $this->bytes[$id]=$bytes;
                return new class($id) { public function __construct(private int $id){} public function getId(): int{return $this->id;} };
            }
            public function getAttachment(string $uid,int $id): array {
                return [new class($this->associatedDraft) {public function __construct(private ?int $draft){} public function getLocalMessageId(): ?int{return $this->draft;} public function getFileName(): string{return 'budget.pdf';}public function getMimeType(): string{return 'application/pdf';}},new class($this->bytes[$id]) {public function __construct(private string $s){} public function getContent(): string{return $this->s;} }];
            }
        };
        $services=$this->createMock(MailServices::class);
        $services->method('attachmentService')->willReturn($attachments);
        return new MailFilesSource($roots,$guard,$backups,$services,$tools->allowsTool(...));
    }
    public function testPreviewIsReadOnlyAndStagingPreservesTheExactBytes(): void {
        $s=$this->source(); $manifest=$s->preview('alice',['/budget.pdf']);
        self::assertSame(0,$this->created);
        self::assertSame([['type'=>'local','id'=>1]],$s->stage('alice',$manifest));
        self::assertSame(1,$this->created);
    }
    public function testContentChangedWithoutNewEtagIsRejectedBeforeStaging(): void {
        $s=$this->source();$manifest=$s->preview('alice',['/budget.pdf']);
        $this->bytes='changed bytes';
        try {$s->stage('alice',$manifest);self::fail('Changed bytes were attached.');}
        catch(ToolFailure) {self::assertSame(0,$this->created);}
    }
    public function testRevokedSourcePermissionAfterPreviewPreventsStaging(): void {
        $s=$this->source(); $m=$s->preview('alice',['/budget.pdf']); $this->allowed=false;
        try {$s->stage('alice',$m);self::fail('Revoked access was used.');}
        catch(ToolFailure) {self::assertSame(0,$this->created);}
    }
    public function testNativeDownloadDenialPreventsSnapshotAndStaging(): void {
        $s=$this->source(); $this->download=false;
        try {$s->preview('alice',['/budget.pdf']);self::fail('Download denied source was copied.');}
        catch(ToolFailure) {self::assertSame(0,$this->created);}
    }
    public function testFreshRepairReusesItsVerifiedSnapshotWithoutCreatingAnotherAttachment(): void {
        $s=$this->source();$m=$s->preview('alice',['/budget.pdf']);$s->stage('alice',$m);
        $result=$s->stage('alice',$m,[['attachment_id'=>1,'file_id'=>50,'sha256'=>$m[0]['sha256']]]);
        self::assertSame([['type'=>'local','id'=>1]],$result);
        self::assertSame(1,$this->created);
    }
    public function testRepairCannotTakeAnAttachmentNowAssociatedWithAnotherDraft(): void {
        $s=$this->source();$m=$s->preview('alice',['/budget.pdf']);$s->stage('alice',$m);
        $this->associatedDraft=99;
        $this->expectException(ToolFailure::class);
        $s->stage('alice',$m,[['attachment_id'=>1,'file_id'=>50,'sha256'=>$m[0]['sha256']]],77);
    }
    public function testHiddenSourcesAreNotStaged(): void {
        $s=$this->source();$this->visible=false;
        $this->expectException(ToolFailure::class);
        $s->preview('alice',['/budget.pdf']);
    }
}
