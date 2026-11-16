<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedAudience;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
final class FormsLinkedAudienceTest extends TestCase {
    private SqliteDatabase $sql;private FormsLinkedAudience $audience;private string $parentPath='/alice/files/exports';private string $destinationPath='/exports/results.csv';private int $now=1000;private Folder $reportedParent;
    protected function setUp():void {
        $this->sql=new SqliteDatabase($this,[]);
        $this->sql->dbal->executeStatement('CREATE TABLE share (id INTEGER PRIMARY KEY, file_source INTEGER, share_type INTEGER, share_with TEXT, permissions INTEGER, expiration TEXT, token TEXT)');
        $this->sql->dbal->executeStatement('CREATE TABLE group_user (gid TEXT, uid TEXT)');
        $root=$this->createMock(IRootFolder::class);$home=$this->createMock(Folder::class);$parent=$this->createMock(Folder::class);$file=$this->createMock(File::class);$user=$this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');$root->method('getUserFolder')->with('alice')->willReturn($home);$this->reportedParent=$home;
        $home->method('get')->willReturnCallback(static fn(string $path)=>in_array($path,['exports','moved'],true)?$parent:$file);
        $home->method('nodeExists')->willReturn(true);
        foreach([[$home,1],[$parent,2],[$file,3]] as [$node,$id]) {$node->method('getId')->willReturn($id);$node->method('getOwner')->willReturn($user);}
        $home->method('getPath')->willReturn('/alice/files');$parent->method('getPath')->willReturnCallback(fn():string=>$this->parentPath);$parent->method('getParent')->willReturnCallback(fn():Folder=>$this->reportedParent);
        $this->audience=new FormsLinkedAudience($root,$this->sql->connection(),fn():int=>$this->now);
    }
    private function snapshot():array {return $this->audience->snapshot('alice',$this->destinationPath);}
    public function testNewInheritedOrDirectAudienceAndLocationChangesInvalidateTheApproval():void {
        $before=$this->snapshot();
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (1,2,0,'bob',1,NULL,'private-token')");
        $inherited=$this->snapshot();self::assertNotSame($before['digest'],$inherited['digest']);
        self::assertSame([['type'=>0,'recipient'=>'bob','permissions'=>1,'expires'=>null]],$inherited['recipients']);
        self::assertStringNotContainsString('private-token',json_encode($inherited));
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (2,3,3,'',1,NULL,'public-secret')");
        $direct=$this->snapshot();self::assertNotSame($inherited['digest'],$direct['digest']);
        self::assertStringNotContainsString('public-secret',json_encode($direct));
        $this->parentPath='/alice/files/moved';$this->destinationPath='/moved/results.csv';self::assertNotSame($direct['digest'],$this->snapshot()['digest']);
    }
    public function testExternalStorageParentChainCannotEscapeTheAuthorizedHome():void {
        $mountRoot=$this->createMock(Folder::class);$outside=$this->createMock(Folder::class);$owner=$this->createMock(IUser::class);$owner->method('getUID')->willReturn('alice');
        $mountRoot->method('getId')->willReturn(4);$mountRoot->method('getOwner')->willReturn($owner);$mountRoot->method('getPath')->willReturn('/alice/files');
        $outside->method('getId')->willReturn(5);$outside->method('getOwner')->willReturn($owner);$outside->method('getPath')->willReturn('/external/s3-root');
        $mountRoot->method('getParent')->willReturn($outside);$this->reportedParent=$mountRoot;
        $result=$this->snapshot();
        self::assertSame([], $result['recipients']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['digest']);
    }
    public function testMembershipAndExpirationAreReadFreshlyEvenWithoutShareRowChanges():void {
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (1,2,1,'team',1,'1970-01-01 00:20:00',NULL)");
        $before=$this->snapshot();
        $this->sql->dbal->executeStatement("INSERT INTO group_user VALUES ('team','bob')");
        $members=$this->snapshot();self::assertNotSame($before['digest'],$members['digest']);
        self::assertStringNotContainsString('bob',json_encode($members['recipients']));
        $this->now=1201;self::assertNotSame($members['digest'],$this->snapshot()['digest']);
    }
    public function testUnsupportedRecipientProviderCannotBeSilentlyOmitted():void {
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (1,2,7,'circle',1,NULL,NULL)");
        $this->expectException(\RuntimeException::class);$this->snapshot();
    }
}
