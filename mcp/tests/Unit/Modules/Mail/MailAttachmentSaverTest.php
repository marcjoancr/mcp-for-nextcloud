<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Modules\Mail\Files\MailAttachmentSaver;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class MailAttachmentSaverTest extends TestCase {
	public function testSharedFilesConfirmationIsPropagatedAsTheToolResult(): void {
		$attachment = ['id' => '2.1', 'name' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => 6, 'content_base64' => base64_encode('binary')];
		$reader = new class($attachment) extends MailAttachmentAccess {
			public function __construct(private array $row) {}
			public function read(string $userId, int $messageId, string $attachmentId): array { return $this->row; }
		};
		$destination = ['path' => '/Shared/report.pdf', 'folder' => '/Shared', 'size' => 6, 'access' => ['scope' => 'shared'], 'shared' => [], 'requiresSharedConfirmation' => true];
		$files = $this->createMock(IncomingFileWriter::class);
		$files->expects(self::exactly(2))->method('preview')->with('alice', '/Shared/report.pdf', 6)->willReturn($destination);
		$files->expects(self::once())->method('save')->with('alice', '/Shared/report.pdf', 'binary', false)
			->willReturn(['requiresConfirmation' => true, 'scope' => 'shared', 'message' => 'Confirm the shared destination.']);
		$saver = new MailAttachmentSaver($reader, $files, new PlanState((new InMemoryConfig())->mock($this)));
		$args = ['message_id' => 55, 'attachment_id' => '2.1', 'path' => '/Shared/report.pdf'];
		$plan = $saver->preview($args, 'alice');
		$result = $saver->save($args + ['plan_state' => $plan['plan_state']], 'alice');

		self::assertTrue($result['requiresConfirmation']);
		self::assertSame('Confirm the shared destination.', $result['message']);
		self::assertSame($plan['attachment'], $result['attachment']);
		self::assertArrayNotHasKey('file', $result);
	}

	public function testPreviewNeverWritesToFiles(): void {
		$reader = new class(['id' => '1', 'name' => 'x.bin', 'mimeType' => 'application/octet-stream', 'size' => 1, 'content_base64' => base64_encode('x')]) extends MailAttachmentAccess {
			public function __construct(private array $row) {}
			public function read(string $userId, int $messageId, string $attachmentId): array { return $this->row; }
		};
		$files = $this->createMock(IncomingFileWriter::class);
		$files->expects(self::once())->method('preview')->willReturn(['path' => '/x.bin']);
		$files->expects(self::never())->method('save');
		$saver = new MailAttachmentSaver($reader, $files, new PlanState((new InMemoryConfig())->mock($this)));
		$plan = $saver->preview(['message_id' => 1, 'attachment_id' => '1', 'path' => '/x.bin'], 'alice');
		self::assertArrayHasKey('plan_state', $plan);
	}
    public function testEquivalentDecodedBytesWithChangedSourceEncodingRequireFreshApproval():void {
        $reader=new class extends MailAttachmentAccess {public int $reads=0;public function __construct(){}public function readForSave(string $uid,int $message,string $part):array {return ['bytes'=>'a','byte_count'=>1,'sha256'=>hash('sha256','a'),'part_id'=>'2','filename'=>'a.bin','mime_type'=>'application/octet-stream','manifest_revision'=>str_repeat('a',64),'header_revision'=>str_repeat('b',64),'wire_sha256'=>str_repeat(++$this->reads===1?'c':'d',64)];}};
        $files=$this->createMock(IncomingFileWriter::class);$files->method('preview')->willReturn(['path'=>'/a.bin']);$files->expects(self::never())->method('save');$saver=new MailAttachmentSaver($reader,$files,new PlanState((new InMemoryConfig())->mock($this)));
        $args=['message_id'=>7,'attachment_id'=>'2','path'=>'/a.bin'];$plan=$saver->preview($args,'alice');$this->expectException(\OCA\Mcp\Mcp\Confirmation\PlanChanged::class);$saver->save($args+['plan_state'=>$plan['plan_state']],'alice');
    }

}
