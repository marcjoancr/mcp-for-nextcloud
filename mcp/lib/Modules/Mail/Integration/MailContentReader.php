<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\{IConfig,IDBConnection};
/** Closed optional native adapter. Byte-bearing arrays are only consumed by explicit private adapters. */
class MailContentReader {
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private IConfig $config,private IDBConnection $db,private ConnectionContext $connection,private \Closure $authorize){}
    public function mustUseBounded():bool {return $this->boundary->currentContentGeneration();}
    public function available(string $uid):bool {return $this->services->isEnabledFor($uid)&&$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))&&$this->db->getDatabaseProvider()===IDBConnection::PLATFORM_POSTGRES&&$this->boundary->deliveryVerified();}
    private function native(string $uid):object {if(!$this->available($uid))throw new ToolFailure(MailMessages::contentUnavailable());return $this->services->guardedContentReader($uid);}
    private function fence(string $uid,string $tool):object {
        if(!in_array($tool,['mail_export_message','mail_save_attachment','mail_read_attachment','mail_read_message','mail_read_thread','mail_write_draft'],true))throw new ToolFailure(MailMessages::messageChanged());
        $connection=$this->connection->current();$check=function(string $owner,int $account,int $mailbox,int $message)use($uid,$tool,$connection):void {
            if($owner!==$uid||$this->connection->current()!==$connection||!($this->authorize)($uid,$connection,$tool,$account,$mailbox,$message))throw new ToolFailure(MailMessages::messageChanged());
        };
        return new class($check){public function __construct(private \Closure $check){}public function authorize(string $uid,int $account,int $mailbox,int $message):void {($this->check)($uid,$account,$mailbox,$message);}};
    }
    public function readBody(string $uid,int $message,string $tool):array {
        try{return $this->native($uid)->readBody($uid,$message,$this->fence($uid,$tool));}
        catch(\RuntimeException $e){if($e->getCode()===413)throw new MailBodyTooLargeException(1048577,true);throw new ToolFailure(MailMessages::contentReadUnavailable());}
        catch(\Throwable){throw new ToolFailure(MailMessages::contentReadUnavailable());}
    }
    public function readRaw(string $uid,int $message):array {try{return $this->verified($this->native($uid)->readRaw($uid,$message,$this->fence($uid,'mail_export_message')));}catch(\Throwable){throw new ToolFailure(MailMessages::contentReadUnavailable());}}
    public function listParts(string $uid,int $message,string $tool='mail_read_message'):array {try{return $this->native($uid)->listParts($uid,$message,$this->fence($uid,$tool));}catch(\Throwable){throw new ToolFailure(MailMessages::contentReadUnavailable());}}
    public function readPart(string $uid,int $message,string $part,string $tool='mail_read_attachment'):array {try{return $this->verified($this->native($uid)->readPart($uid,$message,$part,$this->fence($uid,$tool)));}catch(\Throwable){throw new ToolFailure(MailMessages::contentReadUnavailable());}}
    public function assertSource(string $uid,array $source,string $tool):void {try{$this->native($uid)->assertSource($uid,$source,$this->fence($uid,$tool));}catch(\Throwable){throw new ToolFailure(MailMessages::messageChanged());}}
    private function verified(array $row):array {
        if(!is_string($row['bytes']??null)||strlen($row['bytes'])>26214400||($row['byte_count']??null)!==strlen($row['bytes'])||!is_string($row['sha256']??null)||!hash_equals($row['sha256'],hash('sha256',$row['bytes'])))throw new ToolFailure(MailMessages::contentReadUnavailable());
        return $row;
    }
    public static function stableSource(array $source):array {return array_intersect_key($source,array_flip(['message_id','account_id','mailbox_id','uid','uidvalidity','profile_revision','auth_revision','mailbox_revision']));}
}
