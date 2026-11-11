<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\OAuth\{ConnectionContext,TokenHasher,OAuthStore,TokenService};
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IDBConnection,IConfig};
/** Actual FPM HTTP and real Files/S3 creation; never weakens FileCreationLockGuard's CLI refusal. */
final class MailContentHttpActor {
    public static function run(string $uid,int $message,string $mode):array {
        $tools=$mode==='service_http_thread'?['mail_read_thread']:($mode==='service_http_attachment_only'?['mail_read_attachment','mail_save_attachment','files_create']:['mail_export_message','mail_save_attachment','mail_read_attachment','mail_get_operation','files_create']);$config=Server::get(IConfig::class);$policy=Server::get(GrantPolicy::class);$db=Server::get(IDBConnection::class);
        $snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE,'mail_read_message','mail_read_thread','mail_export_message'],$tools));$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__missing__');$legacy=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY,'grant_files_create'] as $key)$legacy[$key]=$config->getUserValue($uid,'mcp',$key,'__missing__');
        $connection=null;$file=null;$path='/mcp-content-'.bin2hex(random_bytes(8)).($mode==='service_http_raw'?'.eml':'.bin');
        try {
            $policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$policy->setGrant($uid,'files','create',true);
            $mapper=Server::get(ToolGrantsMapper::class);Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($mapper,$uid,$tools):bool {foreach(array_merge([ToolGrantsMapper::SERVICE],$tools) as $tool)$mapper->set(ToolGrantsMapper::USER,$uid,$tool,'allow');return true;});
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];$client=new McpClient($credential);
            if($mode==='service_http_attachment_only'){
                Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($mapper,$uid):bool {foreach(['mail_read_message','mail_read_thread','mail_export_message'] as $denied)$mapper->set(ToolGrantsMapper::USER,$uid,$denied,'deny');return true;});
                $store=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$selection=$store->get($uid,$connection);$store->save($uid,$connection,$tools,$selection['revision']);
                $messageDenied=!$client->tool('mail_read_message',['message_id'=>$message])->ok();$exportDenied=!$client->tool('mail_export_message',['message_id'=>$message,'path'=>$path])->ok();
                $bodyReadRefused=!$client->tool('mail_read_attachment',['message_id'=>$message,'attachment_id'=>'1'])->ok();$guessArgs=['message_id'=>$message,'attachment_id'=>'1','path'=>$path.'.body'];$guess=$client->tool('mail_save_attachment',$guessArgs);$bodySaveRefused=!$guess->ok();
                $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$bodyCreated=false;
                try{if($guess->ok()){$g=self::data($guess);$client->tool('mail_save_attachment',$guessArgs+['confirm'=>true,'plan_state'=>$g['plan_state'],'operation_id'=>$g['operation_id']]);}$bodyCreated=$root->nodeExists(ltrim($guessArgs['path'],'/'));}finally{if($root->nodeExists(ltrim($guessArgs['path'],'/')))$root->get(ltrim($guessArgs['path'],'/'))->delete();}
                $attachment=self::data($client->tool('mail_read_attachment',['message_id'=>$message,'attachment_id'=>'2']));$args=['message_id'=>$message,'attachment_id'=>'2','path'=>$path];$plan=self::data($client->tool('mail_save_attachment',$args));$saved=self::data($client->tool('mail_save_attachment',$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]));$file=$root->get(ltrim($path,'/'));
                return ['body_read_refused'=>$bodyReadRefused,'body_save_refused'=>$bodySaveRefused,'body_file_absent'=>!$bodyCreated,'message_denied'=>$messageDenied,'export_denied'=>$exportDenied,'attachment_read'=>base64_decode($attachment['content_base64'],true)==='aaa','attachment_saved'=>($saved['operation']['state']??null)==='succeeded'&&hash_equals(hash('sha256','aaa'),hash('sha256',$file->getContent()))];
            }
            if($mode==='service_http_slow_line'){$started=hrtime(true);$reply=$client->tool('mail_export_message',['message_id'=>$message,'path'=>$path]);return ['refused'=>$reply->status===200&&$reply->isError(),'elapsed'=>(hrtime(true)-$started)/1000000000,'private'=>!str_contains($reply->raw,'xxxxx')];}
            if($mode==='service_http_thread'){
                Server::get(PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static fn():bool=>$mapper->set(ToolGrantsMapper::USER,$uid,'mail_read_message','deny')===null);
                $store=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$selection=$store->get($uid,$connection);$store->save($uid,$connection,['mail_read_thread'],$selection['revision']);
                $messageDenied=!$client->tool('mail_read_message',['message_id'=>$message])->ok();$thread=self::data($client->tool('mail_read_thread',['message_id'=>$message,'max_chars'=>2]));$row=$thread['messages'][0]??[];
                return ['accepted'=>($row['body']['text']??null)==='te','message_denied'=>$messageDenied,'truncated'=>($row['body']['truncated']??false)===true,'total_chars'=>$row['body']['totalChars']??null,'attachments'=>$row['attachmentCount']??null];
            }
            $tool=$mode==='service_http_raw'?'mail_export_message':'mail_save_attachment';$args=['message_id'=>$message,'path'=>$path];if($tool==='mail_save_attachment')$args['attachment_id']='2';
            $smallRefused=$tool==='mail_save_attachment'?!$client->tool('mail_read_attachment',['message_id'=>$message,'attachment_id'=>'2'])->ok():true;
            $plan=self::data($client->tool($tool,$args));$confirmed=$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']];$result=self::data($client->tool($tool,$confirmed));
            $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$file=$root->get(ltrim($path,'/'));$bytes=$file->getContent();$expected=$tool==='mail_export_message'?"Content-Type: application/pkcs7-mime\r\n\r\nOpaque synthetic bytes\0\xff":str_repeat('a',26214400);
            $again=self::data($client->tool($tool,$confirmed));$status=self::data($client->tool('mail_get_operation',['operation_id'=>$plan['operation_id']]));
            $private=json_encode([$plan,$result,$again,$status],JSON_THROW_ON_ERROR);$exact=hash_equals(hash('sha256',$expected),hash('sha256',$bytes));unset($bytes,$expected);
            $store=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$selection=$store->get($uid,$connection);$store->save($uid,$connection,array_values(array_diff($selection['tools'],[$tool])),$selection['revision']);
            $revokedRead=!$client->tool('mail_get_operation',['operation_id'=>$plan['operation_id']])->ok();$revokedReplay=!$client->tool($tool,$confirmed)->ok();
            return ['accepted'=>($result['operation']['state']??null)==='succeeded','integrity'=>$exact,'replayed'=>$again['operation']['replayed']??false,'status'=>$status['operation']['state']??null,'same_file'=>($again['operation']['resource_refs']['file_id']??null)===$file->getId(),'private'=>!str_contains($private,'Opaque synthetic bytes')&&!str_contains($private,'content_base64'),'small_refused'=>$smallRefused,'revoked_status'=>$revokedRead,'revoked_replay'=>$revokedReplay];
        }finally {
            if($file!==null)$file->delete();
            if($connection!==null){$q=$db->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',$uid."\0".$connection))))->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            Server::get(ConnectionContext::class)->clear();ToolPermissionFixture::restore($snapshot);
            if($global==='__missing__')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);
            foreach($legacy as $key=>$value){if($value==='__missing__')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}Server::get(\OCP\Config\IUserConfig::class)->clearCache($uid,false);
        }
    }
    private static function data(ToolReply $reply):array {if(!$reply->ok())throw new \RuntimeException('Synthetic HTTP content call refused: '.$reply->describe());return $reply->structured()!==[]?$reply->structured():$reply->json();}
}
