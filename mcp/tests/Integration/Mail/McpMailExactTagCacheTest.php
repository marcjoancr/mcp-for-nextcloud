<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{Server,IDBConnection};
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailExactTagCacheTest extends IntegrationTestCase {
    public function testCachedQresyncFlagsCannotAdoptAnInterveningC5Assignment():void {$this->qresyncReuse(false);}
    public function testCachedQresyncFlagsCannotUndoTheirOwnNewMessageClassification():void {$this->qresyncReuse(true);}
    private function qresyncReuse(bool $classify):void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');
        $uid=MailActor::ALICE;$id=MailActor::run($uid,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($uid,$id);$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$sync=Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer');$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account);$imap=Server::get('OCA\\Mail\\IMAP\\MessageMapper');$box=$manager->getMailbox($uid,$f['boxes']['source']);$sp=new \ReflectionProperty($sync,'synchronizer');$cp=new \ReflectionProperty($sync,'newMessagesClassifier');$savedSync=$sp->getValue($sync);$savedClassifier=$cp->getValue($sync);$created=false;$tag=null;$target=null;$safe=MailNativeMutationActor::safety($uid);
        try {
            if($classify){try{$tag=$tags->getTagByImapLabel('$label1',$uid);}catch(\OCP\AppFramework\Db\DoesNotExistException){$tag=new \OCA\Mail\Db\Tag();$tag->setUserId($uid);$tag->setDisplayName('Important');$tag->setImapLabel('$label1');$tag->setColor('#123456');$tag=$tags->insert($tag);$created=true;}}
            else{$tag=$manager->createTag('C5 QRESYNC '.bin2hex(random_bytes(6)),'#123456',$uid);$created=true;$target=$manager->getMessage($uid,$f['messages'][0]);}
            $client->openMailbox($box->getName(),\Horde_Imap_Client::OPEN_READONLY);self::assertTrue($client->capability->isEnabled('QRESYNC'),'This regression must exercise real cached QRESYNC.');$token=$client->getSyncToken($box->getName());$token=base64_encode(implode(',',array_filter(explode(',',base64_decode($token,true)),static fn(string $part):bool=>!str_starts_with($part,'H'))));foreach(['New','Changed','Vanished']as$s){$method='setSync'.$s.'Token';$box->$method($token);}Server::get('OCA\\Mail\\Db\\MailboxMapper')->update($box);
            if($classify)$imap->save($client,$box,"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: QRESYNC classification\r\nMessage-ID: <same-c3@example.test>\r\n\r\nNative new snapshot",[]);
            else {$writer=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);try{$writer->store($box->getName(),['ids'=>new \Horde_Imap_Client_Ids([$target->getUid()]),'add'=>[\Horde_Imap_Client::FLAG_SEEN]]);}finally{$writer->logout();}$client->logout();$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account);$client->openMailbox($box->getName(),\Horde_Imap_Client::OPEN_READONLY);self::assertTrue($client->capability->isEnabled('QRESYNC'));}
            $after=static function($response)use($classify,$uid,$id,$target,$tag):void {
                if($classify)return;self::assertContains($target->getUid(),array_map(static fn($m)=>$m->getUid(),$response->getChangedMessages()),'The cached FLAGS payload must contain the selected UID before the external write.');$request=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailTagTarget::class)->snapshot($uid,['account_id'=>$id,'message_id'=>$target->getId(),'tag_id'=>$tag->getId(),'value'=>true])['request'];
                $fence=new class implements \OCA\Mail\Service\MutationFence {public function beforePreconditions(array $b):bool{return true;}public function beforeWire(array $b):bool{return true;}public function recordRemote(array $p):bool{return true;}public function beforeCache(array $b):bool{return true;}public function recordCache(array $p):bool{return true;}};
                Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->setTag($uid,$request,$fence);
            };
            $proxy=new class($imap,$after) extends \OCA\Mail\IMAP\Sync\Synchronizer {
                private ?\OCA\Mail\IMAP\Sync\Response $first=null;public bool $reused=false;public bool $overlap=false;
                public function __construct($mapper,private \Closure $after){parent::__construct($mapper);}
                public function sync(\Horde_Imap_Client_Base $client,\OCA\Mail\IMAP\Sync\Request $request,string $user,bool $qresync,\Psr\Log\LoggerInterface $logger,int $criteria=\Horde_Imap_Client::SYNC_ALL):\OCA\Mail\IMAP\Sync\Response {
                    $r=parent::sync($client,$request,$user,$qresync,$logger,$criteria);
                    if($criteria===\Horde_Imap_Client::SYNC_NEWMSGSUIDS){$this->first=$r;$new=array_map(static fn($m)=>$m->getUid(),$r->getNewMessages());$changed=array_map(static fn($m)=>$m->getUid(),$r->getChangedMessages());$this->overlap=array_intersect($new,$changed)!==[];($this->after)($r);}
                    if($criteria===\Horde_Imap_Client::SYNC_FLAGSUIDS)$this->reused=$r===$this->first;return $r;
                }
            };$sp->setValue($sync,$proxy);
            if($classify){$classifier=$this->createMock(\OCA\Mail\Service\Classification\NewMessagesClassifier::class);$classifier->expects(self::once())->method('classifyNewMessages')->willReturnCallback(static function($messages,$b,$a,$important)use($manager,&$target):bool{$target=$messages[0];$manager->tagMessage($a,$b->getName(),$target,$important,true);return true;});$cp->setValue($sync,$classifier);}
            $refused=false;$failure=[];try{$sync->sync($account,$client,$box,Server::get(\Psr\Log\LoggerInterface::class));}catch(\OCA\Mail\Exception\ServiceException $e){$refused=true;$cause=$e->getPrevious()??$e;$failure=['class'=>get_class($cause),'at'=>basename($cause->getFile()).':'.$cause->getLine()];}
            self::assertTrue($proxy->reused,'The FLAGS stage must consume the actual native cached response. '.json_encode($failure));if($classify)self::assertTrue($proxy->overlap,'The classifier regression needs the new UID in cached FLAGS too.');
            self::assertNotNull($target);self::assertTrue($tags->exactAssociations()->has($uid,$target->getId(),$tag->getId()),'Old cached flags must not erase the later exact association.');if(!$classify)self::assertTrue($refused,'A cached QRESYNC response must retain its original revision.');self::assertSame($safe,MailNativeMutationActor::safety($uid));
        }finally{$sp->setValue($sync,$savedSync);$cp->setValue($sync,$savedClassifier);$client->logout();if($created&&$tag!==null)$tags->delete($tag);MailNativeMoveActor::cleanup($uid,$f);}
    }
    public function testNativeOAuthEmailPrincipalChangeInvalidatesTheCachedIncarnation():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$uid=MailActor::ALICE;$id=MailActor::run($uid,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($uid,$id);$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id);$entities=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$message=Server::get('OCA\\Mail\\Service\\MailManager')->getMessage($uid,$f['messages'][0]);$saved=clone $account->getMailAccount();$email=$saved->getEmail();$auth=$saved->getAuthMethod();$profile=$message->getTagCacheProfile();$db=Server::get(IDBConnection::class);
        $stamp=static function($profile)use($db,$message):void{$q=$db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_profile',$q->createNamedParameter($profile))->where($q->expr()->eq('id',$q->createNamedParameter($message->getId())))->executeStatement();};
        try {
            // Exercise the real entity/profile/DB projection, without invoking an OAuth endpoint or changing credentials.
            $oauth=clone $saved;$oauth->setAuthMethod('xoauth2');$oauth->setEmail('c5-oauth@example.test');$entities->update($oauth);$stamp(\OCA\Mail\Db\TagProfile::account($oauth));$exact=$tags->exactAssociations();self::assertTrue($exact->verified($uid,$message->getId()));
            $changed=clone $oauth;$changed->setEmail('c5-other@example.test');$entities->update($changed);self::assertFalse($exact->verified($uid,$message->getId()));$refused=false;try{$tags->captureTagSnapshot(new \OCA\Mail\Account($oauth),$message->getMailboxId(),$message->getTagCacheGeneration());}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);
        }finally{$q=$db->getQueryBuilder();$q->update('mail_accounts')->set('email',$q->createNamedParameter($email))->set('auth_method',$q->createNamedParameter($auth))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();$stamp($profile);MailNativeMoveActor::cleanup($uid,$f);}
    }
    public function testLegacyQueuedHeaderClassificationIsIgnoredBeforeCallingTheLlm():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$ai=$this->createMock(\OCA\Mail\Service\AiIntegrations\AiIntegrationsService::class);$ai->expects(self::never())->method('isLlmProcessingEnabled');
        $job=new \OCA\Mail\BackgroundJob\FollowUpClassifierJob(Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),Server::get(\Psr\Log\LoggerInterface::class),Server::get('OCA\\Mail\\Service\\AccountService'),Server::get('OCA\\Mail\\Service\\MailManager'),$ai,Server::get('OCA\\Mail\\Db\\ThreadMapper'));
        $job->run(['messageId'=>'<same-c3@example.test>','mailboxId'=>1,'userId'=>MailActor::ALICE]);self::addToAssertionCount(1);
    }
    public function testNativeSingleAndBulkUiPathsUseExactIdsAndLegacyRefusalPrecedesNetwork():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$uid=MailActor::ALICE;$accountId=MailActor::run($uid,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($uid,$accountId);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$tag=$manager->createTag('C5 UI '.bin2hex(random_bytes(6)),'#123456',$uid);$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);$safe=MailNativeMutationActor::safety($uid);
        try {
            $a=$manager->getMessage($uid,$f['messages'][0]);$b=$manager->getMessage($uid,$f['messages'][1]);$box=$manager->getMailbox($uid,$a->getMailboxId());$exact=$tags->exactAssociations();$manager->tagMessage($account,$box->getName(),$a,$tag,true);self::assertTrue($exact->has($uid,$a->getId(),$tag->getId()));self::assertFalse($exact->has($uid,$b->getId(),$tag->getId()));
            $manager->tagMessagesWithClient($client,$account,$box,[$a,$b],$tag,true);$manager->tagMessage($account,$box->getName(),$a,$tag,false);self::assertFalse($exact->has($uid,$a->getId(),$tag->getId()));self::assertTrue($exact->has($uid,$b->getId(),$tag->getId()));
            $db=Server::get(IDBConnection::class);$q=$db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_generation',$q->createNamedParameter(null))->where($q->expr()->eq('id',$q->createNamedParameter($a->getId())))->executeStatement();$client->logout();$refused=false;try{$manager->tagMessagesWithClient($client,$account,$box,[$a],$tag,true);}catch(\OCA\Mail\Exception\ServiceException){$refused=true;}self::assertTrue($refused);self::assertNull((new \ReflectionProperty(\Horde_Imap_Client_Socket::class,'_connection'))->getValue($client),'Unknown provenance must refuse before opening a connection.');self::assertSame($safe,MailNativeMutationActor::safety($uid));
        }finally{$client->logout();$tags->delete($tag);MailNativeMoveActor::cleanup($uid,$f);}
    }
    public function testAConcurrentCacheWriterInvalidatesTheNativeUiWriteSnapshot():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$uid=MailActor::ALICE;$id=MailActor::run($uid,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($uid,$id);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$tag=$manager->createTag('C5 UI race '.bin2hex(random_bytes(6)),'#123456',$uid);$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id);$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);$prop=new \ReflectionProperty($manager,'imapMessageMapper');$original=$prop->getValue($manager);
        try {
            $m=$manager->getMessage($uid,$f['messages'][0]);$box=$manager->getMailbox($uid,$m->getMailboxId());$exact=$tags->exactAssociations();$imap=$this->createMock(\OCA\Mail\IMAP\MessageMapper::class);$imap->expects(self::once())->method('addFlag')->willReturnCallback(static function()use($exact,$uid,$m,$tag):void{$exact->set($uid,$m->getId(),$tag->getId(),false);});$prop->setValue($manager,$imap);
            $refused=false;try{$manager->tagMessagesWithClient($client,$account,$box,[$m],$tag,true);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused,'A native UI cache write must retain its pre-wire revision.');self::assertFalse($exact->has($uid,$m->getId(),$tag->getId()));
        }finally{$prop->setValue($manager,$original);$client->logout();$tags->delete($tag);MailNativeMoveActor::cleanup($uid,$f);}
    }
    public function testNativeKeywordConversionCannotInventATagByTruncatingAServerKeyword():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard only');$uid=MailActor::ALICE;$accountId=MailActor::run($uid,'accounts')['accounts'][0]['id'];$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId)->getMailAccount();$empty=new \OCA\Mail\AddressList([]);
        $model=new \OCA\Mail\Model\IMAPMessage(messageId:9,realMessageId:'<native-tags@example.test>',flags:['$'.str_repeat('x',64),'$labelwork','$reviewed'],from:$empty,to:$empty,cc:$empty,bcc:$empty,replyTo:$empty,subject:'Native keyword decoding',plainMessage:'',htmlMessage:'',hasHtmlMessage:false,attachments:[],inlineAttachments:[],hasAttachments:false,scheduling:[],imapDate:new \Horde_Imap_Client_DateTime('2026-11-09T12:00:00+00:00'),rawReferences:'',dispositionNotificationTo:'',hasDkimSignature:false,hasAiGeneratedHeader:false,phishingDetails:[],unsubscribeUrl:null,isOneClickUnsubscribe:false,unsubscribeMailto:null,rawInReplyTo:'',isEncrypted:false,isSigned:false,signatureIsValid:false,htmlService:Server::get('OCA\\Mail\\Service\\Html'),isPgpMimeEncrypted:false);
        $message=$model->toDbMessage(1,$account);self::assertSame(['$label2','$reviewed'],array_map(static fn($tag):string=>$tag->getImapLabel(),$message->getTags()));
    }
    public function testNativeImportUpdateRelatedDataAndDeletionNeverShareHeaderAssociations():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Latest native guard qualification only');
        self::assertTrue(Server::get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class)->tagsSourceVerified(),'Every manifest path must match the installed native v4 bytes.');
        $uid=MailActor::ALICE;$accountId=MailActor::run($uid,'accounts')['accounts'][0]['id'];$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$mapper=Server::get('OCA\\Mail\\Db\\MessageMapper');$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$boxes=Server::get('OCA\\Mail\\Db\\MailboxMapper');
        $tag=$manager->createTag('C5 exact '.bin2hex(random_bytes(6)),'#123456',$uid);$box=$manager->createMailbox($account,'c5-cache-'.bin2hex(random_bytes(6)));$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);
        $db=Server::get(IDBConnection::class);$legacyId=null;
        try {
            $client->openMailbox($box->getName(),\Horde_Imap_Client::OPEN_READONLY);$generation=(string)$client->status($box->getName(),\Horde_Imap_Client::STATUS_UIDVALIDITY)['uidvalidity'];$token=base64_encode('V'.$generation);$box->setSyncNewToken($token);$box->setSyncChangedToken($token);$box->setSyncVanishedToken($token);$boxes->update($box);
            $make=static function(int $uidNumber,array $selected)use($box,$generation):object{$m=new \OCA\Mail\Db\Message();$m->setUid($uidNumber);$m->setMailboxId($box->getId());$m->setMessageId('<c5-duplicate@example.test>');$m->setSubject('Exact cache');$m->setSentAt(time());foreach(['Answered','Deleted','Draft','Flagged','Seen','Forwarded','Junk','Notjunk','Important','Mdnsent']as$f){$method='setFlag'.$f;$m->$method(false);}$m->setTags($selected);$m->setTagSnapshotGeneration($generation);return $m;};
            $q=$db->getQueryBuilder();$q->insert('mail_message_tags')->setValue('imap_message_id',$q->createNamedParameter('<c5-duplicate@example.test>'))->setValue('tag_id',$q->createNamedParameter($tag->getId()))->executeStatement();$legacyId=$q->getLastInsertId();
            $v=$tags->captureTagSnapshot($account,$box->getId(),$generation);$a=$make(9,[$tag]);$b=$make(10,[]);$a->setTagSnapshotVersion($v);$b->setTagSnapshotVersion($v);$mapper->insertBulk($account,$a,$b);
            $read=$mapper->findRelatedData([$a,$b],$uid);self::assertCount(1,$read[0]->getTags());self::assertSame([],$read[1]->getTags());self::assertTrue($read[0]->jsonSerialize()['tagsVerified']);
            $freshA=$make(9,[]);$freshB=$make(10,[$tag]);self::assertNull($freshA->getId());$v=$tags->captureTagSnapshot($account,$box->getId(),$generation);$freshA->setTagSnapshotVersion($v);$freshB->setTagSnapshotVersion($v);$mapper->updateBulk($account,true,$freshA,$freshB);
            self::assertSame($a->getId(),$freshA->getId());self::assertSame($b->getId(),$freshB->getId());$read=$mapper->findByIds($uid,[$a->getId(),$b->getId()],'ASC','uid');self::assertSame([],$read[0]->getTags());self::assertCount(1,$read[1]->getTags());
            $old=$make(10,[]);$old->setTagSnapshotVersion($tags->captureTagSnapshot($account,$box->getId(),$generation));$tags->tagMessage($tag,$freshB,$uid);$refused=false;try{$mapper->updateBulk($account,true,$old);}catch(\Throwable){$refused=true;}self::assertTrue($refused);self::assertTrue($tags->exactAssociations()->has($uid,$b->getId(),$tag->getId()));
            self::assertSame([$b->getId()],$tags->getTaggedMessageIdsForMessages($read,$uid,$tag->getImapLabel()));
            $query=new \OCA\Mail\Service\Search\SearchQuery();$query->setThreaded(false);$query->setTags([(string)$tag->getId()]);self::assertSame([$b->getId()],$mapper->findIdsByQuery($box,$query,'ASC',100));
            // Native related-data, classifier lookup, UI helpers and FK cleanup all see exact cached IDs.
            $links=Server::get('OCA\\Mail\\Db\\MessageTagsMapper')->getMessagesByTag($tag->getId(),$accountId,$uid);self::assertCount(1,$links);self::assertSame($b->getId(),$links[0]->getCachedMessageId());
            $mapper->deleteTagSnapshot($box,$tags->captureTagSnapshot($account,$box->getId(),$generation),10);self::assertSame([],$tags->getAllTagsForMessages([$b],$uid));
            $q=$db->getQueryBuilder();$r=$q->select('cached_message_id')->from('mail_message_tags')->where($q->expr()->eq('id',$q->createNamedParameter($legacyId)))->executeQuery();try{self::assertNull($r->fetchOne());}finally{$r->closeCursor();}
        } finally {
            $client->logout();$manager->deleteMailbox($account,$box);$tags->delete($tag);
            if($legacyId!==null){$q=$db->getQueryBuilder();$q->delete('mail_message_tags')->where($q->expr()->eq('id',$q->createNamedParameter($legacyId)))->executeStatement();}
        }
    }
}
