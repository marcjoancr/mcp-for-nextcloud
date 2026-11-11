<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\{ResourceScopeWriter,ScopeActivation};
use OCA\Mcp\Db\{ToolGrantsMapper,ResourceScopeMapper,PolicyStateMapper};
use OCA\Mcp\OAuth\{ConnectionToolsStore,TokenService,TokenHasher,OAuthStore};
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IConfig,IDBConnection};
/** Final clean-runtime proof: ordinary OAuth HTTP, shipping services and native signed children only. */
final class MailCleanHttpActor {
    public static function assertClean():void {
        if(getenv('MCP_IT_CLEAN_MAIL')!=='1')throw new \RuntimeException('Explicit clean Mail acceptance required.');
        $expected=getenv('MCP_IT_SOURCE_COMMIT');$path=Server::get(\OCP\App\IAppManager::class)->getAppPath('mcp');
        $identity=json_decode((string)file_get_contents($path.'/appinfo/source.json'),true,8,JSON_THROW_ON_ERROR);
        if(!is_string($expected)||!preg_match('/^[a-f0-9]{40}$/D',$expected)||($identity['sourceCommit']??null)!==$expected)throw new \RuntimeException('Exact clean source identity required.');
        $boundary=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);
        if(get_class($boundary)!==\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class||!$boundary->deliveryVerified()||!$boundary->snoozeVerified()||!$boundary->sieveVerified()||!$boundary->signatureModeVerified()||!Server::get(ScopeActivation::class)->isActive('mail'))throw new \RuntimeException('Shipping qualification and scope barriers must be open.');
    }
    private static function data(ToolReply $reply):array {
        if(!$reply->ok())throw new \RuntimeException('Clean HTTP tool refused.');
        return $reply->structured()!==[]?$reply->structured():$reply->json();
    }
    private static function write(McpClient $client,string $tool,array $args):array {
        $plan=self::data($client->tool($tool,$args));
        $confirmed=$args+['confirm'=>true,'operation_id'=>$plan['operation_id'],'plan_state'=>$plan['plan_state']];
        $result=self::data($client->tool($tool,$confirmed));
        $replay=self::data($client->tool($tool,$confirmed));
        if(($result['operation']['state']??null)!=='succeeded'||!($replay['operation']['replayed']??false))throw new \RuntimeException('Clean HTTP durable replay failed.');
        return $result;
    }
    private static function scope(string $uid,?array $items):void {
        Server::get(ResourceScopeWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'],'admin',$uid,'mail',$items);
    }
    private static function within(string $uid,int $account,array $tools,\Closure $work):array {
        self::assertClean();$config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);$policy=Server::get(GrantPolicy::class);
        $snapshot=ToolPermissionFixture::capture([$uid],array_merge([ToolGrantsMapper::SERVICE],$tools));$oldScope=Server::get(ResourceScopeMapper::class)->forModule($uid,'mail');
        $global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__absent');$old=[];$connection=null;$native=[];
        foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$old[$key]=$config->getUserValue($uid,'mcp',$key,'__absent');
        try {
            $policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);ToolPermissionFixture::assign($uid,array_fill_keys(array_merge([ToolGrantsMapper::SERVICE],$tools),'allow'));
            self::scope($uid,['account:'.$account]);
            // Stock FPM must observe CLI fixture config writes before normal HTTP admission.
            sleep(3);
            $credential=LoginFactory::bearer($uid);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash(substr($credential->authorization(),7)));$connection=(int)$row['id'];
            $store=Server::get(ConnectionToolsStore::class);$prior=$store->get($uid,$connection);$store->save($uid,$connection,$tools,$prior['revision']);
            return $work(new McpClient($credential),$connection,$native);
        } finally {
            foreach($native as[$table,$id]){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}
            if($connection!==null){foreach(['mcp_operation_origins','mcp_operations']as$table){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',$uid."\0".$connection))))->executeStatement();}Server::get(TokenService::class)->revokeGrant($connection,$uid);}
            self::scope($uid,$oldScope['items']??null);ToolPermissionFixture::restore($snapshot);
            if($global==='__absent')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);
            foreach($old as$key=>$value){if($value==='__absent')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}
        }
    }
    public static function delivery(string $uid,int $draft,int $account):array {
        return self::within($uid,$account,['mail_send','mail_read_delivery','mail_get_operation'],function(McpClient $client,int $connection,array &$native)use($uid,$draft,$account):array {
            $r=self::write($client,'mail_send',['draft_id'=>$draft]);$id=$r['delivery']['id'];$native[]=['mail_mcp_deliveries',$id];
            $child=Server::get(\OCA\Mcp\Modules\Mail\Transmission\DeliveryRunner::class)->run(['uid'=>$uid,'id'=>$id,'accountId'=>$account,'connectionId'=>$connection,'sourceTool'=>'mail_send']);
            $status=self::data($client->tool('mail_read_delivery',['delivery_id'=>$id]));
            self::scope($uid,[]);$denied=!$client->tool('mail_read_delivery',['delivery_id'=>$id])->ok();
            return ['http_replayed'=>true,'child'=>$child['status']??null,'state'=>$status['delivery']['state']??null,'sent'=>$status['delivery']['sentState']??null,'scope_denied'=>$denied];
        });
    }
    public static function snooze(string $uid,array $fixture):array {
        return self::within($uid,$fixture['account_id'],['mail_snooze','mail_wake','mail_cancel_snooze','mail_read_snooze','mail_get_operation'],function(McpClient $client,int $connection,array &$native)use($uid,$fixture):array {
            $r=self::write($client,'mail_snooze',['message_id'=>$fixture['messages'][0],'wake_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'timezone'=>'UTC']);$id=$r['snooze']['id'];$native[]=['mail_mcp_snoozes',$id];
            $placed=self::data($client->tool('mail_read_snooze',['snooze_id'=>$id]));
            MailNativeMoveActor::inspect($uid,$fixture,$fixture['boxes']['destination'],true);
            $woken=self::write($client,'mail_wake',['snooze_id'=>$id]);
            self::scope($uid,[]);$denied=!$client->tool('mail_read_snooze',['snooze_id'=>$id])->ok();
            return ['http_replayed'=>true,'placed'=>$placed['snooze']['state']??null,'woken'=>$woken['snooze']['state']??null,'scope_denied'=>$denied];
        });
    }
    public static function sieve(string $uid,int $account):array {
        return self::within($uid,$account,['mail_write_filters','mail_read_filters','mail_read_sieve_status','mail_transmit_filters','mail_get_operation'],function(McpClient $client,int $connection,array &$native)use($uid,$account):array {
            $args=['account_id'=>$account,'filters'=>MailSieveActor::filter()];$r=self::write($client,'mail_write_filters',$args);$id=$r['sieve']['id'];$native[]=['mail_mcp_sieve',$id];
            $read=self::data($client->tool('mail_read_filters',['account_id'=>$account]));$status=self::data($client->tool('mail_read_sieve_status',['sieve_id'=>$id]));
            $off=!$client->tool('mail_transmit_filters',$args)->ok();self::scope($uid,[]);$denied=!$client->tool('mail_read_sieve_status',['sieve_id'=>$id])->ok();
            return ['http_replayed'=>true,'state'=>$r['sieve']['state']??null,'observation'=>$status['sieve']['observation']??null,'read_available'=>$read!==[],'emitting_denied'=>$off,'scope_denied'=>$denied];
        });
    }
    public static function signature(string $uid):array {
        $mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$account=$mapper->findByUserId($uid)[0];$id=(int)$account->getId();$before=MailSignatureModeActor::row('mail_accounts',$id);$mode=(int)$account->getSignatureMode();
        try{return self::within($uid,$id,['mail_write_signature_mode','mail_get_operation'],function(McpClient $client,int $connection,array &$native)use($uid,$id,$before,$mode):array {
            self::write($client,'mail_write_signature_mode',['account_id'=>$id,'signature_mode'=>1-$mode]);$after=MailSignatureModeActor::row('mail_accounts',$id);
            $changed=$after['signature_mode']==1-$mode;unset($before['signature_mode'],$after['signature_mode']);
            return ['http_replayed'=>true,'changed'=>$changed,'other_columns_preserved'=>$before===$after];
        });}finally{$fresh=$mapper->findById($id);$fresh->setSignatureMode($mode);$mapper->update($fresh);}
    }
}
