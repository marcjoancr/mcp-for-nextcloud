<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
function nativeAccountVisibilityOverlay(Closure $read,array $files):array {
    $take=static fn(string $path):string=>$files[$path]??$read($path);
    $p='lib/Db/MailAccountMapper.php';$s=$take($p);
    // Normal direct, delegated, provisioning and background discovery all select visible account rows.
    foreach(['find','findById','findDelegatedByUserId','findByUserId','findByUserIdAndAddress','findProvisionedAccount','getAllAccounts','getAllUserIdsWithAccounts'] as $method){
        $column=$method==='findDelegatedByUserId'?'a.id':'id';
        $s=nativeDraftReplaceBody($s,$method,static function(string $body)use($column):string {
            $needle='return $this->find';if(substr_count($body,$needle)!==1)throw new RuntimeException('Unexpected native account reader.');
            return str_replace($needle,"(new \\OCA\\Mail\\Lifecycle\\AccountVisibility(\$this->db))->excludePending(\$query,'$column');\n\t\t".$needle,$body);
        });
    }
    foreach(['findAllWhereOooFollowsSystem'=>'query','getTotal'=>'qb','getRandomAccountIdsByImapHost'=>'query'] as $method=>$variable){
        $s=nativeDraftReplaceBody($s,$method,static function(string $body)use($variable):string {
            $needle='$result = $'.$variable.'->executeQuery();';if(substr_count($body,$needle)!==1)throw new RuntimeException('Unexpected native account iterator.');
            return str_replace($needle,"(new \\OCA\\Mail\\Lifecycle\\AccountVisibility(\$this->db))->excludePending(\$$variable,'id');\n\t\t".$needle,$body);
        });
    }
    $files[$p]=$s;
    $p='lib/Service/AccountService.php';$s=$take($p);
    // Account objects cached before deletion cannot establish current owner visibility or existence.
    // Requery also discovers the recreated user's new IDs without reviving purged old objects.
    foreach(['findByUserId'=>'currentUserId','findByUserIdAndAddress'=>'userId','find'=>'userId'] as $method=>$uid){
        $s=nativeDraftReplaceBody($s,$method,static fn(string $body):string=>"\n        unset(\$this->accounts[\$$uid]);\n".$body);
    }
    $files[$p]=$s;
    // Stale Account objects must not create a new credential-bearing client after confirmation/purge.
    // Unsaved setup accounts have no captured ID and preserve the native connection-test workflow.
    foreach(['lib/IMAP/IMAPClientFactory.php'=>'getClient','lib/SMTP/SmtpClientFactory.php'=>'create'] as $p=>$method){
        $files[$p]=nativeDraftReplaceBody($take($p),$method,static fn(string $body):string=>"\n        if(\$account->getId()!==null)(new \\OCA\\Mail\\Lifecycle\\AccountVisibility(\\OCP\\Server::get(\\OCP\\IDBConnection::class)))->assertVisible(\$account->getUserId(),\$account->getId());\n".$body);
    }
    return $files;
}
