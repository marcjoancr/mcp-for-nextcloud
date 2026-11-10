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

/** Real HTTP tools, native Mail drafts, native Files writes and independent IMAP flag probes. */
#[Group('mail')]
final class McpMailSettingsTest extends IntegrationTestCase {
    private const TOOLS = ['mail_read_account_settings', 'mail_write_account_settings', 'mail_read_signature', 'mail_write_signature', 'mail_read_connection', 'mail_configure_connection', 'mail_test_connection', 'mail_add_account', 'mail_complete_account_setup', 'mail_delete_account', 'mail_get_operation', 'mail_list_operations'];
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
            foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_files_create'] as $key) {
                $this->settings[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__missing__');
            }
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            $policy->setGrant($uid, 'files', 'create', true);
            ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
        }
        $this->alice = new McpClient(LoginFactory::basic(MailActor::ALICE));
        $this->bob = new McpClient(LoginFactory::basic(MailActor::BOB));
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


    public function testNativePreferenceAndSignatureWritesPreserveAuthenticationAndOwnerIsolation(): void {
        $id=$this->account(MailActor::ALICE);$args=['account_id'=>$id];
        $before=MailActor::run(MailActor::ALICE,'native-settings-proof',$args);
        $settings=$this->data($this->alice->tool('mail_read_account_settings',$args));
        $signature=$this->data($this->alice->tool('mail_read_signature',$args));
        $alias=MailActor::run(MailActor::ALICE,'native-alias-fixture',$args);
        try {
            $aliasWritten=$this->write('mail_write_signature',$args+$alias+['signature'=>'Alias signature']);
            self::assertSame('Alias signature',$aliasWritten['signature']);
            $changed=$this->write('mail_write_account_settings',$args+['changes'=>['editor_mode'=>'plaintext','signature_above_quote'=>true,'show_subscribed_only'=>true]]);
            self::assertTrue($changed['settings']['signature_above_quote']);
            $written=$this->write('mail_write_signature',$args+['signature'=>'<p>D fixture signature</p>']);
            self::assertSame('<p>D fixture signature</p>',$written['signature']);
            $after=MailActor::run(MailActor::ALICE,'native-settings-proof',$args);
            self::assertSame($before['auth_digest'],$after['auth_digest']);self::assertSame($before['security'],$after['security']);
            $profile=$this->data($this->alice->tool('mail_read_connection',$args));
            self::assertSame(['host','port','tls','user'],array_keys($profile['imap']));
            self::assertStringNotContainsString('Password',json_encode($profile));
            $foreign=$this->bob->tool('mail_read_connection',$args);
            $missing=$this->bob->tool('mail_read_connection',['account_id'=>987654321]);
            self::assertTrue($foreign->isError());self::assertSame($foreign->fingerprint(),$missing->fingerprint());
        } finally {
            MailActor::run(MailActor::ALICE,'native-alias-fixture',$alias);
            $this->write('mail_write_account_settings',$args+['changes'=>$settings['settings']]);
            $this->write('mail_write_signature',$args+['signature'=>$signature['signature']]);
        }
    }

    public function testNativeAssistantBindingIndependentAuthenticationAndMeasuredRemovalNeverSend(): void {
        $email='d-'.bin2hex(random_bytes(5)).'@example.test';$intent=['email'=>$email,'imap'=>['host'=>'127.0.0.1','port'=>33493,'tls'=>'ssl','user'=>'fixture'],
            'smtp'=>['host'=>'127.0.0.1','port'=>33465,'tls'=>'ssl','user'=>'fixture'],'auth_method'=>'password'];
        $ids=[];$imap=new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'),(int)getenv('MCP_IT_IMAP_PORT'),'alice',(string)getenv('MCP_IT_IMAP_PASSWORD'));
        $remoteBefore=$imap->state();
        $root=Server::get(IRootFolder::class)->getUserFolder(MailActor::ALICE);
        $file=$root->newFile('/mcp-mail-d-retain-'.bin2hex(random_bytes(5)).'.txt','Original Files bytes');
        try {
            $begin=$this->write('mail_add_account',['intent'=>$intent]);$source=$begin['operation']['operation_id'];
            self::assertSame('awaiting_user',$begin['operation']['state']);
            if(Server::get(\OCA\Mcp\Modules\Mail\Integration\NativeSetupEvidence::class)->available()){
                parse_str((string)parse_url($begin['url'],PHP_URL_QUERY),$query);
                self::assertSame(['mcpSetup'],array_keys($query));self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$query['mcpSetup']);
                self::assertStringNotContainsString($email,$begin['url']);
            }else self::assertNull(parse_url($begin['url'],PHP_URL_QUERY));
            $native=MailActor::run(MailActor::ALICE,'native-setup-form',['email'=>$email]);$id=$native['account_id'];$ids[]=$id;
            // A separate native form can create another account; MCP never guesses which was intended.
            $duplicate=MailActor::run(MailActor::ALICE,'native-setup-form',['email'=>$email]);$ids[]=$duplicate['account_id'];
            self::assertNotSame($id,$duplicate['account_id']);
            $bound=$this->write('mail_complete_account_setup',['setup_operation_id'=>$source,'account_id'=>$id,'intent'=>$intent]);
            self::assertSame('observed_in_mail',$bound['native_state']);self::assertArrayNotHasKey('credential_write',$bound,'Independent legacy creation must not invent a manual credential proof.');
            $receipt=$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$source]));
            self::assertSame('succeeded',$receipt['operation']['state']);self::assertSame($id,$receipt['operation']['resource_refs']['account_id']);
            $probe=$this->write('mail_test_connection',['account_id'=>$id,'protocols'=>['imap','smtp']]);
            self::assertSame(['status'=>'ok','category'=>'authenticated'],$probe['protocols']['imap']);
            self::assertSame(['status'=>'ok','category'=>'authenticated'],$probe['protocols']['smtp']);
            self::assertFalse($probe['message_sent']);
            $capture=MailActor::run(MailActor::ALICE,'probe-capture');
            foreach(['MAIL','RCPT','DATA','BDAT'] as $command)self::assertNotContains($command,$capture['smtp']);
            foreach(['SELECT','STORE','APPEND','DELETE','EXPUNGE'] as $command)self::assertNotContains($command,$capture['imap']);
            $captureBeforeStatus=MailActor::run(MailActor::ALICE,'probe-capture');
            $this->data($this->alice->tool('mail_get_operation',['operation_id'=>$probe['operation']['operation_id']]));
            self::assertSame($captureBeforeStatus,MailActor::run(MailActor::ALICE,'probe-capture'));
            MailActor::run(MailActor::ALICE,'probe-fault',['mode'=>'reject']);
            $failed=$this->write('mail_test_connection',['account_id'=>$id,'protocols'=>['imap','smtp']]);
            self::assertSame('ok',$failed['protocols']['imap']['status']);self::assertSame('failed',$failed['protocols']['smtp']['status']);
            MailActor::run(MailActor::ALICE,'probe-fault',['mode'=>'delay']);$start=microtime(true);
            $timeout=$this->write('mail_test_connection',['account_id'=>$id,'protocols'=>['smtp']]);
            self::assertSame('timeout',$timeout['protocols']['smtp']['category']);self::assertLessThan(13,microtime(true)-$start);
            MailActor::run(MailActor::ALICE,'probe-fault',['mode'=>'normal']);
            $configuration=$this->write('mail_configure_connection',['account_id'=>$id,'intent'=>['name'=>'D renamed']]);
            MailActor::run(MailActor::ALICE,'native-setup-form',['email'=>$email,'account_id'=>$id]);
            // The observed profile still has the original name, so a mismatched requested change is refused.
            self::assertTrue($this->alice->tool('mail_complete_account_setup',['setup_operation_id'=>$configuration['operation']['operation_id'],'account_id'=>$id,'intent'=>['name'=>'D renamed']])->isError());
            $alias=MailActor::run(MailActor::ALICE,'native-alias-fixture',['account_id'=>$duplicate['account_id']]);
            $impact=$this->data($this->alice->tool('mail_delete_account',['account_id'=>$duplicate['account_id']]));
            self::assertSame(1,$impact['impact']['aliases_removed']);
            // Remove the independent account without a pending setup workflow.
            $removed=$this->write('mail_delete_account',['account_id'=>$duplicate['account_id']]);
            self::assertTrue($removed['removed']);self::assertSame($remoteBefore,$imap->state());
            self::assertSame('Original Files bytes',$file->getContent());
        } finally {
            $file->delete();
            MailActor::run(MailActor::ALICE,'probe-fault',['mode'=>'normal']);
            foreach($ids as $id){try{MailActor::run(MailActor::ALICE,'native-remove-fixture',['account_id'=>$id]);}catch(\Throwable){}}
        }
    }
    private function write(string $tool,array $args):array {
        $p=$this->data($this->alice->tool($tool,$args));
        $confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
        $result=$this->data($this->alice->tool($tool,$confirmed));
        $repeat=$this->data($this->alice->tool($tool,$confirmed));
        self::assertTrue($repeat['operation']['replayed']);
        if($tool==='mail_test_connection') {
            self::assertSame($result['protocols'],$repeat['operation']['protocols']);
            $status=$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']]));
            self::assertSame($result['protocols'],$status['operation']['protocols']);
        }
        self::assertSame($result['operation']['state'],$repeat['operation']['state']);
        return $result;
    }
    private function account(string $uid):int {
        $q=$this->official()->getQueryBuilder();$r=$q->select('id')->from('mail_accounts')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeQuery();
        try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }
    private function data(ToolReply $reply):array {
        self::assertTrue($reply->ok(),$reply->describe());
        return $reply->structured()!==[]?$reply->structured():$reply->json();
    }
}
