<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedAudience;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\Files\{IRootFolder,Folder,File};
use OCP\IUser;
use PHPUnit\Framework\TestCase;
final class FormsLinkedSharedAudienceTest extends TestCase {
    private SqliteDatabase $sql;
    protected function setUp():void {
        $this->sql=new SqliteDatabase($this,[]);
        $this->sql->dbal->executeStatement('CREATE TABLE share (id INTEGER PRIMARY KEY, file_source INTEGER, share_type INTEGER, share_with TEXT, permissions INTEGER, expiration TEXT, token TEXT)');
        $this->sql->dbal->executeStatement('CREATE TABLE group_user (gid TEXT, uid TEXT)');
    }
    private function audience(?\Closure $resolve):FormsLinkedAudience {
        $root=$this->createMock(IRootFolder::class);$home=$this->createMock(Folder::class);$parent=$this->createMock(Folder::class);$file=$this->createMock(File::class);
        $actor=$this->createMock(IUser::class);$owner=$this->createMock(IUser::class);
        $actor->method('getUID')->willReturn('alice');$owner->method('getUID')->willReturn('bob');
        $root->method('getUserFolder')->with('alice')->willReturn($home);
        $home->method('get')->willReturnCallback(static fn(string $path)=>$path==='exports'?$parent:$file);$home->method('nodeExists')->willReturn(true);
        $home->method('getId')->willReturn(1);$home->method('getPath')->willReturn('/alice/files');$home->method('getOwner')->willReturn($actor);
        $parent->method('getId')->willReturn(9);$parent->method('getPath')->willReturn('/alice/files/exports');$parent->method('getOwner')->willReturn($owner);
        $parent->method('getParent')->willReturn($home);$parent->method('getPermissions')->willReturn(1);$file->method('getId')->willReturn(10);$file->method('getOwner')->willReturn($owner);
        return new FormsLinkedAudience($root,$this->sql->connection(),static fn():int=>1000,null,null,$resolve);
    }
    public function testSharedAudienceUsesBoundOwnerAncestryAndRedactsNamesWithoutSharePermission():void {
        $origin=['uid'=>'alice','tool'=>'forms_link_results_file','references'=>['owner_id'=>'alice','form_id'=>4]];
        $calls=0;$resolver=static function(string $uid,string $path,?array $proof)use(&$calls,$origin):array {
            $calls++;self::assertSame('alice',$uid);self::assertSame('/exports/results.csv',$path);self::assertSame($origin,$proof);
            return ['owner_uid'=>'bob','owner_incarnation_digest'=>hash('sha256','bob-current'),'destination_permissions'=>1,'ids'=>[100,101,102],'bindings'=>[
                ['id'=>102,'path'=>'/bob/files/projects/exports'],
                ['id'=>101,'path'=>'/bob/files/projects'],
                ['id'=>100,'path'=>'/bob/files'],
            ],'share_identity'=>['share_id'=>33,'source_id'=>101,'mount'=>'/alice/files/exports','storage_id'=>'shared-storage-1','path'=>'/exports/results.csv','permissions'=>1]];
        };
        $audience=$this->audience($resolver);
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (1,101,0,'private-recipient',1,NULL,'private-token')");
        $before=$audience->snapshot('alice','/exports/results.csv',$origin);
        self::assertSame(1,$calls);
        self::assertSame([
            ['type'=>'owner','recipient'=>'bob'],
            ['type'=>'shared','recipient'=>'shared audience','count'=>1],
        ],$before['recipients']);
        self::assertStringNotContainsString('private-recipient',json_encode($before));
        self::assertStringNotContainsString('private-token',json_encode($before));
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (2,100,0,'another-private-recipient',1,NULL,NULL)");
        $after=$audience->snapshot('alice','/exports/results.csv',$origin);
        self::assertNotSame($before['digest'],$after['digest']);
        self::assertSame('owner',$after['recipients'][0]['type']);
        self::assertSame('shared audience',$after['recipients'][1]['recipient']);
        self::assertSame(2,$after['recipients'][1]['count']);
        self::assertStringNotContainsString('another-private-recipient',json_encode($after));
    }
    public function testSharedAudienceWithoutResolverStaysClosed():void {
        $audience=$this->audience(null);
        $this->expectException(\RuntimeException::class);
        $audience->snapshot('alice','/exports/results.csv');
    }
}
