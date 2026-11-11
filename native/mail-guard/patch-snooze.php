<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Native UI/background mutations share the owner fence with dedicated snooze intents. */
function nativeSnoozeOverlay(Closure $read,array $files):array {
    $take=static fn(string $p):string=>$files[$p]??$read($p);
    $replace=static function(string $s,string $before,string $after):string{if(substr_count($s,$before)!==1)throw new RuntimeException('Pinned snooze source changed: '.$before);return str_replace($before,$after,$s);};
    $p='lib/Service/GuardedMessageMutations.php';$files[$p]=nativeDraftReplaceBody($take($p),'move',static fn(string $body):string=>'
        return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->db)->runOwner($ownerUid,function()use($ownerUid,$request,$fence):array {
            if($fence instanceof SnoozeFence)$fence->assertPermit($ownerUid,$request);
            else \OCA\Mail\Snooze\SnoozeHolds::mailbox($this->db,$request["mailbox_id"],(int)$request["uid"]);
        '.$body.'
        });
    ');
    $p='lib/Service/GuardedMailboxMutations.php';$files[$p]=nativeDraftReplaceBody($take($p),'mutate',static fn(string $body):string=>'
        return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->db)->runOwner($owner,function()use($owner,$r,$fence):array {
            if($r["action"]==="rename_mailbox")foreach($r["tree"]as$b)\OCA\Mail\Snooze\SnoozeHolds::tree($this->db,$r["account_id"],$b["id"],$b["name"],$b["delimiter"]);
        '.$body.'
        });
    ');
    $p='lib/Service/MailManager.php';$s=$take($p);
    $methods=[
        'moveMessage'=>['$sourceAccount','$sourceAccount,$sourceFolderId,$uid,$destinationAccount,$destFolderId','\\OCA\\Mail\\Snooze\\SnoozeHolds::named($db,(int)$sourceAccount->getId(),$sourceFolderId,$uid);',false],
        'deleteMessage'=>['$account','$account,$mailboxId,$messageUid','\\OCA\\Mail\\Snooze\\SnoozeHolds::named($db,(int)$account->getId(),$mailboxId,$messageUid);',true],
        'deleteMessageWithClient'=>['$account','$account,$mailbox,$messageUid,$client','\\OCA\\Mail\\Snooze\\SnoozeHolds::mailbox($db,(int)$mailbox->getId(),$messageUid);',true],
        'moveThread'=>['$srcAccount','$srcAccount,$srcMailbox,$dstAccount,$dstMailbox,$threadRootId','',false],
        'deleteThread'=>['$account','$account,$mailbox,$threadRootId','',true],
        'renameMailbox'=>['$account','$account,$mailbox,$name','\\OCA\\Mail\\Snooze\\SnoozeHolds::tree($db,(int)$account->getId(),(int)$mailbox->getId(),$mailbox->getName(),$mailbox->getDelimiter());',false],
        'deleteMailbox'=>['$account','$account,$mailbox','\\OCA\\Mail\\Snooze\\SnoozeHolds::tree($db,(int)$account->getId(),(int)$mailbox->getId(),$mailbox->getName(),$mailbox->getDelimiter());',true],
        'clearMailbox'=>['$account','$account,$mailbox','\\OCA\\Mail\\Snooze\\SnoozeHolds::mailbox($db,(int)$mailbox->getId());',true],
    ];
    foreach($methods as$name=>[$account,$vars,$guard,$void])$s=nativeDraftReplaceBody($s,$name,static function(string $body)use($name,$account,$vars,$guard,$void,$replace):string {
        if(in_array($name,['moveThread','deleteThread'],true))$body=$replace($body,'foreach ($messages as $message) {','foreach ($messages as $mcpMessage) { \\OCA\\Mail\\Snooze\\SnoozeHolds::named($db,(int)'.$account.'->getId(),$mcpMessage["mailboxName"],(int)$mcpMessage["messageUid"]); }' . "\n\t\t" . 'foreach ($messages as $message) {');
        return "\n        ".($void?'':'return ')."\\OCA\\Mail\\Snooze\\SnoozeHolds::runAccount($account,function(\\OCP\\IDBConnection \$db)use($vars){ $guard $body\n        });\n    ";});$files[$p]=$s;
    $p='lib/IMAP/IMAPClientFactory.php';$files[$p]=$replace($take($p),'return $client;','\OCA\Mail\Snooze\SnoozeHolds::bindClient($client,$account);return $client;');
    $p='lib/IMAP/MessageMapper.php';$s=$take($p);
    foreach(['move'=>['$sourceFolderId','[$messageId]','$client,$sourceFolderId,$messageId,$destFolderId',false],'expunge'=>['$mailbox','[$id]','$client,$mailbox,$id',true],'addFlag'=>['$mailbox->getName()','$uids','$client,$mailbox,$uids,$flag',true]]as$name=>[$box,$uids,$vars,$void])$s=nativeDraftReplaceBody($s,$name,static fn(string $body):string=>"\n        ".($void?'':'return ')."\\OCA\\Mail\\Snooze\\SnoozeHolds::lower(\$client,$box,$uids,function()use($vars){ $body\n        }".($name==='addFlag'?', strcasecmp($flag, "\\\\Deleted")===0':'').");\n    ");$files[$p]=$s;
    $p='lib/Service/SnoozeService.php';$s=$take($p);
    foreach(['snoozeMessage'=>['$srcAccount','$message,$unixTimestamp,$srcAccount,$srcMailbox,$dstAccount,$dstMailbox'],'snoozeThread'=>['$srcAccount','$selectedMessage,$unixTimestamp,$srcAccount,$srcMailbox,$dstAccount,$dstMailbox'],'wakeMessagesByAccount'=>['$account','$account']]as$name=>[$account,$vars])$s=nativeDraftReplaceBody($s,$name,static function(string $body)use($name,$account,$vars,$replace):string {
        $guard=$name==='snoozeMessage'?'\\OCA\\Mail\\Snooze\\SnoozeHolds::mailbox($db,(int)$srcMailbox->getId(),(int)$message->getUid());':'';
        if(in_array($name,['snoozeMessage','snoozeThread'],true))$guard.='\\OCA\\Mail\\Snooze\\SnoozeHolds::incoming($db,(int)$dstMailbox->getId());';
        if($name==='wakeMessagesByAccount')$body=$replace($body,'if (count($messages) === 0) {','$messages = array_values(array_filter($messages, static fn($m):bool => \\OCA\\Mail\\Snooze\\SnoozeHolds::permits($db,(int)$m->getMailboxId(),(int)$m->getUid())));' . "\n\t\t" . 'if (count($messages) === 0) {');
        return "\n        \\OCA\\Mail\\Snooze\\SnoozeHolds::runAccount($account,function(\\OCP\\IDBConnection \$db)use($vars){ $guard $body\n        });\n    ";
    });
    foreach(['unSnoozeMessage'=>['$message','$message,$currentUserId'],'unSnoozeThread'=>['$selectedMessage','$selectedMessage,$currentUserId'],'snoozeMessageDB'=>['$message','$message,$unixTimestamp,$srcMailbox'],'snoozeThreadDB'=>['$dstMailbox','$uids,$dstMailbox,$unixTimestamp,$srcMailbox']]as$name=>[$message,$vars]){
        $mailbox=$name==='snoozeThreadDB'?'$dstMailbox->getId()':$message.'->getMailboxId()';
        $s=nativeDraftReplaceBody($s,$name,static function(string $body)use($name,$mailbox,$message,$vars,$replace):string {
            $guard=in_array($name,['unSnoozeMessage','snoozeMessageDB'],true)?'\\OCA\\Mail\\Snooze\\SnoozeHolds::mailbox($db,(int)'.$mailbox.',(int)'.$message.'->getUid());':'';
            if($name==='snoozeThreadDB')$guard='foreach($uids as$mcpUid)\\OCA\\Mail\\Snooze\\SnoozeHolds::mailbox($db,(int)$dstMailbox->getId(),(int)$mcpUid);';
            if($name==='unSnoozeThread')$body=$replace($body,'foreach ($messages as $message) {','foreach ($messages as $mcpMessage) { \\OCA\\Mail\\Snooze\\SnoozeHolds::mailbox($db,(int)$snoozedMailbox->getId(),(int)$mcpMessage->getUid()); }' . "\n\t\t" . 'foreach ($messages as $message) {');
            return "\n        \$mcpBox=\$this->mailboxMapper->findById($mailbox);\$mcpOwner=\\OCA\\Mail\\Transmission\\DeliveryHolds::owner(\\OCP\\Server::get(\\OCP\\IDBConnection::class),(int)\$mcpBox->getAccountId());\$mcpAccount=\$this->accountService->find(\$mcpOwner,(int)\$mcpBox->getAccountId());\n        \\OCA\\Mail\\Snooze\\SnoozeHolds::runAccount(\$mcpAccount,function(\\OCP\\IDBConnection \$db)use($vars){ $guard $body\n        });\n    ";
        });
    }$files[$p]=$s;
    $p='lib/Db/MessageSnoozeMapper.php';$files[$p]=$replace($take($p),'class MessageSnoozeMapper extends QBMapper {', <<<'CODE'
class MessageSnoozeMapper extends QBMapper {
    public function insert(\OCP\AppFramework\Db\Entity $entity):\OCP\AppFramework\Db\Entity {
        $box=\OCP\Server::get(MailboxMapper::class)->findById((int)$entity->getMailboxId());$uid=\OCA\Mail\Transmission\DeliveryHolds::owner($this->db,(int)$box->getAccountId());
        return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->db)->runOwner($uid,function()use($entity){\OCA\Mail\Snooze\SnoozeHolds::mailbox($this->db,(int)$entity->getMailboxId(),(int)$entity->getUid());return parent::insert($entity);});
    }
CODE);
    return $files;
}
