<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

/** Preserve reviewed native draft identity without changing any SMTP/MDN method. */
function nativeDraftOverlay(Closure $read,array $files):array {
    $path='lib/Service/MailTransmission.php';$stock=$read($path);
    $start=strpos($stock,'public function saveLocalDraft(');$end=strpos($stock,'private function buildMimePart(',$start);
    if($start===false||$end===false)throw new RuntimeException('Unexpected native draft serializer boundary.');
    $method=substr($stock,$start,$end-$start);
    $method=str_replace('public function saveLocalDraft(Account $account, LocalMessage $message): void {','public function saveLocalDraft(Account $account, LocalMessage $message, ?\\OCA\\Mail\\Drafts\\DraftScope $draftScope = null): void {',$method);
    $needle='$from = Address::fromRaw($account->getName(), $account->getEMailAddress());';
    if(substr_count($method,$needle)!==1)throw new RuntimeException('Unexpected native draft sender.');
    $replacement= <<<'PHP'
if ($message->getType() !== LocalMessage::TYPE_DRAFT || $message->getAccountId() !== $account->getId()) {
            throw new ServiceException('Draft identity does not belong to the selected account.');
        }
        $name = $account->getName();
        $email = $account->getEMailAddress();
        if ($message->getAliasId() !== null) {
            try {
                $alias = $this->aliasesService->find($message->getAliasId(), $account->getUserId());
            } catch (DoesNotExistException) {
                throw new ServiceException('The explicit draft identity is no longer available.');
            }
            if ($alias->getAccountId() !== $account->getId()) {
                throw new ServiceException('The explicit draft identity belongs to another account.');
            }
            $name = $alias->getName() ?? $name;
            $email = $alias->getAlias();
        }
        if ($message->getMcpDraftOnly() && ($message->getSendAt() !== null || $message->getSmimeSign() || $message->getSmimeEncrypt() || $message->isPgpMime())) {
            throw new ServiceException('This draft requires native security or scheduling handling outside the draft-only editor.');
        }
        $parent = $message->getInReplyToMessageId();
        if ($parent !== null && (strlen($parent) > 998 || preg_match('/^<[^<>\x00-\x20\x7f]+>$/D', $parent) !== 1)) {
            throw new ServiceException('The draft reply parent is not a bounded single message identifier.');
        }
        if ($draftScope === null || $draftScope->uid !== $account->getUserId()) { throw new ServiceException('Draft conversion requires native lifecycle ownership.'); }
        $draftScope->assertOwned();
        $from = Address::fromRaw($name, $email);
PHP;
    $method=str_replace($needle,$replacement,$method);
    $needle='$draftHeaders = $this->buildMimeHeaders($from, $to, $cc, $bcc, $message->getSubject());';
    if(substr_count($method,$needle)!==1)throw new RuntimeException('Unexpected native draft headers.');
    $method=str_replace($needle,$needle."\n        if (\$parent !== null) {\n            \$draftHeaders->addHeader('In-Reply-To', \$parent);\n            \$draftHeaders->addHeader('References', \$parent);\n        }",$method);
    $clientNeedle='$client = $this->imapClientFactory->getClient($account);';
    if(substr_count($method,$clientNeedle)!==1)throw new RuntimeException('Unexpected native conversion client.');
    $method=str_replace($clientNeedle,$clientNeedle."\n        \$client->setDraftScope(\$draftScope);",$method);
    $patched=substr($stock,0,$start).$method.substr($stock,$end);
    // The transformation is confined to this one native null-transport method.
    if(substr($patched,0,$start)!==substr($stock,0,$start)||substr($patched,strpos($patched,'private function buildMimePart('))!==substr($stock,$end))throw new RuntimeException('Native sending/MIME methods changed outside the draft boundary.');
    $files[$path]=$patched;
    $replace=static function(string $source,string $needle,string $replacement):string {
        if(substr_count($source,$needle)!==1)throw new RuntimeException('Unexpected pinned native editor source.');
        return str_replace($needle,$replacement,$source);
    };
    $path='lib/Db/LocalMessage.php';$local=$read($path);
    $local=$replace($local,'protected $accountId;',"protected \$accountId;\n    protected \$mcpDraftOnly = false;");
    $local=$replace($local,"\$this->addType('type', 'integer');","\$this->addType('type', 'integer');\n        \$this->addType('mcpDraftOnly', 'boolean');");
    $files[$path]=$local;
    $path='lib/Db/LocalMessageMapper.php';$files[$path]=$replace($read($path),'class LocalMessageMapper extends QBMapper {',"class LocalMessageMapper extends QBMapper {\n    use DraftOnlyStorage;");
    $path='lib/Service/OutboxService.php';$outbox=$read($path);
    $needle='public function convertDraft(LocalMessage $draftMessage, int $sendAt): LocalMessage {';
    $outbox=$replace($outbox,$needle,$needle."\n        if (\$draftMessage->getMcpDraftOnly()) { throw new ClientException('This native editor supports drafts only.'); }");
    $needle='public function sendMessage(LocalMessage $message, Account $account): LocalMessage {';
    $outbox=$replace($outbox,$needle,$needle."\n        if (\$message->getMcpDraftOnly()) { throw new ClientException('This native editor supports drafts only.'); }");
    $needle='public function saveMessage(Account $account, LocalMessage $message, array $to, array $cc, array $bcc, array $attachments = []): LocalMessage {';
    $outbox=$replace($outbox,$needle,$needle."\n        if (\$message->getMcpDraftOnly()) { throw new ClientException('This native editor supports drafts only.'); }");
    $files[$path]=$outbox;
    $path='lib/Controller/DraftsController.php';$controller=$read($path);
    $start=strpos($controller,'public function update(');$end=strpos($controller,'public function destroy(',$start);
    if($start===false||$end===false)throw new RuntimeException('Unexpected native draft update boundary.');
    $update=substr($controller,$start,$end-$start);
    $needle='$message = $this->service->getMessage($id, $effectiveUserId);';
    $update=$replace($update,$needle,$needle."\n        if (\$message->getMcpDraftOnly() && (\$sendAt !== null || \$smimeSign || \$smimeEncrypt || \$isPgpMime)) { throw new ClientException('Draft-only editing requires an ordinary unscheduled draft.'); }");
    $update=$replace($update,'$message->setFailed($failed);','$message->setFailed($failed || $message->getMcpDraftOnly());');
    $files[$path]=substr($controller,0,$start).$update.substr($controller,$end);
    $path='lib/Controller/PageController.php';$page=$read($path);
    $page=$replace($page,'private IAppConfig $appConfig,',"private IAppConfig \$appConfig,\n        private \\OCA\\Mail\\Service\\LocalDraftEditor \$mcpDraftEditor,");
    $page=$replace($page,'public function index(): TemplateResponse {','public function index(?int $mcpLocalDraft = null): TemplateResponse {');
    $needle='if (class_exists(LoadViewer::class)) {';
    $entry= <<<'PHP'
if ($mcpLocalDraft !== null) {
            try {
                $localDraft = $this->mcpDraftEditor->read($this->userId, $mcpLocalDraft);
            } catch (\OCP\AppFramework\Db\DoesNotExistException|\OCA\Mail\Exception\ClientException) {
                return new \OCP\AppFramework\Http\NotFoundResponse();
            }
            $this->initialStateService->provideInitialState('mcp-local-draft', $localDraft);
        }
PHP;
    $entry .= "\n" . str_repeat(' ', 8);
    $files[$path]=$replace($page,$needle,$entry.$needle);
    $runtime=$read('js/mail.js');
    $chunks=[
        'js/mail.2803.29abfc1efd368b07a996.js'=>['created(){const t=this.mainStore.getAccounts;','created(){'.file_get_contents(__DIR__.'/frontend/open-local-draft.js').';const t=this.mainStore.getAccounts;'],
        'js/mail.new-message-modal.ddd76b56a1edd9679f59.js'=>['"is-draft":void 0!==t.composerData.draftId','"draft-only":t.composerData.mcpLocalDraft===true,"is-draft":void 0!==t.composerData.draftId||t.composerData.mcpLocalDraft===true'],
    ];
    foreach($chunks as $path=>[$needle,$replacement]){
        $original=$read($path);$chunk=$replace($original,$needle,$replacement);
        if(str_contains($path,'new-message-modal')){
            $chunk=$replace($chunk,'isDraft:{type:Boolean,default:!1},','isDraft:{type:Boolean,default:!1},draftOnly:{type:Boolean,default:!1},');
            $chunk=$replace($chunk,'canSend(){','canSend(){if(this.draftOnly)return false;');
            $chunk=$replace($chunk,'t.isScheduledSendingDisabled?','(t.draftOnly||t.isScheduledSendingDisabled)?');
            $chunk=$replace($chunk,'smimeCertificateForCurrentAlias(){','smimeCertificateForCurrentAlias(){if(this.draftOnly)return undefined;');
            $chunk=$replace($chunk,'this.wantsSmimeSign=-1!==this.smimeSignAliases.indexOf(t)','this.wantsSmimeSign=!this.draftOnly&&-1!==this.smimeSignAliases.indexOf(t)');
            $chunk=$replace($chunk,'async beforeMount(){this.setAlias(),this.initBody(),await this.onMailvelopeLoaded(await(0,Pt.J)())}', 'async beforeMount(){this.setAlias();this.initBody();if(!this.draftOnly)await this.onMailvelopeLoaded(await(0,Pt.J)())}');
            $chunk=$replace($chunk,'bodyVal:this.editorBody,','bodyVal:this.editorBody,mcpOriginalBody:{format:this.body?.format,value:this.body?.value??""},mcpInitialEditorBody:null,mcpInitialEditorMode:null,');
            $chunk=$replace($chunk,'onEditorReady(t){this.bodyVal=t.getData(),','onEditorReady(t){this.bodyVal=t.getData();if(this.draftOnly&&this.mcpInitialEditorBody===null){this.mcpInitialEditorBody=this.bodyVal;this.mcpInitialEditorMode=this.editorMode;}');
            $tail='return t.isHtml?t.bodyHtml=this.bodyVal:t.isPgpMime?t.bodyPlain=this.bodyVal:t.bodyPlain=(0,qt.Ul)((0,qt.qy)(this.bodyVal)).value,t},saveDraft(){';
            $replacement='t.isHtml?t.bodyHtml=this.bodyVal:t.isPgpMime?t.bodyPlain=this.bodyVal:t.bodyPlain=(0,qt.Ul)((0,qt.qy)(this.bodyVal)).value;'.file_get_contents(__DIR__.'/frontend/preserve-local-draft-body.js').';return t},saveDraft(){';
            $chunk=$replace($chunk,$tail,$replacement);
        }
        $chunk=preg_replace('~\n?//# sourceMappingURL=[^\r\n]+~','',$chunk);
        if(preg_match('/\.([a-f0-9]{20})\.js$/D',$path,$hash)!==1)throw new RuntimeException('Unexpected native chunk name.');
        $next=substr(hash('sha256',$chunk),0,20);$target=str_replace('.'.$hash[1].'.js','.'.$next.'.js',$path);
        $runtime=$replace($runtime,'"'.$hash[1].'"','"'.$next.'"');$files[$target]=$chunk;
    }
    $files['js/mail.js']=$runtime;
    $files['appinfo/info.xml']=$replace($read('appinfo/info.xml'),'<version>5.12.5</version>','<version>5.12.5.6</version>');
    $files['appinfo/info.xml']=$replace($files['appinfo/info.xml'],'<nextcloud min-version="32" max-version="35" />','<nextcloud min-version="35" max-version="35" />');
    return $files;
}
