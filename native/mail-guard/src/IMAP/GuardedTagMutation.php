<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Service\MutationFence;
/** One selected connection, UID and canonical keyword group. No system flags or generic native options. */
trait GuardedTagMutation {
    private ?MutationSelection $tagSelection=null;
    private string $tagCommand='';
    private int $tagUid=0;
    private string $tagKeyword='';
    public function setTagGuarded(array $r,MutationFence $fence):array {
        if($this->tagSelection!==null||$this->mutationSelection!==null)throw new \RuntimeException('A mutation is already armed.');
        if(!TagKeywords::valid($r['imap_label']??null)||!is_bool($r['value']??null)||preg_match('/^[1-9][0-9]{0,9}$/D',$r['uid']??'')!==1||(float)$r['uid']>4294967295||preg_match('/^[1-9][0-9]{0,9}$/D',$r['generation']??'')!==1)throw new \InvalidArgumentException('Invalid exact keyword target.');
        $name=$r['mailbox'];$uid=(int)$r['uid'];$this->openMailbox($name,\Horde_Imap_Client::OPEN_READWRITE);$g=(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY);
        if($g!==$r['generation']||!$this->_isAuthenticated||$this->_mode!==\Horde_Imap_Client::OPEN_READWRITE||!is_object($this->_connection))throw new \RuntimeException('Keyword source selection changed.');
        $this->tagKeyword=$r['imap_label'];$this->requirePermanentKeyword($this->tagKeyword);$permit=new MutationSelection();$permit->arm($this->_connection,$name,$g,$this->_mode,$uid);$this->tagSelection=$permit;$this->tagUid=$uid;
        try {
            $old=$this->tagFlags($name,$uid);$forms=TagKeywords::forms($r['imap_label']);$present=array_intersect($old,$forms)!==[];$expected=$old;
            if($present!==$r['value']){if($r['value'])$expected[]=$forms[0];else{$expected=array_values(array_diff($old,$forms));foreach(array_intersect($old,$forms)as$keyword)$this->requirePermanentKeyword($keyword);}sort($expected);}
            if(!$fence->beforeWire($r))throw new \RuntimeException('Keyword admission revoked.');$this->assertTagMutation();
            if($present!==$r['value']){$this->tagCommand='UID STORE';$options=['ids'=>new \Horde_Imap_Client_Ids([$uid]),$r['value']?'add':'remove'=>$r['value']?[$forms[0]]:$forms];$this->_sendCmd($this->_storeCmd($options));$this->assertTagMutation();}
            if($this->tagFlags($name,$uid)!==$expected)throw new \RuntimeException('Keyword or unrelated flags could not be verified.');
            $proof=['outcome'=>$present===$r['value']?'already_desired':'verified','message_id'=>$r['message_id'],'mailbox_id'=>$r['mailbox_id'],'generation'=>$g,'uid'=>$r['uid'],'tag_id'=>$r['tag_id'],'value'=>$r['value']];
            if(!$fence->recordRemote($proof))throw new \RuntimeException('Keyword proof could not be recorded.');return $proof;
        }finally{$permit->invalidate();$this->tagSelection=null;$this->tagCommand='';$this->tagUid=0;$this->tagKeyword='';}
    }
    private function tagFlags(string $mailbox,int $uid):array {
        $this->tagCommand='UID FETCH';$q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$q->flags();$rows=$this->fetch($mailbox,$q,['ids'=>new \Horde_Imap_Client_Ids([$uid])]);$this->assertTagMutation();$m=$rows->first();if(count($rows)!==1||$m===null||$m->getUid()!==$uid)throw new \RuntimeException('Exact keyword UID unavailable.');$flags=array_values(array_unique(array_map('strtolower',$m->getFlags())));sort($flags);return $flags;
    }
    private function requirePermanentKeyword(string $keyword):void {
        $permanent=$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_PERMFLAGS);if(!is_array($permanent))throw new \RuntimeException('Keyword persistence unavailable.');$flags=array_map('strtolower',$permanent);
        if(!in_array('\\*',$flags,true)&&!in_array(strtolower($keyword),$flags,true))throw new \RuntimeException('The selected keyword is not proven permanent.');
    }
    private function assertTagMutation():void {
        $this->requirePermanentKeyword($this->tagKeyword);if(!$this->_isAuthenticated||!is_object($this->_connection)||$this->tagSelection===null||!$this->tagSelection->check($this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,$this->tagUid))throw new \RuntimeException('Keyword selection invalidated.');
    }
    private function guardTagCommand($cmd,$data):void {
        if($this->tagSelection!==null&&$cmd===$data){$this->assertTagMutation();if((string)$cmd->getCommand()!==$this->tagCommand)throw new \RuntimeException('Unexpected guarded keyword command.');if($this->tagCommand==='UID STORE'&&!$this->tagSelection->startWire($this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,$this->tagUid))throw new \RuntimeException('Keyword wire permit consumed.');}
    }
}
