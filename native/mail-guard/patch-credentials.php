<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Exact native manual entry points; never replaces native credential transport or forms. */
function nativeCredentialOverlay(Closure $read,array $files):array {
    $take=static fn(string $path):string=>$files[$path]??$read($path);
    $replace=static function(string $source,string $needle,string $next):string {
        if(substr_count($source,$needle)!==1)throw new RuntimeException('Unexpected pinned native credential source.');return str_replace($needle,$next,$source);
    };
    $dependency=static function(string $source):string {
        [$body]=nativeDraftMethodRange($source,'__construct');$close=strrpos(substr($source,0,$body-1),')');
        if($close===false||preg_match('/,\s*$/',substr($source,0,$close))!==1)throw new RuntimeException('Unexpected native constructor signature.');
        return rtrim(substr($source,0,$close)," \t")."        private \\OCA\\Mail\\Authorization\\SetupEvidence \$mcpSetupEvidence,\n\t".substr($source,$close);
    };
    $path='lib/Db/MailAccount.php';$source=$take($path);
    $needle='public function __construct(array $params = []) {';
    $new= <<<'CODE'
protected $mcpAuthRevision = 0;
    /** Refresh the hidden loaded revision without making it an ORM input. */
    public function mcpCaptureAuthRevision(int $revision):void {
        if($revision<0)throw new \LogicException('Invalid native authorization revision.');
        $this->mcpAuthRevision=$revision;
    }
    public function __construct(array $params = []) {
        $this->addType('mcpAuthRevision','integer');
CODE;
    $files[$path]=$replace($source,$needle,$new);
    $path='lib/Db/MailAccountMapper.php';$files[$path]=$replace($take($path),'class MailAccountMapper extends QBMapper {',"class MailAccountMapper extends QBMapper {\n    use CredentialRevision;");
    $path='lib/Service/OauthStateService.php';$source=$take($path);
    $source=nativeDraftReplaceBody($source,'createState',static fn(string $body):string=>"\n        return \\OCA\\Mail\\Authorization\\StateCodec::create(\$this->crypto,\$userId,\$accountId,\$this->time->getTime());\n    ");
    $source=nativeDraftReplaceBody($source,'validateAndConsume',static fn(string $body):string=>"\n        try{return \\OCA\\Mail\\Authorization\\StateCodec::validate(\$this->crypto,\$userId,\$state,\$this->time->getTime());}\n        catch(\\OCA\\Mail\\Exception\\DraftAdmissionFailure){throw new InvalidOauthStateException('Invalid or expired native OAuth state.');}\n    ");
    $files[$path]=$source;
    $path='lib/Controller/PageController.php';$page=$dependency($take($path));
    $page=$replace($page,'public function index(?int $mcpLocalDraft = null):','public function index(?int $mcpLocalDraft = null, ?string $mcpSetup = null):');
    $page=nativeDraftReplaceBody($page,'index',static function(string $body):string {
        $prefix= <<<'CODE'

        $mcpActivation=null;
        if($mcpSetup!==null){
            try{
                if($mcpLocalDraft!==null)throw new \RuntimeException('Incompatible native entry.');
                $mcpActivation=$this->mcpSetupEvidence->activate($this->userId,$mcpSetup);
                $this->initialStateService->provideInitialState('mcp-setup-context',$mcpActivation['id']);
            }catch(\Throwable){return new \OCP\AppFramework\Http\NotFoundResponse();}
        }
        $mcpResponse=(function()use($mcpLocalDraft){
CODE;
        $suffix= <<<'CODE'

        })();
        if($mcpActivation!==null&&$mcpResponse instanceof \OCP\AppFramework\Http\TemplateResponse){
            $mcpResponse->addCookie(\OCA\Mail\Authorization\SetupEvidence::COOKIE,$mcpActivation['id'],new \DateTime('@'.$mcpActivation['expires_at']),'Lax');
            $mcpResponse->addHeader('Cache-Control','private, no-store');
        }
        return $mcpResponse;
CODE;
        return $prefix.$body.$suffix."\n    ";
    });
    $page=$replace($page,'public function setup(): TemplateResponse {','public function setup(?string $mcpSetup = null): TemplateResponse {');
    $page=nativeDraftReplaceBody($page,'setup',static fn(string $body):string=>str_replace('$this->index()','$this->index(null,$mcpSetup)',$body));
    $files[$path]=$page;
    $path='lib/Controller/AccountsController.php';$source=$dependency($take($path));
    $source=nativeDraftReplaceBody($source,'update',static function(string $body)use($replace):string {
        $body=$replace($body,'$this->setup->createNewAccount(','$mcpAccount=$this->setup->createNewAccount(');
        $needle="\t\t\t\$this->delegationService->logDelegatedAction";
        if(substr_count($body,$needle)!==1)throw new RuntimeException('Native update completion marker unavailable.');
        return str_replace($needle,"            \$this->mcpSetupEvidence->passwordSaved(\$this->userId,\$mcpAccount,'configure_connection',\$authMethod==='password'&&\$imapPassword!==null,\$authMethod==='password'&&\$smtpPassword!==null);\n".$needle,$body);
    });
    $source=nativeDraftReplaceBody($source,'create',static function(string $body)use($replace):string {
        $needle='$account = $this->setup->createNewAccount(';$start=strpos($body,$needle);
        if($start===false)throw new RuntimeException('Native create entry unavailable.');
        $end=strpos($body,';',$start);if($end===false)throw new RuntimeException('Native create completion unavailable.');
        $hook="\n            if(\$authMethod==='password')\$this->mcpSetupEvidence->passwordSaved(\$this->userId,\$account,'create_account',\$imapPassword!==null,\$smtpPassword!==null);\n            else \$this->mcpSetupEvidence->createdWithoutCredentials(\$this->userId,\$account);\n";
        return substr($body,0,$end+1).$hook.substr($body,$end+1);
    });
    $files[$path]=$source;
    $path='lib/Controller/OauthController.php';$source=$dependency($take($path));
    $source=nativeDraftReplaceBody($source,'generateState',static function(string $body)use($replace):string {
        $needle="return JsonResponse::success(['state' => \$state]);";
        return $replace($body,$needle,"\$this->mcpSetupEvidence->bindState(\$this->userId,\$accountId,\$state);\n        ".$needle);
    });
    $files[$path]=$source;
    foreach(['Google'=>'google','Microsoft'=>'microsoft'] as $name=>$provider){
        $path='lib/Integration/'.$name.'Integration.php';$original=$take($path);$source=$original;
        $source=$replace($source,'string $code): Account {','string $code, ?\\OCA\\Mail\\Authorization\\ManualAuthorizationPermit $mcpManual = null): Account {');
        $source=nativeDraftReplaceBody($source,'finishConnect',static function(string $body):string {
            $needle="\t\treturn \$account;";$last=strrpos($body,$needle);
            if($last===false)throw new RuntimeException('Native provider success marker unavailable.');
            return substr($body,0,$last)."        \$mcpManual?->accept(\$account);\n".substr($body,$last);
        });
        [$a,$b]=nativeDraftMethodRange($original,'refresh');[$x,$y]=nativeDraftMethodRange($source,'refresh');
        if(substr($original,$a,$b-$a)!==substr($source,$x,$y-$x))throw new RuntimeException('Background authorization refresh must stay unchanged.');
        $files[$path]=$source;
        $path='lib/Controller/'.$name.'IntegrationController.php';$source=$dependency($take($path));
        $source=nativeDraftReplaceBody($source,'oauthRedirect',static function(string $body)use($replace,$provider):string {
            $needle='$updated = $this->'.$provider.'Integration->finishConnect(';
            $body=$replace($body,$needle,"\$mcpPermit=\$this->mcpSetupEvidence->beginOAuth(\$this->userId,\$accountId,\$state);\n        ".$needle);
            $needle="\t\t\t\$code,\n\t\t);";$body=$replace($body,$needle,"\t\t\t\$code,\n            \$mcpPermit,\n\t\t);");
            $needle='$this->accountService->update($updated->getMailAccount());';
            return $replace($body,$needle,$needle."\n        \$this->mcpSetupEvidence->oauthSaved(\$this->userId,\$updated,\$mcpPermit,'".$provider."');");
        });
        $files[$path]=$source;
    }
    // Patch only the three pinned native API producers; retain their transport and forms.
    $payload=file_get_contents(__DIR__.'/frontend/setup-page-payload.js');
    $runtime=$take('js/mail.js');
    $module='var r=n(44760),a=n(63814);function i(e){return{id:e.accountId,...e}}';
    $runtime=$replace($runtime,$module,'var r=n(44760),a=n(63814);'.$payload.';function i(e){return{id:e.accountId,...e}}');
    foreach(['post','put'] as $verb)$runtime=$replace($runtime,'r.Ay.'.$verb.'(t,e).then((e=>e.data.data)).then(i)','r.Ay.'.$verb.'(t,mcpSetupPayload(e)).then((e=>e.data.data)).then(i)');
    $path='js/mail.9829.c130186969f840cafa3e.js';$chunk=$take($path);
    $needle='async function g(t){return(await u.Ay.post((0,p.Jv)("/apps/mail/api/oauth/state"),{accountId:t},{headers:{Accept:"application/json"}})).data.data.state}';
    $chunk=$replace($chunk,$needle,$payload.';'.str_replace('{accountId:t}','mcpSetupPayload({accountId:t})',$needle));
    $chunk=preg_replace('~\n?//# sourceMappingURL=[^\r\n]+~','',$chunk);
    $next=substr(hash('sha256',$chunk),0,20);
    $files[str_replace('c130186969f840cafa3e',$next,$path)]=$chunk;
    $files['js/mail.js']=$replace($runtime,'"c130186969f840cafa3e"','"'.$next.'"');
    $files['appinfo/info.xml']=$replace($take('appinfo/info.xml'),'<version>5.12.5.6</version>','<version>5.12.5.7</version>');
    return $files;
}
