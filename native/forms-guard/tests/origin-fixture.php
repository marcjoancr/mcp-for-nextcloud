<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use OCP\Server;
/** Explicit disposable-cell fixture using the real MCP policy/proof services. No production bypass. */
function formsNativeOrigin(string $uid,string $tool,array $input=[]): array {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $config=Server::get(\OCP\IConfig::class);
    if($config->getUserValue($uid,'mcp','forms_native_test_ready','')!=='2') {
        $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);
        foreach(\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG['forms'] as $operation){$policy->setGrant($uid,'forms',$operation,true);}
        foreach(['read','create','edit','share'] as $operation){$policy->setGrant($uid,'files',$operation,true);}
        $tools=array_keys(\OCA\Mcp\Modules\Forms\Execution\FormsCommands::definitions());$variants=[];
        foreach($tools as $name){$definition=\OCA\Mcp\Modules\Forms\Execution\FormsCommands::definitions()[$name];if(isset($definition['grantAnyOf'])){$variants[$name]=\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG['forms'];}}
        Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid,$tools):bool {
            $mapper=Server::get(\OCA\Mcp\Db\ToolGrantsMapper::class);foreach($tools as $name){$mapper->set('user',$uid,$name,'allow');}return true;
        });
        $selections=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$current=$selections->get($uid,0);$selections->save($uid,0,$tools,$current['revision'],$variants);
        $config->setUserValue($uid,'mcp','forms_native_test_ready','2');
    }
    $snapshot=[];
    if(isset($input['id'])) {
        try{$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);$snapshot['form']=['ownerId'=>$form->getOwnerId()];
            $snapshot['shares']=Server::get(\OCA\Forms\Service\FormsService::class)->getShares($form->getId());}catch(\OCP\AppFramework\Db\DoesNotExistException){}
    }
    return Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->capture($uid,$tool,$input,$snapshot);
}
