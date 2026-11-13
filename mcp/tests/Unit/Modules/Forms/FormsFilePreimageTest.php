<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Attachments\FormsFilePreimage;
use PHPUnit\Framework\TestCase;
final class FormsFilePreimageTest extends TestCase {
    private function node(string $bytes):\OCP\Files\File {
        $storage=$this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('fopen')->willReturnCallback(static function(string $path,string $mode)use($bytes){
            self::assertSame('files/target',$path);self::assertSame('rb',$mode);
            $stream=fopen('php://memory','w+');fwrite($stream,$bytes);rewind($stream);return $stream;
        });
        $file=$this->createMock(\OCP\Files\File::class);$file->method('getInternalPath')->willReturn('files/target');$file->method('getStorage')->willReturn($storage);
        $file->expects(self::never())->method('fopen');$file->expects(self::never())->method('lock');
        return $file;
    }
    public function testLockedSinkAcceptsExactBinaryPreimageWithoutViewReentry():void {
        $bytes="\x00\xfforiginal";
        FormsFilePreimage::assert($this->node($bytes),['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)]);
    }
    public function testSameSizeManualEditIsRefusedEvenWhenNativeEtagCollides():void {
        $this->expectException(\RuntimeException::class);
        FormsFilePreimage::assert($this->node('modified'),['size'=>8,'sha256'=>hash('sha256','original')]);
    }
    public function testChangedSizeIsRefused():void {
        $this->expectException(\RuntimeException::class);
        FormsFilePreimage::assert($this->node('different bytes'),['size'=>8,'sha256'=>hash('sha256','original')]);
    }
}
