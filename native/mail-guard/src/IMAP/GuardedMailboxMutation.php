<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Service\MutationFence;
/** Closed folder primitives on the native connection; no account/message synchronization or implicit retry. */
trait GuardedMailboxMutation {
    private ?object $folderConnection=null;
    private bool $folderInvalid=false;
    private bool $folderFirstWrite=true;
    private string $folderCommand='';
    private ?array $folderSelection=null;
    private function folderRows(string $root,string $delimiter,bool $descendants):array {
        $patterns=[new \Horde_Imap_Client_Mailbox($root)];if($descendants)$patterns[]=$root.$delimiter.'*';$out=[];
        foreach($patterns as $pattern)foreach($this->listMailboxes($pattern,\Horde_Imap_Client::MBOX_ALL_SUBSCRIBED,['attributes'=>true,'delimiter'=>true]) as $b){
            $name=(string)$b['mailbox'];if($name!==$root&&(!$descendants||!str_starts_with($name,$root.$delimiter)))continue;
            if(($b['delimiter']??null)!==$delimiter||in_array('\\noselect',array_map('strtolower',$b['attributes']),true)||in_array('\\nonexistent',array_map('strtolower',$b['attributes']),true))throw new \RuntimeException('Folder is not a selectable native target.');
            $out[$name]=['name'=>$name,'subscribed'=>in_array('\\subscribed',array_map('strtolower',$b['attributes']),true),'attributes'=>$b['attributes']];if(count($out)>101)throw new \RuntimeException('Native subtree limit exceeded.');
        }
        ksort($out);return $out;
    }
    private function folderGeneration(string $name):string {
        $s=$this->status($name,\Horde_Imap_Client::STATUS_UIDVALIDITY|\Horde_Imap_Client::STATUS_FORCE_REFRESH);$g=(string)($s['uidvalidity']??'');
        if(!preg_match('/^[1-9][0-9]{0,9}$/D',$g)||(float)$g>4294967295)throw new \RuntimeException('Folder generation unavailable.');return $g;
    }
    public function mutateMailboxGuarded(array $r,MutationFence $fence):array {
        if($this->folderConnection!==null||$this->mutationSelection!==null||$this->moveSelection!==null)throw new \LogicException('Mutation already armed.');
        foreach($r['tree'] as $b)if(!is_string($b['generation'])||!preg_match('/^[1-9][0-9]{0,9}$/D',$b['generation'])||(float)$b['generation']>4294967295)throw new \RuntimeException('Approved folder generation unavailable. Synchronize in native Mail first.');
        $this->login();$old=[];$generations=[];$expected=[];foreach($r['tree'] as $b)$expected[$b['name']]=$b;
        if($r['action']!=='create_mailbox'||$r['tree']!==[]){
            $old=$this->folderRows($r['name'],$r['delimiter'],$r['action']==='rename_mailbox');
            if(array_diff_key($old,$expected)!==[]||array_diff_key($expected,$old)!==[])throw new \RuntimeException('Native descendant set changed.');
            foreach($old as $name=>$b){$g=$this->folderGeneration($name);if(($expected[$name]['generation']!==$g)||$b['subscribed']!==$expected[$name]['subscribed'])throw new \RuntimeException('Native folder state changed.');$generations[$name]=$g;}
            $this->openMailbox($r['name'],\Horde_Imap_Client::OPEN_READONLY);
            if((string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY)!==$generations[$r['name']]||$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDNOTSTICKY))throw new \RuntimeException('Native source identity changed.');
            $this->folderSelection=[$r['name'],$generations[$r['name']],$this->_mode];
        }
        if($r['action']!=='subscribe_mailbox'&&$this->folderRows($r['new_name'],$r['delimiter'],true)!==[])throw new \RuntimeException('Destination already exists.');
        if(!is_object($this->_connection))throw new \RuntimeException('Native connection unavailable.');$this->folderConnection=$this->_connection;$this->folderInvalid=false;$this->folderFirstWrite=true;
        try{
            if(!$fence->beforeWire($r))throw new \RuntimeException('Folder mutation admission revoked.');$outcome='';
            if($r['action']==='create_mailbox'){$this->folderCommand='CREATE';$this->_createMailbox(new \Horde_Imap_Client_Mailbox($r['new_name']),[]);$outcome='created';}
            elseif($r['action']==='rename_mailbox'){
                $this->folderCommand='RENAME';// Native _renameMailbox implicitly closes the source. Keep the verified read-only selection and never use CLOSE/expunge fallbacks.
                $this->_sendCmd($this->_command('RENAME')->add([$this->_getMboxFormatOb(new \Horde_Imap_Client_Mailbox($r['name'])),$this->_getMboxFormatOb(new \Horde_Imap_Client_Mailbox($r['new_name']))]));
                foreach($old as $name=>$b)if($b['subscribed']){
                    $this->folderCommand='UNSUBSCRIBE';$this->_subscribeMailbox(new \Horde_Imap_Client_Mailbox($name),false);
                    $this->folderCommand='SUBSCRIBE';$this->_subscribeMailbox(new \Horde_Imap_Client_Mailbox($r['new_name'].substr($name,strlen($r['name']))),true);
                }
                $outcome='renamed';
            }else{
                $outcome=$old[$r['name']]['subscribed']===$r['subscribed']?'already_desired':'subscribed';
                if($outcome!=='already_desired'){$this->folderCommand=$r['subscribed']?'SUBSCRIBE':'UNSUBSCRIBE';$this->_subscribeMailbox(new \Horde_Imap_Client_Mailbox($r['name']),$r['subscribed']);}
            }
            $this->folderCommand='READ';$rows=$this->folderRows($r['new_name'],$r['delimiter'],$r['action']==='rename_mailbox');$proof=[];$cache=[];
            $targets=$r['action']==='create_mailbox'?[$r['new_name']=>null]:[];if($r['action']!=='create_mailbox')foreach($old as $name=>$b)$targets[$r['action']==='rename_mailbox'?$r['new_name'].substr($name,strlen($r['name'])):$name]=$name;
            if(array_diff_key($rows,$targets)!==[]||array_diff_key($targets,$rows)!==[])throw new \RuntimeException('Native target subtree readback changed.');
            foreach($rows as $name=>$b){$g=$this->folderGeneration($name);$source=$targets[$name];if($source!==null&&($g!==$generations[$source]||$b['subscribed']!==($r['action']==='subscribe_mailbox'?$r['subscribed']:$old[$source]['subscribed'])))throw new \RuntimeException('Native folder readback changed.');$proof[]=['name'=>$name,'generation'=>$g,'subscribed'=>$b['subscribed']];$cache[$name]=$b['attributes'];}
            if($r['action']==='create_mailbox'&&$r['tree']!==[]&&$this->folderGeneration($r['name'])!==$generations[$r['name']])throw new \RuntimeException('Parent generation changed.');
            if($r['action']==='rename_mailbox'&&$this->folderRows($r['name'],$r['delimiter'],true)!==[])throw new \RuntimeException('Old namespace remains after rename.');
            $p=['outcome'=>$outcome,'action'=>$r['action'],'account_id'=>$r['account_id'],'mailbox_id'=>$r['mailbox_id'],'name'=>$r['new_name'],'folders'=>$proof];
            if(!$fence->recordRemote($p))throw new \RuntimeException('Folder proof not recorded.');return ['proof'=>$p,'attributes'=>$cache];
        }finally{$this->folderConnection=null;$this->folderSelection=null;$this->folderCommand='';$this->folderInvalid=false;}
    }
    private function guardMailboxCommand($cmd,$data):void {
        if($this->folderConnection===null||$cmd!==$data)return;
        if($this->folderInvalid||!$this->_isAuthenticated||$this->_connection!==$this->folderConnection)throw new \RuntimeException('Folder mutation connection invalidated.');
        $command=(string)$cmd->getCommand();
        if(in_array($command,['CREATE','RENAME','SUBSCRIBE','UNSUBSCRIBE'],true)){
            if($command!==$this->folderCommand)throw new \RuntimeException('Unexpected folder mutation command.');
            if($this->folderFirstWrite&&$this->folderSelection!==null){[$name,$generation,$mode]=$this->folderSelection;if((string)$this->_selected!==$name||(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY)!==$generation||$this->_mode!==$mode)throw new \RuntimeException('Folder selection changed before dispatch.');}
            $this->folderFirstWrite=false;$this->folderCommand='READ';
        }elseif(!in_array($command,['LIST','LSUB','STATUS','NOOP'],true))throw new \RuntimeException('Unexpected guarded folder command.');
    }
    private function invalidateMailboxMutation():void {if($this->folderConnection!==null)$this->folderInvalid=true;}
}
