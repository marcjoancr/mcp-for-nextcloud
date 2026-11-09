<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailTagTarget,MailMessageLocator,MailSettingsGateway,MailCapabilities,MailNativeBoundary,MailServices};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{IConfig};use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
final class MailTagTargetTest extends TestCase {
    private function fixture():array {
        $state=(object)['ready'=>true,'generation'=>'42','owner'=>'alice','name'=>'Reviewed','present'=>false,'label'=>'$reviewed'];
        $m=new class($state){public function __construct(private object $s){}public function getId():int{return 17;}public function getUid():int{return 9;}public function getTagCacheGeneration():?string{return $this->s->generation;}};
        $b=new class{public function getId():int{return 5;}public function getName():string{return 'INBOX';}public function getSyncNewToken():string{return base64_encode('V42');}public function getSyncChangedToken():string{return base64_encode('V42');}public function getSyncVanishedToken():string{return base64_encode('V42');}};
        $account=new class{public function getId():int{return 4;}};$locator=$this->createMock(MailMessageLocator::class);$locator->method('locate')->willReturn(['message'=>$m,'mailbox'=>$b,'account'=>$account]);
        $tag=new class($state){public function __construct(private object $s){}public function getId():int{return 7;}public function getUserId():string{return $this->s->owner;}public function getDisplayName():string{return $this->s->name;}public function getImapLabel():string{return $this->s->label;}public function getColor():?string{return null;}public function getIsDefaultTag():bool{return false;}};
        $native=$this->createMock(MailServices::class);$native->method('isEnabledFor')->willReturn(true);$native->method('tagMapper')->willReturn(new class($tag){public function __construct(private object $tag){}public function getTagForUser(int $id,string $uid):object{return $this->tag;}});$native->method('exactTags')->willReturn(new class($state){public function __construct(private object $s){}public function requireStorage():void{}public function verified(string $uid,int $id):bool{return $this->s->ready;}public function has(string $uid,int $id,int $tag):bool{return $this->s->present;}});
        $apps=$this->createMock(IAppManager::class);$apps->method('getAppVersion')->willReturn('5.12.5');$config=$this->createMock(IConfig::class);$config->method('getSystemValueString')->willReturn('35.0.2');$boundary=new class($apps)extends MailNativeBoundary {public function verified():bool{return false;}public function movesVerified():bool{return false;}public function foldersVerified():bool{return false;}public function tagsVerified():bool{return true;}};
        $caps=new MailCapabilities($native,$apps,$config,$boundary);$settings=$this->createMock(MailSettingsGateway::class);$settings->method('readConnection')->willReturn(['revision'=>'p']);return [new MailTagTarget($locator,$settings,$caps,$native),$state,['account_id'=>4,'message_id'=>17,'tag_id'=>7,'value'=>true]];
    }
    public function testExactOwnedTagMetadataAndMembershipAreBoundWithoutAnyNetworkService():void {
        [$target,$s,$args]=$this->fixture();$a=$target->snapshot('alice',$args);self::assertSame('Reviewed',$a['tag']['name']);self::assertFalse($a['before']);$s->name='Renamed';$b=$target->snapshot('alice',$args);self::assertNotSame($a['request']['tag_revision'],$b['request']['tag_revision']);$s->present=true;$c=$target->snapshot('alice',$args);self::assertNotSame($b['request']['tag_cache_revision'],$c['request']['tag_cache_revision']);
    }
    public function testForeignTagsAndUnprovenEpochsCannotBecomeTargets():void {
        foreach(['owner'=>'bob','ready'=>false,'generation'=>'43','label'=>'\\Seen']as$key=>$value){[$target,$s,$args]=$this->fixture();$s->$key=$value;$refused=false;try{$target->snapshot('alice',$args);}catch(BeforeEffectFailure){$refused=true;}self::assertTrue($refused,$key.' must refuse');}
    }
}
