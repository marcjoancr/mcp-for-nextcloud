<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Exact pinned native mailbox synchronization; retain both original atomic boundaries. */
function nativeMailboxSyncOverlay(Closure $read,array $files):array {
    $replace=static function(string $s,string $before,string $after,int $count=1):string {
        if(substr_count($s,$before)!==$count)throw new RuntimeException('Native mailbox sync source changed.');
        return str_replace($before,$after,$s);
    };
    $p='lib/IMAP/MailboxSync.php';$s=$files[$p]??$read($p);
    $s=$replace($s,'use TTransactional;','use TTransactional, OwnedMailboxSync;');
    $s=$replace($s,'$this->atomic(function () use (','$this->mcpMailboxAtomic($mcpScope,$mcpCurrent,function () use (',2);
    $s=$replace($s,'}, $this->dbConnection);','});',2);
    $s=$replace($s,'$this->syncMailboxStatus($mailboxes, $personalNamespace, $client);','$this->syncMailboxStatus($mailboxes, $personalNamespace, $client, $mcpScope, $mcpCurrent);');
    $s=$replace($s,'private function syncMailboxStatus(mixed $mailboxes, ?string $personalNamespace, \\Horde_Imap_Client_Socket $client): void {','private function syncMailboxStatus(mixed $mailboxes, ?string $personalNamespace, \\Horde_Imap_Client_Socket $client, \\OCA\\Mail\\Drafts\\DraftScope $mcpScope, \\Closure $mcpCurrent): void {');
    $s=$replace($s,"\t\ttry {\n\t\t\ttry {","\t\ttry {\n            \$mcpCurrent(\$ownClient);\n\t\t\ttry {");
    $s=nativeDraftReplaceBody($s,'sync',static fn(string $body):string=>"\n        \$this->mcpMailboxSync(\$account,function(\\OCA\\Mail\\Drafts\\DraftScope \$mcpScope,\\Closure \$mcpCurrent)use(\$account,\$logger,\$force,\$client):void {".$body."\n        });\n    ");
    $files[$p]=$s;return $files;
}
