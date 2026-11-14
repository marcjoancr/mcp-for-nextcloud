<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedRecovery;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
final class FormsLinkedRecoveryTest extends TestCase {
    private function fixture(string $bytes,bool $copies=true,bool $versionsEnabled=true):array {
        $root=$this->createMock(IRootFolder::class);$home=$this->createMock(Folder::class);$file=$this->createMock(File::class);$backup=$this->createMock(File::class);
        $root->method('getUserFolder')->with('alice')->willReturn($home);$home->method('get')->with('MCP backups/results.csv.bak')->willReturn($backup);$file->method('getId')->willReturn(17);$backup->method('getId')->willReturn(18);
        $backup->method('fopen')->willReturnCallback(static function()use($bytes){$stream=fopen('php://memory','w+b');fwrite($stream,$bytes);rewind($stream);return $stream;});
        $service=new FormsLinkedRecovery($root,$this->createMock(IUserManager::class),static fn():object=>new \stdClass(),static fn(string $uid):bool=>$copies,static fn(string $uid):bool=>$versionsEnabled);
        return [$service,$file];
    }
    public function testInitialNewOutputCannotPromiseOverwritesWithoutVersionsEvenWithCopies():void {
        [$recovery,$file]=$this->fixture('old',true,false);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Versions must be enabled');
        $recovery->qualify('alice',$file);
    }
    public function testExactBackupPreimageIsRequiredBeforeTheDestinationCanOpen():void {
        [$recovery,$file]=$this->fixture('old');$proof=$recovery->capture('alice',$file,['size'=>3,'sha256'=>hash('sha256','old')]);
        $recovery->assert('alice',$file,$proof,'MCP backups/results.csv.bak','content');self::assertTrue(true);
    }
    public function testSameSizeCorruptedBackupDoesNotAuthorizeOverwriting():void {
        [$recovery,$file]=$this->fixture('bad');$proof=$recovery->capture('alice',$file,['size'=>3,'sha256'=>hash('sha256','old')]);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('recovery preimage');
        $recovery->assert('alice',$file,$proof,'MCP backups/results.csv.bak','content');
    }
    public function testMissingRequiredCopyCannotBeReplacedByAnAppEnablementClaim():void {
        [$recovery,$file]=$this->fixture('old');$proof=$recovery->capture('alice',$file,['size'=>3,'sha256'=>hash('sha256','old')]);
        $this->expectException(\RuntimeException::class);$recovery->assert('alice',$file,$proof,'','content');
    }
    public function testUnexpectedVersionsServiceIsRefusedWhenCopiesAreSuspended():void {
        [$recovery,$file]=$this->fixture('old',false);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Versions backend');
        $recovery->capture('alice',$file,['size'=>3,'sha256'=>hash('sha256','old')]);
    }
}
