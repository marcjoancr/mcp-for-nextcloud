<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Lifecycle;
use OCP\{IDBConnection,IUser};
/** Only a correlated successful native lifecycle event may activate captured identities. */
final class MailErasureService {
    private \WeakMap $captures;
    public function __construct(private IDBConnection $db,private MailErasureStore $store){$this->captures=new \WeakMap();}
    public function before(IUser $user):void {
        if(isset($this->captures[$user]))return;
        $uid=$user->getUID();$lease=AccountCapture::acquire($this->db,$uid);
        try{$id=$this->store->capture($uid);$lease->assertOwned();}catch(\Throwable $e){$lease->release();throw $e;}
        $this->captures[$user]=['id'=>$id,'uid'=>$uid,'lease'=>$lease,'confirmed'=>false];
        // Core has no backend-failure event. Inert preparations and this request's lease expire here.
        register_shutdown_function(function()use($id,$lease):void {try{$this->store->preparedAbandoned($id);}catch(\Throwable){}finally{$lease->release();}});
    }
    public function deleted(IUser $user):bool {
        $capture=$this->captures[$user]??null;
        // Never infer an incarnation by enumerating a UID from an uncorrelated/delayed event.
        if($capture===null)return false;
        $first=!$capture['confirmed'];
        if(!$capture['confirmed']){$capture['lease']->assertOwned();$this->store->confirm($capture['id'],$capture['uid']);$capture['confirmed']=true;$this->captures[$user]=$capture;$capture['lease']->release();}
        $this->attempt($capture['id'],$capture['uid']);return $first;
    }
    private function attempt(string $id,string $uid):void {
        try{\OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->db)->runOwnerExclusive($uid,fn()=>$this->store->erase($id,$uid));}
        catch(\Throwable){/* Confirmed metadata remains durable; the bounded job retries without loading payload. */}
    }
    public function retry():void {foreach($this->store->pending() as $row)$this->attempt($row['id'],$row['user_id']);}
}
