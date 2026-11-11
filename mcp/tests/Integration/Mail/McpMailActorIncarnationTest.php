<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IUserManager,IConfig,IDBConnection};
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailActorIncarnationTest extends IntegrationTestCase {
    private function data(ToolReply $reply):array {self::assertTrue($reply->ok(),'Synthetic actor operation must be admitted before its deletion.');return $reply->structured()!==[]?$reply->structured():$reply->json();}
    private function allow(string $uid):void {
        $policy=Server::get(GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);
        ToolPermissionFixture::assign($uid,[ToolGrantsMapper::SERVICE=>'allow','mail_create_tag'=>'allow','mail_get_operation'=>'allow','mail_list_operations'=>'allow']);
    }
    public function testRecreatedBasicUidCannotReadOrReplayPreviousActorsMailOperation():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$config=Server::get(IConfig::class);$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__missing__');
        try{
            self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));$this->allow($uid);$client=new McpClient(LoginFactory::basic($uid));
            $args=['name'=>'Synthetic old owner label','color'=>'#123456'];$plan=$this->data($client->tool('mail_create_tag',$args));$confirmed=$args+['confirm'=>true,'operation_id'=>$plan['operation_id'],'plan_state'=>$plan['plan_state']];
            $done=$this->data($client->tool('mail_create_tag',$confirmed));self::assertSame('succeeded',$done['operation']['state']);self::assertTrue($client->tool('mail_get_operation',['operation_id'=>$plan['operation_id']])->ok());
            self::assertTrue($users->get($uid)->delete());self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));$this->allow($uid);$replacement=new McpClient(LoginFactory::basic($uid));
            $status=$replacement->tool('mail_get_operation',['operation_id'=>$plan['operation_id']]);$replay=$replacement->tool('mail_create_tag',$confirmed);
            self::assertFalse($status->ok(),'A recreated Basic UID inherited the former actor receipt.');self::assertFalse($replay->ok(),'A recreated Basic UID inherited the former actor replay.');
        }finally{
            $users->get($uid)?->delete();$db=Server::get(IDBConnection::class);
            foreach(['mcp_operations'=>'actor_uid','mail_tags'=>'user_id'] as $table=>$column){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq($column,$q->createNamedParameter($uid)))->executeStatement();}
            if($global==='__missing__')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);
        }
    }
}
