<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
/** Pinned Horde protected protocol API, deliberately bypassing _append's BINARY/disconnect retries. */
trait SingleAppend {
    private ?CopyBudget $mcpCopyBudget=null;
    private ?object $mcpCopyConnection=null;
    private ?array $mcpCopySelection=null;
    public function mcpBeginCopy(int $artifactBytes):void {
        if($this->_isAuthenticated||$this->_connection!==null||$this->mcpCopyBudget!==null)throw new \RuntimeException('Sent copy requires a fresh private connection.');
        $this->mcpCopyBudget=new CopyBudget($artifactBytes);$this->_params['mcp_refresh_budget']=$this->mcpCopyBudget;$this->_params['timeout']=2;$this->_params['read_timeout']=2;
    }
    public function mcpExpectCopyValidity(int $validity):void {if($this->mcpCopyBudget===null)throw new \RuntimeException('Copy budget missing.');$this->mcpCopyBudget->expectValidity($validity);}
    private function mcpCopyState():array {return [$this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,(bool)$this->_isAuthenticated];}
    private function mcpAssertCopyConnection():void {if($this->mcpCopyConnection===null||$this->_connection!==$this->mcpCopyConnection||!$this->_isAuthenticated||!$this->_connection->connected)throw new \RuntimeException('Sent copy connection changed.');}
    private function mcpAssertCopySelection():void {$this->mcpAssertCopyConnection();if($this->mcpCopySelection===null||$this->mcpCopySelection!==$this->mcpCopyState())throw new \RuntimeException('Sent copy selection changed.');}
    public function mcpSelectCopy(string $mailbox,int $validity,int $uid):void {
        if($this->mcpCopySelection!==null||$this->mcpCopyBudget===null)throw new \RuntimeException('Sent copy selection already used.');$this->mcpAssertCopyConnection();
        $this->openMailbox($mailbox,\Horde_Imap_Client::OPEN_READONLY);$this->mcpAssertCopyConnection();$state=$this->mcpCopyState();
        if($state[1]!==$mailbox||$state[2]!== (string)$validity||$state[3]!==\Horde_Imap_Client::OPEN_READONLY)throw new \RuntimeException('Selected Sent mailbox identity changed.');
        $this->mcpCopySelection=$state;$this->mcpCopyBudget->expectUid($uid);
    }
    public function mcpVerifyCopySelection():int {$this->mcpAssertCopySelection();$this->mcpCopyBudget->assertReadback();return (int)$this->mcpCopySelection[2];}
    private function mcpGuardCopyCommand($command,$data):void {
        if($this->mcpCopyBudget===null||$command!==$data)return;$name=(string)$command->getCommand();
        if($this->mcpCopyConnection!==null)$this->mcpAssertCopyConnection();
        if($this->mcpCopySelection!==null){$this->mcpAssertCopySelection();if(!in_array($name,['UID FETCH','LOGOUT'],true))throw new \RuntimeException('Sent copy selection cannot reopen or change.');}
        $this->mcpCopyBudget->command($name);
    }

    public function mcpAppendFrozen(string $mailbox,string $bytes):\Horde_Imap_Client_Ids {
        if($this->mcpCopyBudget===null)throw new \RuntimeException('Sent copy budget required.');
        $this->login();
        if(!$this->queryCapability('UIDPLUS'))throw new \RuntimeException('Positive APPENDUID support is required.');
        $data=new \Horde_Imap_Client_Data_Format_String_Nonascii($bytes,['eol'=>true,'skipscan'=>true]);$data->forceLiteral();
        $command=$this->_command('APPEND')->add($this->_getMboxFormatOb(\Horde_Imap_Client_Mailbox::get($mailbox)))->add(new \Horde_Imap_Client_Data_Format_List([\Horde_Imap_Client::FLAG_SEEN]))->add($data);
        $command->literal8=false;$command->literalplus=false;$this->close();
        $response=$this->_sendCmd($command);$ids=$response->data['appenduid']??null;
        // No Message-ID fallback search and no automatic create/encoding/reconnect retry.
        if(!$ids instanceof \Horde_Imap_Client_Ids||count($ids)!==1||(int)$ids->current()<=0)throw new \RuntimeException('Positive APPENDUID evidence unavailable.');$this->mcpCopyConnection=$this->_connection;$this->mcpAssertCopyConnection();return $ids;
    }
}
