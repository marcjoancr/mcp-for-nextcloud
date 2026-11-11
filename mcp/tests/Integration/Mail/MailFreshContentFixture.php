<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
/** Fresh synthetic messages obtain provenance exclusively through native IMAP synchronization. */
final class MailFreshContentFixture {
    public array $messages=[];
    public array $remoteUids=[];
    public string $mailboxName;
    public int $mailboxId;
    private object $account;
    private object $box;
    private array $original=[];
    public function __construct(private string $uid,array $subjects,string $thread) {
        $this->account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];
        $db=Server::get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$r=$q->select('m.*')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id=b.id')->where($q->expr()->eq('b.account_id',$q->createNamedParameter($this->account->getId())))->executeQuery();try{foreach($r->fetchAll()as$row)$this->original[(int)$row['id']]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));}finally{$r->closeCursor();}
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$this->box=$manager->createMailbox($this->account,'mcp-fresh-content-'.bin2hex(random_bytes(8)));$this->mailboxId=(int)$this->box->getId();$this->mailboxName=$this->box->getName();
        $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($this->account,false);$uids=[];
        try {
            foreach($subjects as$subject){
                $reply=$subject==='Flagged message';$messageId=$reply?'reply-'.$thread:$thread;
                $raw="From: sender@example.com\r\nTo: mcp-it@example.com\r\nSubject: $subject\r\nDate: Sat, 24 Oct 2026 10:00:00 +0000\r\nMessage-ID: <$messageId@example.test>\r\nMIME-Version: 1.0\r\n";
                if($reply)$raw.="References: <$thread@example.test>\r\nIn-Reply-To: <$thread@example.test>\r\n";
                if($subject==='Unread message'){
                    $raw.="Content-Type: multipart/mixed; boundary=\"fresh-content\"\r\n\r\n--fresh-content\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nBody of Unread message.\r\n--fresh-content\r\nContent-Type: application/octet-stream; name=\"proof.txt\"\r\nContent-Disposition: attachment; filename=\"proof.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode("Attachment bytes\r\nwith UTF-8: café\n")."\r\n--fresh-content--\r\n";
                }else $raw.="Content-Type: text/plain; charset=UTF-8\r\n\r\nBody of $subject.\r\n";
                $uids[$subject]=Server::get('OCA\\Mail\\IMAP\\MessageMapper')->save($client,$this->box,$raw,$subject==='Seen message'?['\\Seen']:[]);
            }
            // Native save intentionally marks appended mail Seen; prepare unread synthetic inputs on the server before sync.
            foreach($uids as$subject=>$remote)if($subject!=='Seen message')$client->store($this->box->getName(),['ids'=>new \Horde_Imap_Client_Ids([$remote]),'remove'=>[\Horde_Imap_Client::FLAG_SEEN]]);
            $this->remoteUids=$uids;
            Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer')->sync($this->account,$client,$this->box,Server::get(\Psr\Log\LoggerInterface::class));
            foreach($uids as$subject=>$remote){$m=Server::get('OCA\\Mail\\Db\\MessageMapper')->findByUids($this->box,[$remote])[0];$this->messages[$subject]=(int)$m->getId();if(!Server::get('OCA\\Mail\\Db\\TagMapper')->exactAssociations()->verified($uid,(int)$m->getId()))throw new \RuntimeException('Native sync did not establish fresh provenance.');}
            $this->assertOriginals();
        }catch(\Throwable $e){$client->logout();$manager->deleteMailbox($this->account,$this->box);throw $e;}finally{$client->logout();}
    }
    private function assertOriginals():void {
        if($this->original===[])return;$db=Server::get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$r=$q->select('*')->from('mail_messages')->where($q->expr()->in('id',$q->createNamedParameter(array_keys($this->original),\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        if(count($rows)!==count($this->original))throw new \RuntimeException('Original synthetic cache inventory changed.');foreach($rows as$row)if($this->original[(int)$row['id']]!==hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)))throw new \RuntimeException('Original synthetic cache bytes changed.');
    }
    public function close():void {Server::get('OCA\\Mail\\Service\\MailManager')->deleteMailbox($this->account,$this->box);$this->assertOriginals();}
}
