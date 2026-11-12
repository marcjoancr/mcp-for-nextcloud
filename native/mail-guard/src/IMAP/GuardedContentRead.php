<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Content\{ContentBudget,TransferDecoder};
/** Fresh selected read-only transport. Protected pipeline deliberately excludes public fetch retries. */
trait GuardedContentRead {
    private ?ContentBudget $mcpContentBudget=null;
    private ?object $mcpContentConnection=null,$mcpContentFence=null;
    private ?array $mcpContentSelection=null;
    private int $mcpContentUid=0,$mcpContentValidity=0;
    private bool $mcpContentClosed=false;
    public function mcpBeginContent(int $validity,int $uid,object $fence):void {
        if($this->_isAuthenticated||$this->_connection!==null||isset($this->_params['mcp_refresh_budget']))throw new \RuntimeException('Content requires a fresh private connection.');
        $this->mcpContentBudget=new ContentBudget($validity,$uid);$this->mcpContentFence=$fence;$this->mcpContentUid=$uid;$this->mcpContentValidity=$validity;
        $this->_params['mcp_refresh_budget']=$this->mcpContentBudget;$this->_params['timeout']=2;$this->_params['read_timeout']=2;
        $this->_params['capability_ignore']=['ESEARCH','SEARCHRES','CONTEXT','QRESYNC','CONDSTORE','SORT','THREAD'];
    }
    private function mcpContentState():array {return [$this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,(bool)$this->_isAuthenticated];}
    private function mcpAssertContentConnection():void {if($this->mcpContentClosed||$this->mcpContentConnection===null||$this->_connection!==$this->mcpContentConnection||!$this->_isAuthenticated||!$this->_connection->connected)throw new \RuntimeException('Content connection changed.');}
    private function mcpAssertContentSelection():void {$this->mcpAssertContentConnection();if($this->mcpContentSelection===null||$this->mcpContentSelection!==$this->mcpContentState())throw new \RuntimeException('Content selection changed.');}
    public function mcpSelectContent(string $mailbox):void {
        if($this->mcpContentBudget===null||$this->mcpContentSelection!==null||$this->mcpContentConnection!==null)throw new \RuntimeException('Content selection already used.');
        $this->login();$this->mcpContentConnection=$this->_connection;$this->mcpAssertContentConnection();
        $this->openMailbox($mailbox,\Horde_Imap_Client::OPEN_READONLY);$this->mcpAssertContentConnection();$state=$this->mcpContentState();
        if($state[1]!==$mailbox||$state[2]!== (string)$this->mcpContentValidity||$state[3]!==\Horde_Imap_Client::OPEN_READONLY)throw new \RuntimeException('Selected content identity changed.');$this->mcpContentSelection=$state;
    }
    private function mcpGuardContentCommand($command,$data):void {
        if($this->mcpContentBudget===null||$command!==$data)return;$name=(string)$command->getCommand();
        if($this->mcpContentClosed)throw new \RuntimeException('Content session is closed.');
        if($this->mcpContentConnection!==null)$this->mcpAssertContentConnection();
        if($this->mcpContentSelection!==null){$this->mcpAssertContentSelection();if(!in_array($name,['UID FETCH','LOGOUT'],true))throw new \RuntimeException('Content cannot reselect or retry.');}
        $this->mcpContentBudget->command($name);$this->mcpContentFence->authorize();
    }
    public function mcpVerifyContent():void {$this->mcpAssertContentSelection();$this->mcpContentBudget->assertFetch();$this->mcpContentFence->authorize();}
    public function mcpEndContent():void {try{$this->logout();}finally{$this->mcpContentClosed=true;}}
    private function mcpFetchContent(string $mode,string $section=''):object {
        $this->mcpAssertContentSelection();$this->mcpContentBudget->expect($mode,$section);
        $query=new \Horde_Imap_Client_Fetch_Query();
        match($mode){'raw'=>$query->fullText(['peek'=>true]),'manifest'=>$query->structure(),'flags'=>$query->flags(),'part'=>$query->bodyPart($section,['peek'=>true]),'header'=>$section==='HEADER'?$query->headerText(['peek'=>true]):$query->mimeHeader(substr($section,0,-5),['peek'=>true])};
        $pipeline=$this->_pipeline();$pipeline->data['fetch_lookup']=[];$pipeline->data['fetch_followup']=[];
        $this->_fetchCmd($pipeline,['_query'=>$query,'ids'=>new \Horde_Imap_Client_Ids([$this->mcpContentUid])]);
        $response=$this->_sendCmd($pipeline);$this->mcpVerifyContent();
        if(!empty($response->data['fetch_followup'])||count($response->fetch)!==1)throw new \RuntimeException('Unexpected content fetch followup.');
        foreach($response->fetch as $row){if((int)$row->getUid()!==$this->mcpContentUid)throw new \RuntimeException('Content UID proof missing.');return $row;}
        throw new \RuntimeException('Content fetch missing.');
    }
    public function mcpContentRaw():array {$row=$this->mcpFetchContent('raw');$stream=$row->getFullMsg(true);try{$result=TransferDecoder::decode($stream,'binary',$this->mcpContentBudget);}finally{if(is_resource($stream))fclose($stream);}$this->mcpVerifyContent();return $result;}
    public function mcpContentManifest():object {$row=$this->mcpFetchContent('manifest');return $row->getStructure();}
    public function mcpContentFlags():array {return $this->mcpFetchContent('flags')->getFlags();}
    public function mcpContentHeader(string $partId):string {$section=$partId===''?'HEADER':$partId.'.MIME';$row=$this->mcpFetchContent('header',$section);$text=$partId===''?$row->getHeaderText():$row->getMimeHeader($partId);if(!is_string($text)||strlen($text)>16384)throw new \RuntimeException('Content headers exceed bound.');return $text;}
    public function mcpContentDisplayBudget():void {$this->mcpAssertContentSelection();$this->mcpContentFence->authorize();$this->mcpContentBudget->displayText();}
    public function mcpContentPart(string $partId,string $encoding):array {$row=$this->mcpFetchContent('part',$partId);if($row->getBodyPartDecode($partId)!==null)throw new \RuntimeException('Implicit content decoding refused.');$stream=$row->getBodyPart($partId,true);try{$result=TransferDecoder::decode($stream,$encoding,$this->mcpContentBudget);}finally{if(is_resource($stream))fclose($stream);}$this->mcpVerifyContent();return $result;}
}
