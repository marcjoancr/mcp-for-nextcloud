<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
/** Native sync provenance. No connection or callback leaves Mail; stale reads never establish exact cache. */
trait TagSnapshot {
    private ?array $tagSnapshotSelection=null;
    private function tagSnapshotState():array {
        return [$this->_connection,(string)$this->_selected,(string)$this->_mailboxOb()->getStatus(\Horde_Imap_Client::STATUS_UIDVALIDITY),$this->_mode,(bool)$this->_isAuthenticated];
    }
    public function beginTagSnapshot(string $mailbox):string {
        if($this->tagSnapshotSelection!==null)throw new \RuntimeException('Native tag snapshot already armed.');$s=$this->tagSnapshotState();
        if(!is_object($s[0])||$s[1]!==$mailbox||!$s[4]||preg_match('/^[1-9][0-9]{0,9}$/D',$s[2])!==1||(float)$s[2]>4294967295)throw new \RuntimeException('Native tag snapshot selection unavailable.');$this->tagSnapshotSelection=$s;return $s[2];
    }
    private function assertTagSnapshot():void {
        if($this->tagSnapshotSelection!==null&&$this->tagSnapshotSelection!==$this->tagSnapshotState())throw new \RuntimeException('Native tag snapshot selection changed.');
    }
    public function finishTagSnapshot():string {
        if($this->tagSnapshotSelection===null)throw new \RuntimeException('Native tag snapshot unavailable.');$this->assertTagSnapshot();$g=$this->tagSnapshotSelection[2];$this->tagSnapshotSelection=null;return $g;
    }
    public function cancelTagSnapshot():void {$this->tagSnapshotSelection=null;}
}
