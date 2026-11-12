<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
function nativeSieveOverlay(Closure $read,array $files):array {
    $take=static fn(string $p):string=>$files[$p]??$read($p);
    $replace=static function(string $s,string $a,string $b):string{if(substr_count($s,$a)!==1)throw new RuntimeException('Pinned Sieve source mismatch.');return str_replace($a,$b,$s);};
    $p='lib/Sieve/SieveClientFactory.php';$s=$take($p);$s=$replace($s,'public function getClient(Account $account): ManageSieve {', <<<'CODE'
public function getGuardedClient(#[\SensitiveParameter] Account $account): BoundedManageSieve {
        try {
            $a=$account->getMailAccount();
            if(!$a->isSieveEnabled()||$a->getDebug()||$this->config->getSystemValueBool('app.mail.debug')||!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true))throw new SieveFailure();
            $user=$a->getSieveUser()?:$a->getInboundUser();$password=$a->getSievePassword()?:$a->getInboundPassword();$mode=$a->getSieveSslMode();
            return new BoundedManageSieve(['host'=>$a->getSieveHost(),'port'=>$a->getSievePort(),'user'=>$user,'password'=>$this->crypto->decrypt($password),'secure'=>$mode===''?'tls':$mode]);
        } catch(\Throwable) {throw new SieveFailure();}
    }
    public function getClient(Account $account): ManageSieve {
CODE
    );$s=$replace($s,"if (\$logFile !== null) {\n\t\t\t\$params['logger'] = new SieveLogger(\$logFile);\n\t\t}","// Sieve programs and authentication are never written to protocol debug logs.");$s=str_replace('$this->cache[$account->getId()]','$this->cache[$account->getId().":".$account->getMailAccount()->getMcpAuthRevision()]',$s);$files[$p]=$s;
    foreach(['lib/Service/FilterService.php'=>['parse'=>['$account','','return'],'update'=>['$account','$filters','void']],'lib/Service/OutOfOfficeService.php'=>['parseState'=>['$account','','return'],'update'=>['$account','$state','void'],'disable'=>['$account','','void'],'updateFromSystem'=>['$mailAccount','$user','return']]]as$p=>$methods){$s=$take($p);foreach($methods as$name=>[$account,$vars,$return])$s=nativeDraftReplaceBody($s,$name,static fn($body)=>($return==='return'?'return ':'').'\OCA\Mail\Service\SieveSettingsFence::account('.$account.',function('.'\OCA\Mail\Db\MailAccount '.$account.')'.($vars!==''?'use('.$vars.')':'').' {'.$body.'});');
        $s=preg_replace('/\$this->logger->error\(\x27Failed to save sieve script: \x27 \. \$e->getMessage\(\), \[.*?\]\);/s',"\$this->logger->error('Sieve update refused.');",$s);$files[$p]=$s;
    }
    $p='lib/Service/SieveService.php';$s=$take($p);
    $s=nativeDraftReplaceBody($s,'getActiveScript',static fn($body)=>'return \OCA\Mail\Service\SieveSettingsFence::run($userId,function()use($userId,$accountId){'.$body.'});');
    $s=nativeDraftReplaceBody($s,'updateActiveScript',static fn($body)=>'\OCA\Mail\Service\SieveSettingsFence::run($userId,function()use($userId,$accountId,$script){'.$body.'if($sieve->getActive()!==$scriptName||rtrim($sieve->getScript($scriptName),"\\r\\n")!==rtrim($script,"\\r\\n"))throw new \OCA\Mail\Sieve\SieveFailure();});');$files[$p]=$s;
    $p='lib/Controller/OutOfOfficeController.php';$s=$take($p);foreach(['followSystem'=>'$accountId','update'=>'$accountId,$enabled,$start,$end,$subject,$message']as$name=>$vars)$s=nativeDraftReplaceBody($s,$name,static fn($body)=>'$mcpUser=$this->userSession->getUser();if($mcpUser===null)return JsonResponse::fail([],Http::STATUS_FORBIDDEN);return \OCA\Mail\Service\SieveSettingsFence::run($mcpUser->getUID(),function()use('.$vars.'){'.$body.'});');$files[$p]=$s;
    $p='lib/Controller/SieveController.php';$s=$take($p);$s=nativeDraftReplaceBody($s,'updateAccount',static fn($body)=>'$mcpOwner=$this->delegationService->resolveAccountUserId($id,$this->userId);return \OCA\Mail\Service\SieveSettingsFence::run($mcpOwner,function()use($id,$sieveEnabled,$sieveHost,$sievePort,$sieveUser,$sievePassword,$sieveSslMode){'.$body.'});');
    $s=$replace($s,"\$this->logger->error('Installing sieve script failed: ' . \$e->getMessage(), ['app' => 'mail', 'exception' => \$e]);","\$this->logger->error('Sieve update refused.', ['app' => 'mail']);");$s=str_replace("['message' => \$e->getMessage()]","['message' => 'Sieve update refused.']",$s);$files[$p]=$s;
    $p='lib/Listener/OutOfOfficeListener.php';$s=$take($p);
    $s=nativeDraftReplaceBody($s,'handle',static function($body)use($replace){return $replace($body,'$eventData = $event->getData();', '$eventData = $event->getData();' . "\n" . '\OCA\Mail\Service\SieveSettingsFence::run($eventData->getUser()->getUID(),function()use($event,$eventData){').' });';});
    $s=$replace($s,'foreach ($accounts as $account) {','foreach ($accounts as $account) { $account=$this->accountService->find($eventData->getUser()->getUID(),(int)$account->getId());');
    $s=preg_replace('/\$this->logger->error\(\x27Failed to apply out-of-office sieve script: \x27 \. \$e->getMessage\(\), \[.*?\]\);/s',"\$this->logger->error('Sieve system absence update refused.');",$s);$files[$p]=$s;
    // Native exceptions may be logged with PHP trace arguments enabled. Never retain script or credential inputs there.
    foreach(['lib/Sieve/SieveClientFactory.php','lib/Service/FilterService.php','lib/Service/OutOfOfficeService.php','lib/Service/SieveService.php','lib/Controller/OutOfOfficeController.php','lib/Controller/SieveController.php','lib/Controller/FilterController.php','lib/Service/OutOfOffice/OutOfOfficeParser.php','lib/Service/OutOfOffice/OutOfOfficeState.php','lib/Service/MailFilter/FilterParser.php']as$p){
        $files[$p]=$files[$p]??$take($p);
        $files[$p]=preg_replace('/(?<=[(,])(\\s*)(\\??string \\$(?:script|sievePassword|password|sieveUser|user|sieveHost|host|subject|message|sieveScript|untouchedScript)\\b|array \\$(?:filters|data|allowedRecipients)\\b)/','${1}#[\\\\SensitiveParameter] ${2}',$files[$p]);
        $files[$p]=preg_replace('/(?<=[(,])(\\s*)((?:\\\\OCA\\\\Mail\\\\Db\\\\)?(?:Account|MailAccount|OutOfOfficeState) \\$[A-Za-z_][A-Za-z0-9_]*)/','${1}#[\\\\SensitiveParameter] ${2}',$files[$p]);
    }
    return $files;
}
