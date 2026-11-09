<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailServices,MailTagGateway,MailTagBoundary,MailCapabilities};
use OCP\{IConfig};use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
final class MailTagGatewayTest extends TestCase {
    public function testCanonicalCollisionsReservedKeywordsAndTruncationRefuseWithoutInsertion():void {
        $boundary=$this->createMock(MailTagBoundary::class);$boundary->method('verified')->willReturn(true);
        $native=$this->createMock(MailServices::class);$native->method('isEnabledFor')->willReturn(true);$native->expects(self::never())->method('clientFactory');$native->expects(self::never())->method('smtpFactory');$native->expects(self::never())->method('newTag');
        $native->method('tagCodec')->willReturn(new class{public function create(string $label):string{return '$'.strtolower(substr(str_replace(' ','_',$label),0,63));}});
        $map=new class{public array $rows=[];public function getAllTagsForUser(string $uid):array{return $this->rows;}};$native->method('newTagMapper')->willReturn($map);
        $apps=$this->createMock(IAppManager::class);$apps->method('getAppVersion')->willReturn('5.12.5');$config=$this->createMock(IConfig::class);$config->method('getSystemValueString')->willReturn('35.0.2.2');$caps=new MailCapabilities($native,$apps,$config,null,$boundary);$l10n=$this->createMock(\OCP\L10N\IFactory::class);$l10n->method('get')->willReturn($this->createMock(\OCP\IL10N::class));$g=new MailTagGateway($native,$caps,$boundary,$this->createMock(\OCP\IDBConnection::class),$l10n);
        $r=$g->prepare('alice',['name'=>'Reviewed','color'=>'#AABBCC']);self::assertSame('#aabbcc',$r['color']);self::assertSame('$reviewed',$r['imap_label']);
        foreach(['Label1','bad\\Seen','bad*',str_repeat('a',64),"hidden\u{202e}name",' edge']as$name){$refused=false;try{$g->prepare('alice',['name'=>$name,'color'=>'#123456']);}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused,'Unsafe or lossy native keyword: '.$name);}
        $map->rows=[new class{public function getId():int{return 7;}public function getUserId():string{return 'alice';}public function getDisplayName():string{return 'TO DO';}public function getColor():?string{return null;}public function getImapLabel():string{return '$to_do';}}];
        $this->expectException(\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::class);$g->prepare('alice',['name'=>'to_do','color'=>'#123456']);
    }
}
