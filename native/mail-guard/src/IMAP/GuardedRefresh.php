<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Service\RefreshFence;
use OCA\Mail\Sync\RefreshBudget;
/** Closed read-only native collection; no generic callback or protocol options leave Mail. */
trait GuardedRefresh {
    private ?RefreshBudget $refreshBudget=null;
    private ?RefreshFence $refreshFence=null;
    private array $refreshBinding=[];
    private ?array $refreshSelection=null;
    private ?string $refreshEpoch=null;
    public function collectRefresh(array $r,string $owner,MessageMapper $mapper,RefreshFence $fence,RefreshBudget $budget):array {
        if($this->_isAuthenticated||$this->_connection!==null||$this->refreshBudget!==null)throw new \RuntimeException('Refresh requires a new private connection.');
        $this->refreshBudget=$budget;$this->refreshFence=$fence;$this->refreshBinding=$r;
        $this->_params['mcp_refresh_budget']=$budget;$this->_params['capability_ignore']=['ESEARCH','SEARCHRES','CONTEXT','QRESYNC','SORT','THREAD'];$this->_params['timeout']=2;$this->_params['read_timeout']=2;
        $this->_init['noesearch']=true;$this->login();if(!$this->_capability('CONDSTORE'))throw new \RuntimeException('Refresh requires modification sequences.');
        $name=$r['mailbox'];$this->openMailbox($name,\Horde_Imap_Client::OPEN_READONLY);$this->refreshSelection=[$this->_connection,(string)$this->_selected,$this->_mode];$this->refreshEpoch=(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY);
        if($this->_mode!==\Horde_Imap_Client::OPEN_READONLY)throw new \RuntimeException('Refresh selection is writable.');
        $before=$this->refreshStatus($name);$uids=$this->refreshUids($name);if(count($uids)!==$before['messages'])throw new \RuntimeException('Refresh remote count changed.');
        $imap=$uids===[]?[]:$mapper->findByIds($this,$name,new \Horde_Imap_Client_Ids($uids),$owner,false,false);$actual=array_map(static fn($m):int=>$m->getUid(),$imap);sort($actual,SORT_NUMERIC);if($actual!==$uids)throw new \RuntimeException('Refresh fetch was incomplete.');
        $afterUids=$this->refreshUids($name);$after=$this->refreshStatus($name);if($uids!==$afterUids||$before!==$after)throw new \RuntimeException('Refresh snapshot changed during observation.');$budget->check();
        return ['status'=>$before,'messages'=>$imap,'observed_at'=>time()];
    }
    private function refreshStatus(string $name):array {
        // Do not use status(FORCE_REFRESH) on a selected mailbox: it may reuse cached values after NOOP.
        $fields=['uidvalidity','uidnext','messages','highestmodseq'];foreach($fields as$f)$this->_mailboxOb()->setStatus($this->_statusFields[$f],null);
        $this->_sendCmd($this->_command('STATUS')->add([$this->_getMboxFormatOb($name),new \Horde_Imap_Client_Data_Format_List(array_map('strtoupper',$fields))]));$s=[];
        foreach($fields as$f){$v=$this->_mailboxOb()->getStatus($this->_statusFields[$f]);$v=is_int($v)?(string)$v:$v;if(!is_string($v)||preg_match('/^[0-9]{1,20}$/D',$v)!==1||($f!=='messages'&&($v==='0'||$v[0]==='0')))throw new \RuntimeException('Refresh status is not proven.');$s[$f]=$f==='messages'?(int)$v:$v;}
        if($s['uidvalidity']!==$this->refreshEpoch)throw new \RuntimeException('Refresh selected epoch changed.');
        if($s['messages']>RefreshBudget::MAX_MESSAGES||(float)$s['uidvalidity']>4294967295||(float)$s['uidnext']>4294967295)throw new \RuntimeException('Refresh remote mailbox exceeds its bound.');return $s;
    }
    private function refreshUids(string $name):array {$q=new \Horde_Imap_Client_Search_Query();if((string)$this->_selected!==$name)throw new \RuntimeException('Refresh UID selection changed.');/* Base::search skips the wire when cached MESSAGES is zero. Never trust that shortcut here. */$s=$this->_search($q,['_query'=>$q->build($this),'results'=>[\Horde_Imap_Client::SEARCH_RESULTS_MATCH],'sequence'=>false]);$ids=$s['match']->ids;if(count($ids)>RefreshBudget::MAX_MESSAGES)throw new \RuntimeException('Refresh UID budget exhausted.');$ids=array_map('intval',$ids);sort($ids,SORT_NUMERIC);if(count(array_unique($ids))!==count($ids))throw new \RuntimeException('Duplicate refresh UID.');return $ids;}
    private function guardRefreshCommand($cmd,$data):void {
        if($this->refreshBudget===null||$cmd!==$data)return;$this->refreshBudget->command((string)$cmd->getCommand());
        if(!$this->refreshFence->authorizeRead($this->refreshBinding))throw new \RuntimeException('Refresh permission or target changed.');
        if($this->refreshSelection!==null&&$this->refreshSelection!==[$this->_connection,(string)$this->_selected,$this->_mode])throw new \RuntimeException('Refresh connection changed.');
    }
}
