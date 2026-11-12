<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailNativeTagsTest extends IntegrationTestCase {
    public function testNativeCacheRefusalAndLostDurableReceiptsRetainEvidenceWithoutReplay():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        foreach(['metadata','epoch','remote_receipt','cache_receipt']as$mode){$r=MailActor::run(MailActor::ALICE,'c5-fault',['account_id'=>$id,'mode'=>$mode]);self::assertSame('uncertain',$r['operation']['state'],json_encode($r));self::assertTrue($r['replayed']);self::assertFalse($r['operation']['safe_to_retry']);self::assertSame($mode==='cache_receipt',$r['cache']);self::assertTrue($r['remote']);self::assertSame($r['safety_before'],$r['safety_after']);if($mode==='remote_receipt')self::assertArrayNotHasKey('remote',$r['operation']['mutation']);else self::assertSame('verified',$r['operation']['mutation']['remote']['outcome']);self::assertArrayNotHasKey('cache',$r['operation']['mutation']);}
    }
    public function testRefusalsLostAcknowledgementsAndUnrelatedFlagChangesNeverReplayStore():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        foreach(['no','lost_ack','generation','session_only','permanent_revoked','missing_uid','unrelated_flags','explicit_keyword']as$mode){$r=MailActor::run(MailActor::ALICE,'c5-protocol',['account_id'=>$id,'mode'=>$mode]);self::assertArrayHasKey('operation',$r,json_encode($r));$wire=in_array($mode,['no','lost_ack','unrelated_flags','explicit_keyword'],true);self::assertSame($wire?'uncertain':'failed_before_effect',$r['operation']['state'],$mode.':'.json_encode($r));self::assertSame($wire?1:0,$r['stores'],$mode);self::assertTrue($r['replayed']);self::assertFalse($r['operation']['safe_to_retry']);if($mode!=='explicit_keyword')self::assertArrayNotHasKey('remote',$r['operation']['mutation']);else self::assertSame('verified',$r['operation']['mutation']['remote']['outcome']);}
    }
    public function testCanonicalKeywordAddRemoveNoopAndSelectedGenerationPreserveEveryOtherFlag():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');
        $owner=MailActor::ALICE;$accountId=MailActor::run($owner,'accounts')['accounts'][0]['id'];$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($owner,$accountId);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$name='c5-wire-'.bin2hex(random_bytes(6));$box=$manager->createMailbox($account,$name);$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);$safe=MailActor::run($owner,'c1-safety');
        $fence=static function(?\Closure $before=null):object{return new class($before) implements \OCA\Mail\Service\MutationFence {public array $proof=[];public function __construct(private ?\Closure $before){}public function beforePreconditions(array $b):bool{return true;}public function beforeWire(array $b):bool{return $this->before===null||($this->before)();}public function recordRemote(array $p):bool{$this->proof=$p;return true;}public function beforeCache(array $b):bool{return true;}public function recordCache(array $p):bool{return true;}};};
        $read=static function(object $c,string $box,int $uid):array{$q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$q->flags();$m=$c->fetch($box,$q,['ids'=>new \Horde_Imap_Client_Ids([$uid])])->first();$flags=array_map('strtolower',$m->getFlags());sort($flags);return $flags;};
        try {
            $uids=[];for($i=0;$i<2;$i++)$uids[]=Server::get('OCA\\Mail\\IMAP\\MessageMapper')->save($client,$box,"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: exact tag\r\nMessage-ID: <same-tag@example.test>\r\n\r\nNative tag fixture",[\Horde_Imap_Client::FLAG_SEEN,\Horde_Imap_Client::FLAG_FLAGGED]);
            $client->openMailbox($name,\Horde_Imap_Client::OPEN_READWRITE);$g=(string)$client->status($name,\Horde_Imap_Client::STATUS_UIDVALIDITY)['uidvalidity'];$before=$read($client,$name,$uids[0]);$other=$read($client,$name,$uids[1]);
            $r=['kind'=>'tag','account_id'=>$accountId,'message_id'=>17,'mailbox_id'=>$box->getId(),'mailbox'=>$name,'generation'=>$g,'uid'=>(string)$uids[0],'tag_id'=>7,'imap_label'=>'$reviewed','value'=>true];
            $proof=$client->setTagGuarded($r,$fence());self::assertSame('verified',$proof['outcome']);$expected=[...$before,'$reviewed'];sort($expected);self::assertSame($expected,$read($client,$name,$uids[0]));self::assertSame($other,$read($client,$name,$uids[1]));
            self::assertSame('already_desired',$client->setTagGuarded($r,$fence())['outcome']);$r['value']=false;$client->setTagGuarded($r,$fence());self::assertSame($before,$read($client,$name,$uids[0]));
            foreach(['generation','reconnect','revoked']as$mode){$bad=$r;$bad['value']=true;if($mode==='generation')$bad['generation']=(string)((int)$g+1);$refused=false;try{$client->setTagGuarded($bad,$fence($mode==='reconnect'?static function()use($client):bool{$client->logout();return true;}:($mode==='revoked'?static fn():bool=>false:null)));}catch(\Throwable){$refused=true;}self::assertTrue($refused);self::assertSame($before,$read($client,$name,$uids[0]));}
            // Native default labels have aliases. Unassigning Important must also remove its native aliases.
            $client->store($name,['ids'=>new \Horde_Imap_Client_Ids([$uids[0]]),'add'=>['$important']]);$r['imap_label']='$label1';$r['value']=false;$client->setTagGuarded($r,$fence());self::assertSame($before,$read($client,$name,$uids[0]));
            self::assertSame($safe,MailActor::run($owner,'c1-safety'));
        }finally{$client->logout();$manager->deleteMailbox($account,$box);}
    }
}
