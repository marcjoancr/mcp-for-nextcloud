<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Dedicated immutable queue exclusions; ordinary native sending remains untouched. */
function nativeDeliveryOverlay(Closure $read,array $files):array {
    $take=static fn(string $path):string=>$files[$path]??$read($path);
    $replace=static function(string $s,string $needle,string $next):string {if(substr_count($s,$needle)!==1)throw new RuntimeException('Unexpected pinned delivery source.');return str_replace($needle,$next,$s);};
    $p='lib/Service/MailTransmission.php';$files[$p]=nativeDraftReplaceBody($take($p),'sendMessage',static fn(string $body):string=>"\n        \$mapper=\\OCP\\Server::get(\\OCA\\Mail\\Db\\LocalMessageMapper::class);\$guard=\$mapper->draftCoordinator();\$guard->runOwner(\$account->getUserId(),function()use(\$account,\$localMessage,\$mapper,\$guard){\$guard->admit(\$localMessage,\$mapper,\$account->getUserId(),'send');$body\n        });\n    ");
    $p='lib/IMAP/GuardedFlagMutation.php';$files[$p]=$replace($take($p),'$this->guardRefreshCommand($cmd,$data);','$this->mcpGuardCopyCommand($cmd,$data);$this->guardRefreshCommand($cmd,$data);');
    $p='lib/IMAP/HordeImapClient.php';$files[$p]=$replace($take($p),'class HordeImapClient extends Horde_Imap_Client_Socket {',"class HordeImapClient extends Horde_Imap_Client_Socket {\n    use \\OCA\\Mail\\Transmission\\SingleAppend;");
    $p='lib/Db/AliasMapper.php';$s=$replace($take($p),'class AliasMapper extends QBMapper {',"class AliasMapper extends QBMapper {\n    use \\OCA\\Mail\\Transmission\\HeldAliasWrites;");
    // Bulk native paths must use the same owner lease as individual identity changes.
    $s=nativeDraftReplaceBody($s,'deleteAll',static fn(string $body):string=>"\n        \$uid=\\OCA\\Mail\\Transmission\\DeliveryHolds::owner(\$this->db,(int)\$accountId);\n        \\OCA\\Mail\\Drafts\\DraftCoordinator::forDatabase(\$this->db)->runOwner(\$uid,function()use(\$accountId){\\OCA\\Mail\\Transmission\\DeliveryHolds::assertFree(\$this->db,'account_id',(int)\$accountId);$body\n        });\n    ");
    $s=nativeDraftReplaceBody($s,'deleteProvisionedAliasesByUid',static fn(string $body):string=>"\n        \$q=\$this->db->getQueryBuilder();\$q->select('id')->from('mail_accounts')->where(\$q->expr()->eq('user_id',\$q->createNamedParameter(\$uid)))->andWhere(\$q->expr()->isNotNull('provisioning_id'));\$r=\$q->executeQuery();try{\$ids=\$r->fetchAll();}finally{\$r->closeCursor();}foreach(\$ids as \$row)\$this->deleteAll((int)\$row['id']);\n    ");
    $files[$p]=$s;
    $p='lib/Db/MailAccountMapper.php';$s=$replace($take($p),'class MailAccountMapper extends QBMapper {',"class MailAccountMapper extends QBMapper {\n    use \\OCA\\Mail\\Transmission\\HeldAccountDeletion;");
    foreach(['deleteProvisionedAccounts','deleteProvisionedAccountsByUid','deleteProvisionedOrphanAccounts'] as $method){
        $s=nativeDraftReplaceBody($s,$method,static function(string $body):string {
            // Preserve the exact original eligibility query, replacing only its DELETE with SELECT.
            $body=str_replace("->delete(\$this->getTableName())","->select('*')->from(\$this->getTableName())",$body);
            $needle='$delete->executeStatement();';if(substr_count($body,$needle)!==1)throw new RuntimeException('Native bulk account deletion changed.');
            return str_replace($needle,"foreach(\$this->findEntities(\$delete) as \$account)\$this->delete(\$account);",$body);
        });
    }
    $files[$p]=$s;
    $p='lib/Service/AccountService.php';$s=$take($p);
    foreach(['delete','deleteByAccountId'] as $method){$vars=$method==='delete'?'$currentUserId,$accountId':'$accountId';$s=nativeDraftReplaceBody($s,$method,static fn(string $body):string=>"\n        \$db=\\OCP\\Server::get(\\OCP\\IDBConnection::class);\$uid=\\OCA\\Mail\\Transmission\\DeliveryHolds::owner(\$db,\$accountId);\\OCA\\Mail\\Drafts\\DraftCoordinator::forDatabase(\$db)->runOwner(\$uid,function()use($vars,\$db){\\OCA\\Mail\\Transmission\\DeliveryHolds::assertFree(\$db,'account_id',\$accountId);$body\n        });\n    ");}

    $files[$p]=$s;
    $p='lib/AppInfo/Application.php';$s=$take($p);$needle='$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);';
    $files[$p]=$replace($s,$needle,$needle."\n        \$context->registerEventListener(\\OCP\\User\\Events\\BeforeUserDeletedEvent::class, \\OCA\\Mail\\Listener\\BeforeMailUserDeletedListener::class);");
    $p='lib/Listener/UserDeletedListener.php';$s=$take($p);
    $start=strpos($s,"\t\tforeach (\$this->accountService->findByUserId");$end=strpos($s,"\t\t\$this->textBlockService",$start);
    if($start===false||$end===false)throw new RuntimeException('Unexpected user erasure listener.');
    $files[$p]=substr($s,0,$start)."        if(!\\OCP\\Server::get(\\OCA\\Mail\\Lifecycle\\MailErasureService::class)->deleted(\$user))return;\n".substr($s,$end);
    $p='appinfo/info.xml';$files[$p]=$replace($take($p),'<background-jobs>',"<background-jobs>\n        <job>OCA\\Mail\\BackgroundJob\\MailErasureJob</job>");
    return $files;
}
