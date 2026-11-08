<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Service\MutationFence;
/** Native-only extension: all protected Horde interactions stay inside Mail's existing client. */
trait GuardedFlagMutation {
    private ?MutationSelection $mutationSelection=null;
    private string $mutationCommand='';
    private int $mutationUid=0;
    /** This method never exposes the private client to a caller's callback. */
    public function setFlagGuarded(array $request,MutationFence $fence): array {
        if($this->mutationSelection!==null)throw new \LogicException('Mutation already armed.');
        if(!in_array($request['flag']??null,['seen','flagged'],true)||!is_bool($request['value']??null)||!preg_match('/^[1-9][0-9]*$/D',$request['uid']??'')||!preg_match('/^[1-9][0-9]*$/D',$request['generation']??''))throw new \InvalidArgumentException('Invalid flag target.');
        $name=$request['mailbox'];$uid=(int)$request['uid'];
        if((string)$uid!==$request['uid'])throw new \InvalidArgumentException('Invalid UID.');
        $this->openMailbox($name,\Horde_Imap_Client::OPEN_READWRITE);
        $generation=(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY);
        if($generation!==$request['generation']||$this->_mode!==\Horde_Imap_Client::OPEN_READWRITE||!is_object($this->_connection))throw new \RuntimeException('Selection changed before mutation.');
        $permit=new MutationSelection();$permit->arm($this->_connection,$name,$generation,$this->_mode,$uid);
        $this->mutationSelection=$permit;$this->mutationUid=$uid;
        $flag=$request['flag']==='seen'?\Horde_Imap_Client::FLAG_SEEN:\Horde_Imap_Client::FLAG_FLAGGED;
        try {
            $old=$this->guardedFlagRead($name,$uid,$flag);
            if(!$fence->beforeWire($request))throw new \RuntimeException('Mutation admission revoked.');
            $this->assertMutationSelection();
            if($old!==$request['value']){
                $this->mutationCommand='UID STORE';
                $options=['ids'=>new \Horde_Imap_Client_Ids([$uid]),$request['value']?'add':'remove'=>[$flag]];
                // Unlike public store(), this does not swallow a tagged server NO or issue a follow-up NOOP.
                $this->_sendCmd($this->_storeCmd($options));
                $this->assertMutationSelection();
            }
            if($this->guardedFlagRead($name,$uid,$flag)!==$request['value'])throw new \RuntimeException('Desired flag state not verified.');
            $proof=['outcome'=>$old===$request['value']?'already_desired':'verified','message_id'=>$request['message_id'],
                'mailbox_id'=>$request['mailbox_id'],'generation'=>$generation,'uid'=>$request['uid'],'flag'=>$request['flag'],'value'=>$request['value']];
            if(!$fence->recordRemote($proof))throw new \RuntimeException('Remote proof could not be recorded.');
            return $proof;
        } finally {$permit->invalidate();$this->mutationSelection=null;$this->mutationCommand='';$this->mutationUid=0;}
    }
    private function guardedFlagRead(string $name,int $uid,string $flag): bool {
        $this->mutationCommand='UID FETCH';
        $q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$q->flags();
        $m=$this->fetch($name,$q,['ids'=>new \Horde_Imap_Client_Ids([$uid])])->first();
        $this->assertMutationSelection();
        if($m===null||$m->getUid()!==$uid)throw new \RuntimeException('Exact UID unavailable.');
        return in_array(strtolower($flag),array_map('strtolower',$m->getFlags()),true);
    }
    private function assertMutationSelection(): void {
        if(!$this->_isAuthenticated||!is_object($this->_connection)||$this->mutationSelection===null||!$this->mutationSelection->check($this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,$this->mutationUid))throw new \RuntimeException('Mutation selection invalidated.');
    }
    protected function _processCmd($pipeline,$cmd,$data) {
        if($this->mutationSelection!==null&&$cmd===$data){
            $this->assertMutationSelection();
            if((string)$cmd->getCommand()!==$this->mutationCommand)throw new \RuntimeException('Unexpected guarded command.');
            if($this->mutationCommand==='UID STORE'&&!$this->mutationSelection->startWire($this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,$this->mutationUid))throw new \RuntimeException('Mutation wire permit already consumed.');
        }
        return parent::_processCmd($pipeline,$cmd,$data);
    }
    protected function _logout() {
        $this->mutationSelection?->invalidate();
        return parent::_logout();
    }
}
