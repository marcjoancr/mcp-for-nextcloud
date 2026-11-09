<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
final class MailNativeRefreshActor {
    public static function protocol(string $owner,int $accountId,string $mode):array {
        $p=proc_open([PHP_BINARY,__DIR__.'/refresh-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new \RuntimeException('No fixture server');fclose($pipes[0]);$port=json_decode(fgets($pipes[1]),true,8,JSON_THROW_ON_ERROR)['port'];$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($owner,$accountId);$e=clone $a->getMailAccount();$e->setInboundHost('127.0.0.1');$e->setInboundPort($port);$e->setInboundSslMode('none');$c=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient(new \OCA\Mail\Account($e),false);$ok=false;
        $f=new class implements \OCA\Mail\Service\RefreshFence {public function authorizeRead(array $b):bool{return true;}public function beforeCache(array $b,array $p):bool{throw new \LogicException('Collector cannot write cache');}public function recordCache(array $p):bool{throw new \LogicException('Collector cannot complete cache');}};
        try {$c->collectRefresh(['mailbox'=>'INBOX'],$owner,Server::get('OCA\\Mail\\IMAP\\MessageMapper'),$f,new \OCA\Mail\Sync\RefreshBudget());$ok=true;}catch(\RuntimeException|\Horde_Imap_Client_Exception){}finally{try{$c->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
        return ['accepted'=>$ok]+json_decode(trim($raw),true,16,JSON_THROW_ON_ERROR);
    }
}
