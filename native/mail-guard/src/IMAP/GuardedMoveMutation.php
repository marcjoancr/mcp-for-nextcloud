<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Service\MutationFence;
/** Single UID MOVE on the existing native connection. No COPY/delete fallback or heuristic UID lookup. */
trait GuardedMoveMutation {
    private ?MutationSelection $moveSelection=null;
    private string $moveCommand='';
    private ?\Horde_Imap_Client_Interaction_Pipeline $movePipeline=null;
    private ?array $moveMapping=null;
    private array $moveRequest=[];
    public function moveGuarded(array $r,MutationFence $fence):array {
        if($this->mutationSelection!==null||$this->moveSelection!==null)throw new \LogicException('Mutation already armed.');
        foreach(['uid','generation','destination_generation'] as $key)if(!is_string($r[$key]??null)||preg_match('/^[1-9][0-9]{0,9}$/D',$r[$key])!==1||(float)$r[$key]>4294967295)throw new \InvalidArgumentException('Invalid MOVE identity.');
        if(!is_string($r['mailbox']??null)||!is_string($r['destination_mailbox']??null)||$r['mailbox']===$r['destination_mailbox'])throw new \InvalidArgumentException('Invalid MOVE destination.');
        $this->login();
        if(!$this->_capability('MOVE')||!$this->_capability('UIDPLUS'))throw new \RuntimeException('MOVE and UIDPLUS are required.');
        $this->openMailbox($r['destination_mailbox'],\Horde_Imap_Client::OPEN_READONLY);
        if((string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY)!==$r['destination_generation']||$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDNOTSTICKY))throw new \RuntimeException('Destination generation is not stable.');
        $this->openMailbox($r['mailbox'],\Horde_Imap_Client::OPEN_READWRITE);
        $permit=new MutationSelection();
        if(!is_object($this->_connection)||$this->_mode!==\Horde_Imap_Client::OPEN_READWRITE)throw new \RuntimeException('Source is not writable.');
        $permit->arm($this->_connection,$r['mailbox'],$r['generation'],$this->_mode,(int)$r['uid']);
        $this->moveSelection=$permit;$this->moveRequest=$r;
        try {
            $this->assertMoveSelection();$this->moveCommand='UID FETCH';
            $q=new \Horde_Imap_Client_Fetch_Query();$q->uid();
            $m=$this->fetch($r['mailbox'],$q,['ids'=>new \Horde_Imap_Client_Ids([(int)$r['uid']])])->first();
            $this->assertMoveSelection();if($m===null||$m->getUid()!==(int)$r['uid'])throw new \RuntimeException('Source UID unavailable.');
            $this->moveDestinationGeneration();
            if(!$fence->beforeWire($r))throw new \RuntimeException('MOVE admission revoked.');
            $this->assertMoveSelection();$this->moveCommand='UID MOVE';
            $this->movePipeline=$this->_pipeline($this->_command('UID MOVE')->add([$r['uid'],$this->_getMboxFormatOb(new \Horde_Imap_Client_Mailbox($r['destination_mailbox']))]));
            $this->_sendCmd($this->movePipeline);
            $this->assertMoveSelection();
            if($this->moveMapping===null)throw new \RuntimeException('MOVE lacks COPYUID proof.');
            $this->moveDestinationGeneration();
            $proof=['outcome'=>'moved','message_id'=>$r['message_id'],'mailbox_id'=>$r['mailbox_id'],'generation'=>$r['generation'],'uid'=>$r['uid'],
                'destination_mailbox_id'=>$r['destination_mailbox_id'],'destination_generation'=>$r['destination_generation'],'destination_uid'=>$this->moveMapping['destination_uid'],'action'=>$r['action']];
            if(!$fence->recordRemote($proof))throw new \RuntimeException('MOVE proof was not recorded.');
            return $proof;
        } finally {$permit->invalidate();$this->moveSelection=null;$this->movePipeline=null;$this->moveMapping=null;$this->moveCommand='';$this->moveRequest=[];}
    }
    private function moveDestinationGeneration():void {
        $this->moveCommand='STATUS';
        $s=$this->status($this->moveRequest['destination_mailbox'],\Horde_Imap_Client::STATUS_UIDVALIDITY|\Horde_Imap_Client::STATUS_FORCE_REFRESH);
        $this->assertMoveSelection();
        if((string)($s['uidvalidity']??'')!==$this->moveRequest['destination_generation'])throw new \RuntimeException('Destination generation changed.');
    }
    private function assertMoveSelection():void {
        if(!$this->_isAuthenticated||!is_object($this->_connection)||$this->moveSelection===null||$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDNOTSTICKY)||!$this->moveSelection->check($this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,(int)$this->moveRequest['uid']))throw new \RuntimeException('MOVE selection invalidated.');
    }
    private function guardMoveCommand($pipeline,$cmd,$data):void {
        if($this->moveSelection===null||$cmd!==$data)return;
        $this->assertMoveSelection();
        if((string)$cmd->getCommand()!==$this->moveCommand)throw new \RuntimeException('Unexpected guarded MOVE command.');
        if($this->moveCommand==='UID MOVE'&&($pipeline!==$this->movePipeline||!$this->moveSelection->startWire($this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,(int)$this->moveRequest['uid'])))throw new \RuntimeException('MOVE permit consumed.');
    }
    protected function _responseCode(\Horde_Imap_Client_Interaction_Pipeline $pipeline,\Horde_Imap_Client_Interaction_Server $ob) {
        if(($ob->responseCode->code??null)==='COPYUID'&&$this->moveSelection!==null){
            // Intercept before Horde expands arbitrary UID ranges or updates its cache from an unverified map.
            if($pipeline!==$this->movePipeline||$ob->status!==\Horde_Imap_Client_Interaction_Server::OK||$this->moveMapping!==null)throw new \RuntimeException('Unexpected COPYUID.');
            $this->moveMapping=MoveProof::copyUid($ob->responseCode->data,$this->moveRequest['uid'],$this->moveRequest['destination_generation']);
            if($this->moveMapping===null)throw new \RuntimeException('COPYUID did not prove the approved MOVE.');
            return;
        }
        return parent::_responseCode($pipeline,$ob);
    }
}
