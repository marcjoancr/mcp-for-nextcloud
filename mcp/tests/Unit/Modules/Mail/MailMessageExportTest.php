<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Mail\Integration\MailContentReader;
use OCA\Mcp\Modules\Mail\Files\MailMessageExport;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
final class MailMessageExportTest extends TestCase {
    private function reader():object {self::assertTrue(class_exists(MailContentReader::class),'A closed native content adapter is required.');return $this->createMock(MailContentReader::class);}
    public function testPreviewProjectsNoPrivateBytesAndDoesNotWrite():void {$reader=$this->reader();$reader->method('readRaw')->willReturn($this->raw('private body'));$files=$this->createMock(IncomingFileWriter::class);$files->method('preview')->willReturn(['path'=>'/message.eml','size'=>12]);$files->expects(self::never())->method('save');$service=new MailMessageExport($reader,$files,new PlanState((new InMemoryConfig())->mock($this)));$plan=$service->preview(['message_id'=>7,'path'=>'/message.eml'],'alice');self::assertStringNotContainsString('private body',json_encode($plan));self::assertSame(hash('sha256','private body'),$plan['content_sha256']);self::assertSame('export_message',$plan['action']);}
    public function testChangedSourceBytesRefuseBeforeFilesWrite():void {$reader=$this->reader();$reader->method('readRaw')->willReturnOnConsecutiveCalls($this->raw('first'),$this->raw('second'));$files=$this->createMock(IncomingFileWriter::class);$files->method('preview')->willReturn(['path'=>'/message.eml']);$files->expects(self::never())->method('save');$service=new MailMessageExport($reader,$files,new PlanState((new InMemoryConfig())->mock($this)));$args=['message_id'=>7,'path'=>'/message.eml'];$plan=$service->preview($args,'alice');$this->expectException(PlanChanged::class);$service->save($args+['plan_state'=>$plan['plan_state']],'alice');}
    public function testDeniedFilesDestinationPrecedesPrivateMailRead():void {
        $reader=$this->reader();$reader->expects(self::never())->method('readRaw');
        $files=$this->createMock(IncomingFileWriter::class);$files->method('preview')->willThrowException(new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Destination unavailable.'));
        $service=new MailMessageExport($reader,$files,new PlanState((new InMemoryConfig())->mock($this)));
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$service->preview(['message_id'=>7,'path'=>'/hidden/message.eml'],'alice');
    }
    private function raw(string $bytes):array{return ['bytes'=>$bytes,'byte_count'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'source'=>['message_id'=>7,'account_id'=>2,'mailbox_id'=>3,'uid'=>'7','uidvalidity'=>'42','profile_revision'=>str_repeat('a',64),'auth_revision'=>1,'mailbox_revision'=>str_repeat('b',64),'observed_at'=>time()]];}
}
