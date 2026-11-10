<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * One fixture user of the Mail integration test, in its own CLI request (see MailActor). Actions:
 * - accounts: the Mail accounts of the user, read natively through the Mail AccountService (fixture lookup);
 * - observe: the Mail tools listed for the user and the answers of mail_list_accounts and of mail_list_mailboxes for
 *   the own account, another user's account and an account that does not exist, through the real ToolRegistry.
 */

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Mail\MailActor;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/bootstrap.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
[, $uid, $action] = $argv;
if (!in_array($uid, [MailActor::ALICE, MailActor::BOB], true)) {
	throw new RuntimeException('Not a disposable Mail fixture actor');
}
Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
$apps = Server::get(IAppManager::class);
if (!$apps->isEnabledForAnyone('mail')) {
	throw new RuntimeException('Mail must be provisioned; this scenario never skips inside a Mail cell');
}
$apps->loadApp('mail');
$in = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

/**
 * Calls one tool through the registry, inside the policy operation of the user, as the endpoint does.
 *
 * @param ToolRegistry $registry registry with the Mail module
 * @param GrantPolicy $policy access policy
 * @param string $uid fixture user
 * @param string $tool tool name
 * @param array<string, mixed> $arguments tool arguments
 * @return array<string, mixed> the MCP result, or ['refused' => true] for a call the registry rejects
 */
function callTool(ToolRegistry $registry, GrantPolicy $policy, string $uid, string $tool, array $arguments): array {
	try {
		return $policy->within($uid, static fn (): array => $registry->call($tool, $arguments, $uid));
	} catch (InvalidArgumentException) {
		return ['refused' => true];
	}
}

switch ($action) {
    case 'credential-tabs-prepare':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::tabsPrepare($uid,$in['mode']);break;
    case 'credential-tabs-inspect':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::tabsInspect($uid,$in['fixture']);break;
    case 'credential-tabs-created':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::tabsCreated($uid,$in['fixture'],(int)$in['account_id']);break;
    case 'credential-ui-prepare':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::prepare($uid,$in['provider']??null);break;
    case 'credential-ui-finish':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::finish($uid,$in['fixture']);break;
    case 'credential-ui-gates':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::gates($uid,$in['fixture']);break;
    case 'credential-ui-state-count':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::stateCount($uid,$in['fixture']);break;
    case 'credential-ui-name-contender':
    case 'credential-ui-contender':
    case 'credential-ui-kill':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::contender($uid,$in['fixture'],$action==='credential-ui-kill',$action==='credential-ui-name-contender');break;
    case 'credential-ui-session-loss':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::sessionLoss($uid,$in['fixture']);break;
    case 'credential-ui-inspect':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::inspect($uid,$in['fixture']);break;
    case 'credential-ui-alternate':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::alternate($uid,$in['fixture']);break;
    case 'credential-ui-refresh':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::refresh($uid,$in['fixture']);break;
    case 'credential-ui-expire-state':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::expireState($uid,$in['fixture'],$in['state']);break;
    case 'credential-ui-guarantees':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::guarantees($uid,$in['fixture']);break;
    case 'credential-ui-cleanup':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailCredentialEvidenceActor::cleanup($uid,$in['fixture']);break;
    case 'draft-contender':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftConcurrencyActor::contender($uid,$in);break;
    case 'draft-coordinated':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftConcurrencyActor::coordinated($uid,$in['mode']);break;
    case 'draft-stale-conversion':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftConcurrencyActor::stale($uid,$in['mode']);break;
    case 'draft-ui-prepare':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftUiActor::prepare($uid,$in['format']??'plain');break;
    case 'draft-ui-normal':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftUiActor::normal($uid);break;
    case 'draft-ui-safety':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftUiActor::safety($uid,$in);break;
    case 'draft-ui-finish':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftUiActor::finish($uid,$in);break;
    case 'draft-ui-cleanup':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailDraftUiActor::cleanup($uid,$in);break;
    case 'c6-protocol':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeRefreshActor::protocol($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c6-lease':
    case 'c6-lease-hold':
        $owned=false;try{$lease=\OCA\Mail\Sync\MailboxLease::acquire(\OCP\Server::get(\OCP\IDBConnection::class),\OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix','oc_'),(int)$in['mailbox_id']);$owned=true;if($action==='c6-lease-hold'){echo "owned\n";flush();sleep(30);}$lease->release();}catch(\RuntimeException){}$out=['acquired'=>$owned];break;
    case 'c5-fault':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeTagActor::fault($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c5-protocol':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeTagActor::protocol($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c2-protocol':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailMailboxActor::protocol($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c2-fault':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailMailboxActor::fault($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c2-native':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailMailboxActor::native($uid,(int)$in['account_id']);break;
    case 'c3-role':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::role($uid,$in['fixture'],$in['role'],(int)$in['mailbox_id']);break;
    case 'c3-boundary':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::boundary($uid);break;
    case 'c3-fault':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::fault($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c3-service':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::service($uid,(int)$in['account_id']);break;
    case 'c3-fixture':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::fixture($uid,(int)$in['account_id']);break;
    case 'c3-cleanup':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::cleanup($uid,$in['fixture']);break;
    case 'c3-inspect':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::inspect($uid,$in['fixture'],(int)$in['mailbox_id'],$in['sync']??false);break;
    case 'c3-protocol':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMoveActor::protocol($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c1-journal-advance':
        $lease=$in['lease'];$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);
        $wire=$store->checkpointNative($lease['scope'],$lease['id'],$lease['token'],'prepared',['phase'=>'wire_may_start']);
        $proof=$store->checkpointNative($lease['scope'],$lease['id'],$lease['token'],'wire_may_start',['phase'=>'remote_verified','remote'=>['outcome'=>'verified','message_id'=>17,'mailbox_id'=>5,'generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true]]);
        $out=['wire'=>$wire,'proof'=>$proof];break;
    case 'c1-protocol-fault':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMutationActor::protocolFault($uid,(int)$in['account_id'],$in['mode']);break;
    case 'c1-cache-proof':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMutationActor::cacheProof($uid,(int)$in['message_id']);break;
    case 'c1-safety':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMutationActor::safety($uid);break;
    case 'c1-read-flag':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMutationActor::read($uid,(int)$in['message_id']);break;
    case 'c1-native-proof':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailNativeMutationActor::proof($uid,(int)$in['account_id']);break;
    case 'c0-selection-proof':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailLifecycleActor::selection($uid,(int)$in['account_id']);break;
    case 'c0-cache-proof':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailLifecycleActor::cache($uid,(int)$in['message_id']);break;
    case 'c0-draft-proof':
        $out=\OCA\Mcp\Tests\Integration\Mail\MailLifecycleActor::draft($uid,(int)$in['draft_id']);break;
    case 'probe-fault':
        if(!in_array($in['mode'],['normal','reject','delay'],true))throw new RuntimeException('Unsupported fixture mode');
        file_put_contents('/tmp/mcp-mail-probe-mode',$in['mode']);$out=['updated'=>true];break;
    case 'native-alias-fixture':
        $aliases=Server::get('OCA\\Mail\\Service\\AliasesService');
        if(isset($in['alias_id'])){$aliases->delete($uid,(int)$in['alias_id']);$out=['removed'=>true];}
        else {$alias=$aliases->create($uid,(int)$in['account_id'],'d-alias@example.test','D alias');$out=['alias_id'=>(int)$alias->getId()];}
        break;
    case 'native-settings-proof':
        $entity=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,(int)$in['account_id'])->getMailAccount();
        $out=['auth_digest'=>hash('sha256',serialize([$entity->getInboundPassword(),$entity->getOutboundPassword(),$entity->getOauthAccessToken(),$entity->getOauthRefreshToken()])),
            'security'=>[$entity->getInboundSslMode(),$entity->getOutboundSslMode(),$entity->getAuthMethod()],
            'signature'=>$entity->getSignature(),'editor'=>$entity->getEditorMode()];
        break;
    case 'native-setup-form':
        // Same native controller as AccountForm. Fixture credentials exist only here, never in any MCP argument.
        $controller=Server::get('OCA\\Mail\\Controller\\AccountsController');
        $args=['accountName'=>'D fixture','emailAddress'=>$in['email'],'imapHost'=>'127.0.0.1','imapPort'=>33493,'imapSslMode'=>'ssl','imapUser'=>'fixture',
            'smtpHost'=>'127.0.0.1','smtpPort'=>33465,'smtpSslMode'=>'ssl','smtpUser'=>'fixture',
            'imapPassword'=>'mcp-it-probe-password','smtpPassword'=>'mcp-it-probe-password','authMethod'=>'password'];
        try{
            $response=isset($in['account_id'])?$controller->update(...(['id'=>(int)$in['account_id']]+$args)):$controller->create(...$args);
        }catch(\Throwable $error){
            $trace=array_map(static fn(array $frame):array=>array_intersect_key($frame,array_flip(['file','line','class','function'])), $error->getTrace());
            throw new RuntimeException('Native setup safe trace '.json_encode(['class'=>get_class($error),'file'=>$error->getFile(),'line'=>$error->getLine(),'trace'=>$trace],JSON_THROW_ON_ERROR));
        }
        if(!in_array($response->getStatus(),[200,201],true))throw new RuntimeException('Native setup form failed with status '.$response->getStatus());
        $accounts=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid);
        $ids=[];foreach($accounts as $account)if($account->getEmail()===$in['email'])$ids[]=(int)$account->getId();
        if($ids===[])throw new RuntimeException('Native form did not create the fixture account');
        $out=['account_id'=>max($ids)];
        break;
    case 'native-remove-fixture':
        Server::get('OCA\\Mail\\Service\\AccountService')->delete($uid,(int)$in['account_id']);$out=['removed'=>true];break;
    case 'probe-capture':
        $out=[];foreach(['imap','smtp'] as $protocol)$out[$protocol]=is_file('/tmp/mcp-mail-probe-'.$protocol.'.log')?file('/tmp/mcp-mail-probe-'.$protocol.'.log',FILE_IGNORE_NEW_LINES):[];
        break;
    case 'draft-lifecycle':
        $service = Server::get('OCA\\Mail\\Service\\DraftsService');
        $draft = $service->getMessage((int)$in['draft_id'], $uid);
        $mapper = Server::get('OCA\\Mail\\Db\\LocalMessageMapper');
        $due = array_map(static fn(object $d): int => (int)$d->getId(), $mapper->findDueDrafts(time() + 600));
        $attachmentService = Server::get('OCA\\Mail\\Service\\Attachment\\AttachmentService');
        $bytes = [];
        foreach ($draft->getAttachments() as $a) { [, $file] = $attachmentService->getAttachment($uid, $a->getId()); $bytes[] = base64_encode($file->getContent()); }
        $out = ['in_reply_to' => $draft->getInReplyToMessageId(), 'due_for_sync' => in_array((int)$draft->getId(), $due, true), 'attachment_bytes' => $bytes];
        break;
    case 'native-draft-update':
        $draftService = Server::get('OCA\\Mail\\Service\\DraftsService');
        $draft = $draftService->getMessage((int)$in['draft_id'], $uid);
        $payload = json_decode(json_encode($draft, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $controller = Server::get('OCA\\Mail\\Controller\\DraftsController');
        $controller->update(id: $draft->getId(), accountId: $draft->getAccountId(), subject: $draft->getSubject(),
            bodyPlain: $draft->getBodyPlain(), bodyHtml: $draft->getBodyHtml(), editorBody: $draft->getEditorBody(),
            isHtml: $draft->isHtml(), smimeSign: $draft->getSmimeSign(), smimeEncrypt: $draft->getSmimeEncrypt(),
            to: $payload['to'], cc: $payload['cc'], bcc: $payload['bcc'], attachments: $payload['attachments'],
            aliasId: $draft->getAliasId(), inReplyToMessageId: $draft->getInReplyToMessageId());
        $reopened = $draftService->getMessage($draft->getId(), $uid);
        $due = Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->findDueDrafts(time()+600);
        $out = ['draft_only'=>property_exists($reopened,'mcpDraftOnly')&&$reopened->getMcpDraftOnly(),'in_reply_to' => $reopened->getInReplyToMessageId(), 'sync_held' => $reopened->isFailed(),
            'due_for_sync' => in_array((int)$draft->getId(), array_map(static fn(object $d): int => (int)$d->getId(), $due), true)];
        break;
    case 'cleanup-contract':
        $native = Server::get('OCA\\Mail\\Service\\Attachment\\AttachmentService');
        $store = Server::get(\OCA\Mcp\Db\OperationsMapper::class);
        $orphans=[]; $ids=[]; $scope=hash('sha256','cleanup-fixture-'.$uid.'-'.bin2hex(random_bytes(8)));
        try {
            foreach (['orphan','already_removed','uncertain'] as $kind) {
                $a=$native->addFileFromString($uid,'cleanup-fixture.txt','text/plain','fixture bytes',null,'attachment');
                $orphans[$kind]=(int)$a->getId();
            }
            $native->deleteAttachment($uid,$orphans['already_removed']);
            foreach ($orphans+['associated'=>(int)$in['attachment_id']] as $kind=>$attachmentId) {
                $id=bin2hex(random_bytes(12)); $ids[$kind]=$id;
                $store->reserve(['scope_key'=>$scope,'operation_id'=>$id,'actor_uid'=>$uid,'tool_name'=>'mail_write_draft',
                    'effect_digest'=>'fixture','target_key'=>null,'state'=>'running','resource_refs'=>[],
                    'created_at'=>time()-172800,'updated_at'=>time()-172800]);
                $store->finish($scope,$id,$kind==='uncertain'?'uncertain':'partial',['staged'=>[['attachment_id'=>$attachmentId]]],time()-172800);
            }
            Server::get(\OCA\Mcp\Modules\Mail\Operations\MailOperationCleanup::class)->run();
            $states=[];
            foreach ($ids as $kind=>$id) $states[$kind]=$store->get($scope,$id)['state'];
            $missing=false;
            try {$native->getAttachment($uid,$orphans['orphan']);}
            catch (\OCA\Mail\Exception\AttachmentNotFoundException) {$missing=true;}
            [$associated]=$native->getAttachment($uid,(int)$in['attachment_id']);
            [$uncertain]=$native->getAttachment($uid,$orphans['uncertain']);
            $out=['states'=>$states,'orphan_missing'=>$missing,'associated_draft_id'=>$associated->getLocalMessageId(),
                'uncertain_attachment_exists'=>$uncertain->getId()===$orphans['uncertain']];
        } finally {
            foreach ($orphans as $id) $native->deleteAttachment($uid,$id);
            // These are synthetic cleanup-contract rows, never admissions for real writes. Keep fixture scenarios isolated.
            $q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
            $q->delete('mcp_operations')->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))
                ->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();
        }
        break;
    case 'composition-contract':
        Server::get(ConnectionContext::class)->setSelection(['mail_write_draft','mail_attach_files','mail_read_message','files_read']);
        $errors=[];
        try {
            $plan=Server::get(GrantPolicy::class)->within($uid, static fn(): array =>
                Server::get(\OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations::class)->previewWrite($in['arguments'],$uid));
        } catch (\Throwable $e) {
            do {
                preg_match_all('/(?:OCA|OCP|Psr)\\\\[A-Za-z0-9_\\\\]+/', $e->getMessage(), $classes);
                $errors[]=['class'=>get_class($e),'services'=>$classes[0], 'site'=>basename($e->getFile()).':'.$e->getLine(),
                    'trace'=>array_map(static fn(array $frame): string=>($frame['class']??'').'::'.($frame['function']??'').'@'.basename($frame['file']??'').':'.($frame['line']??0),array_slice($e->getTrace(),0,12))];
            } while (($e=$e->getPrevious())!==null);
        }
        $out=['errors'=>$errors];
        break;
	case 'accounts':
		$service = Server::get('OCA\\Mail\\Service\\AccountService');
		$out = ['accounts' => array_map(static fn (object $account): array => ['id' => (int)$account->getId(), 'email' => (string)$account->getEmail()],
			$service->findByUserId($uid))];
		break;
	case 'observe':
		Server::get(ConnectionContext::class)->setSelection(['mail_list_accounts', 'mail_list_mailboxes']);
		$policy = Server::get(GrantPolicy::class);
		$availability = Server::get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class);
        $registry = new ToolRegistry([Server::get(MailModule::class)], $availability, Server::get(\OCA\Mcp\Mcp\Tools\Execution\ToolExecutor::class));
		$out = [
			'tools' => $policy->within($uid, static fn (): array => array_column($registry->list($uid), 'name')),
			'accounts' => callTool($registry, $policy, $uid, 'mail_list_accounts', []),
			'own' => callTool($registry, $policy, $uid, 'mail_list_mailboxes', ['account_id' => $in['own']]),
			'foreign' => callTool($registry, $policy, $uid, 'mail_list_mailboxes', ['account_id' => $in['foreign']]),
			'missing' => callTool($registry, $policy, $uid, 'mail_list_mailboxes', ['account_id' => $in['missing']]),
		];
		break;
	default:
		throw new RuntimeException('Unknown Mail actor action ' . $action);
}
echo json_encode($out, JSON_THROW_ON_ERROR);

$completion->complete();
