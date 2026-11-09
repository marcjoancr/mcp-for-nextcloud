<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, ToolPermissionFixture, ToolReply};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Actual native/HTTP D+ acceptance; no credentials cross MCP and sending stays disabled. */
#[Group('mail')]
final class McpMailDPlusTest extends IntegrationTestCase {
    private const TOOLS = [
        'mail_add_alias','mail_update_alias','mail_delete_alias','mail_list_aliases',
        'mail_reconcile_operation','mail_get_operation','mail_list_operations',
        'mail_write_draft','mail_delete_draft','mail_read_message',
        'mail_configure_connection','mail_test_connection','mail_read_connection','mail_complete_account_setup',
    ];
    private array $toolSnapshot = [];
    private array $settings = [];
    private string $serviceBefore = '__missing__';
    private McpClient $alice;
    private McpClient $bob;

    protected function setUp(): void {
        parent::setUp();
        if (getenv('MCP_IT_WITH_MAIL') !== '1') { self::markTestSkipped('Mail cell only'); }
        $config = Server::get(IConfig::class);
        $this->serviceBefore = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__missing__');
        $this->toolSnapshot = ToolPermissionFixture::capture([MailActor::ALICE, MailActor::BOB], self::TOOLS);
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        foreach ([MailActor::ALICE, MailActor::BOB] as $uid) {
            foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY] as $key) {
                $this->settings[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__missing__');
            }
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
        }
        $this->alice = new McpClient(LoginFactory::basic(MailActor::BOB));
        $this->bob = new McpClient(LoginFactory::basic(MailActor::ALICE));
    }

    protected function tearDown(): void {
        try {
            if ($this->toolSnapshot !== []) {
                ToolPermissionFixture::restore($this->toolSnapshot);
                $config = Server::get(IConfig::class);
                foreach ($this->settings as $uid => $values) {
                    foreach ($values as $key => $value) {
                        if ($value === '__missing__') { $config->deleteUserValue($uid, 'mcp', $key); }
                        else { $config->setUserValue($uid, 'mcp', $key, $value); }
                    }
                }
                if ($this->serviceBefore === '__missing__') { $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY); }
                else { $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->serviceBefore); }
            }
        } finally { parent::tearDown(); }
    }



    public function testAliasCrudCommitLossReconciliationAndHeldReferencesNeverSend():void {
        $uid=MailActor::BOB;$account=MailActor::run($uid,'accounts')['accounts'][0]['id'];
        $fixture=MailActor::run($uid,'c3-fixture',['account_id'=>$account]);
        $aliases=Server::get('OCA\\Mail\\Service\\AliasesService');$aliasId=$draftId=null;$faults=[];
        $email='plus-'.bin2hex(random_bytes(6)).'@example.test';$safe=MailActor::run($uid,'c1-safety');
        try {
            $created=$this->write('mail_add_alias',['account_id'=>$account,'name'=>'D+ alias','email'=>$email]);
            $aliasId=$created['identity']['alias_id'];self::assertSame($aliasId,$created['operation']['resource_refs']['alias_id']);
            $native=$aliases->find($aliasId,$uid);$native->setSignature('D+ native signature');$native->setSignatureMode(1);
            Server::get('OCA\\Mail\\Db\\AliasMapper')->update($native);
            $preserved=[$native->getSignature(),$native->getSignatureMode(),$native->getSmimeCertificateId(),$native->getAccountId()];
            $updated=$this->write('mail_update_alias',['account_id'=>$account,'alias_id'=>$aliasId,'changes'=>['name'=>'D+ renamed']]);
            $native=$aliases->find($aliasId,$uid);
            self::assertSame($preserved,[$native->getSignature(),$native->getSignatureMode(),$native->getSmimeCertificateId(),$native->getAccountId()]);
            self::assertSame('D+ renamed',$updated['identity']['name']);
            self::assertFalse($this->bob->tool('mail_update_alias',['account_id'=>$account,'alias_id'=>$aliasId,'changes'=>['name'=>'Foreign']])->ok());
            self::assertFalse($this->alice->tool('mail_update_alias',['account_id'=>$account,'alias_id'=>$aliasId,'changes'=>['smime_certificate_id'=>null]])->ok());

            // The same committed native effect and checkpoint exist when the parent loses final completion.
            $lost=$updated['operation']['operation_id'];$faults[]=$this->loseParentReceipt($uid,$lost,$account,false);
            $receipt=$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$lost]));self::assertSame('uncertain',$receipt['operation']['state']);
            self::assertFalse($this->alice->tool('mail_add_alias',['account_id'=>$account,'name'=>'Blocked','email'=>'blocked@example.test'])->ok());
            self::assertFalse($this->bob->tool('mail_reconcile_operation',['account_id'=>$account,'target_operation_id'=>$lost])->ok());
            $r=$this->write('mail_reconcile_operation',['account_id'=>$account,'target_operation_id'=>$lost]);
            self::assertSame('succeeded',$r['recorded_state']);self::assertFalse($r['native_write_repeated']);
            self::assertSame('succeeded',$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$lost]))['operation']['state']);
            $before=[$aliases->find($aliasId,$uid)->getName(),$aliases->find($aliasId,$uid)->getAlias()];
            $this->replay($r);self::assertSame($before,[$aliases->find($aliasId,$uid)->getName(),$aliases->find($aliasId,$uid)->getAlias()]);
            ToolPermissionFixture::assign($uid,['mail_update_alias'=>'deny']);
            try{self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$r['operation']['operation_id']])->ok());self::assertFalse($this->alice->tool('mail_reconcile_operation',$r['_confirmation'])->ok());}
            finally{ToolPermissionFixture::assign($uid,['mail_update_alias'=>'allow']);}

            $draft=$this->write('mail_write_draft',['account_id'=>$account,'alias_id'=>$aliasId,'reply_to_message_id'=>$fixture['messages'][0],'reply_mode'=>'reply','subject'=>'D+ held','body_plain'=>'Keep this reply local.']);
            $draftId=$draft['draft']['id'];self::assertTrue($draft['draft']['sync_held']);
            self::assertFalse($this->alice->tool('mail_delete_alias',['account_id'=>$account,'alias_id'=>$aliasId])->ok());
            self::assertTrue(Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage($draftId,$uid)->isFailed());
            $this->write('mail_delete_draft',['draft_id'=>$draftId]);$draftId=null;

            $withoutProof=$this->write('mail_update_alias',['account_id'=>$account,'alias_id'=>$aliasId,'changes'=>['name'=>'No-proof fixture']]);
            $unknown=$withoutProof['operation']['operation_id'];$faults[]=$this->loseParentReceipt($uid,$unknown,$account,true);
            self::assertFalse($this->alice->tool('mail_reconcile_operation',['account_id'=>$account,'target_operation_id'=>$unknown])->ok());
            self::assertSame('uncertain',$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$unknown]))['operation']['state']);
            // Restore only the exact successful fixture receipt deliberately faulted above, never a product unlock.
            $this->restoreFaults($uid,$faults);$faults=[];
            $deleted=$this->write('mail_delete_alias',['account_id'=>$account,'alias_id'=>$aliasId]);
            self::assertSame($aliasId,$deleted['deleted_alias_id']);$aliasId=null;$this->replay($deleted);
            self::assertSame($safe,MailActor::run($uid,'c1-safety'));
            self::assertSame('disabled',Server::get(\OCA\Mcp\Modules\Mail\Integration\MailCapabilities::class)->forUser($uid)['capabilities']['send']['status']);
        } finally {
            $this->restoreFaults($uid,$faults);
            if($draftId!==null)$this->write('mail_delete_draft',['draft_id'=>$draftId]);
            if($aliasId!==null){$a=$aliases->find($aliasId,$uid);if($a->getAlias()!==$email)throw new \LogicException('Not this alias fixture');$aliases->delete($uid,$aliasId);}
            MailActor::run($uid,'c3-cleanup',['fixture'=>$fixture]);
        }
    }

    public function testWorkflowLinkedAuthenticationDoesNotAttestToCredentialChangesOrCompleteSetup():void {
        $uid=MailActor::BOB;$email='d-plus-'.bin2hex(random_bytes(6)).'@example.test';$id=null;
        try {
            $id=MailActor::run($uid,'native-setup-form',['email'=>$email])['account_id'];
            $begin=$this->write('mail_configure_connection',['account_id'=>$id,'intent'=>[]]);$setup=$begin['operation']['operation_id'];
            $args=['account_id'=>$id,'protocols'=>['imap','smtp'],'setup_operation_id'=>$setup,'intent'=>[]];
            $probe=$this->write('mail_test_connection',$args);
            self::assertSame('authentication_observed',$probe['authentication_observation']['status']);
            self::assertSame('unverified',$probe['authentication_observation']['credential_change']);self::assertFalse($probe['authentication_observation']['setup_completed']);
            self::assertSame('awaiting_user',$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$setup]))['operation']['state']);
            self::assertFalse($this->alice->tool('mail_complete_account_setup',['account_id'=>$id,'setup_operation_id'=>$setup,'intent'=>[]])->ok());
            $capture=MailActor::run($uid,'probe-capture');$this->replay($probe);
            $this->data($this->alice->tool('mail_get_operation',['operation_id'=>$probe['operation']['operation_id']]));
            self::assertSame($capture,MailActor::run($uid,'probe-capture'),'Replay/status must not authenticate again');
            foreach(['MAIL','RCPT','DATA','BDAT'] as $command)self::assertNotContains($command,$capture['smtp']);
            foreach(['SELECT','STORE','APPEND','DELETE','EXPUNGE'] as $command)self::assertNotContains($command,$capture['imap']);
            foreach(['mail_configure_connection','mail_read_connection'] as $tool){
                ToolPermissionFixture::assign($uid,[$tool=>'deny']);
                try{self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$probe['operation']['operation_id']])->ok());self::assertFalse($this->alice->tool('mail_test_connection',$probe['_confirmation'])->ok());}
                finally{ToolPermissionFixture::assign($uid,[$tool=>'allow']);}
            }
            self::assertSame($capture,MailActor::run($uid,'probe-capture'));
            self::assertFalse($this->alice->tool('mail_test_connection',array_replace($args,['intent'=>['name'=>'Other']]))->ok());
            // Submitting the same old credentials through the native form cannot prove a password change.
            MailActor::run($uid,'native-setup-form',['email'=>$email,'account_id'=>$id]);
            $oldCredentials=$this->write('mail_test_connection',$args);self::assertSame('authentication_observed',$oldCredentials['authentication_observation']['status']);
            self::assertSame('unverified',$oldCredentials['authentication_observation']['credential_change']);
            self::assertFalse($this->alice->tool('mail_complete_account_setup',['account_id'=>$id,'setup_operation_id'=>$setup,'intent'=>[]])->ok());
        }finally{if($id!==null)MailActor::run($uid,'native-remove-fixture',['account_id'=>$id]);}
    }

    private function write(string $tool,array $args):array {
        $p=$this->data($this->alice->tool($tool,$args));self::assertTrue($p['requiresConfirmation'],$tool);
        $confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
        $r=$this->data($this->alice->tool($tool,$confirmed));self::assertSame($tool==='mail_configure_connection'?'awaiting_user':'succeeded',$r['operation']['state'],json_encode($r));
        return $r+['_tool'=>$tool,'_confirmation'=>$confirmed];
    }
    private function replay(array $result):void {
        $r=$this->data($this->alice->tool($result['_tool'],$result['_confirmation']));self::assertTrue($r['operation']['replayed']);
    }
    private function loseParentReceipt(string $uid,string $id,int $account,bool $removeProof):array {
        $db=Server::get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$r=$q->select('*')->from('mcp_operations')
            ->where($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))->executeQuery();
        try{$row=$r->fetch();}finally{$r->closeCursor();}
        self::assertIsArray($row);self::assertSame('succeeded',$row['state']);$refs=json_decode($row['resource_refs'],true,8,JSON_THROW_ON_ERROR);
        self::assertTrue($refs['local_completed']);self::assertSame($account,$refs['account_id']);
        if($removeProof)unset($refs['local_completed']);
        $q=$db->getQueryBuilder();self::assertSame(1,$q->update('mcp_operations')->set('state',$q->createNamedParameter('uncertain'))
            ->set('target_key',$q->createNamedParameter(hash('sha256',$uid."\0account:".$account)))
            ->set('resource_refs',$q->createNamedParameter(json_encode($refs,JSON_THROW_ON_ERROR)))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($row['scope_key'])))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement());
        return $row;
    }
    private function restoreFaults(string $uid,array $rows):void {
        foreach($rows as $row){if($row['actor_uid']!==$uid||$row['state']!=='succeeded')throw new \LogicException('Not an owned successful test receipt');
            $q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();$q->update('mcp_operations')->set('state',$q->createNamedParameter($row['state']))
                ->set('target_key',$q->createNamedParameter($row['target_key'],$row['target_key']===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR))
                ->set('resource_refs',$q->createNamedParameter($row['resource_refs']))->set('updated_at',$q->createNamedParameter((int)$row['updated_at'],IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('scope_key',$q->createNamedParameter($row['scope_key'])))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($row['operation_id'])))
                ->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();
        }
    }
    private function data(ToolReply $reply):array{self::assertTrue($reply->ok(),$reply->describe());return $reply->structured()!==[]?$reply->structured():$reply->json();}
}
