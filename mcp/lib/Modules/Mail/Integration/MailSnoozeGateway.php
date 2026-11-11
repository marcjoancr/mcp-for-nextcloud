<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailFreshAccess,MailDeliveryOrigin};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\{IDBConnection,IConfig};
/** Closed native snooze surface, with current original authorization at every effect. */
class MailSnoozeGateway {
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private IDBConnection $db,private IConfig $config,private MailAccessGuard $access,private MailFreshAccess $fresh,private MailDeliveryOrigin $origins,private ConnectionContext $connection){}
    private function qualified():void {if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||$this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES||!$this->boundary->snoozeVerified())throw new ToolFailure('The qualified guarded Mail snooze dependency is unavailable.');}
    private function native(string $uid):object {$this->qualified();return $this->services->guardedSnooze($uid);}
    private function admission(string $uid,bool $effect):object {
        $live=$effect?$this->fresh->activeFence($uid,'mail_snooze'):null;
        return new class($uid,$live,$this->fresh,$this->connection){
            public function __construct(private string $uid,private ?\Closure $live,private MailFreshAccess $fresh,private ConnectionContext $connection){}
            public function authorize(string $uid,array $r):void {
                if($uid!==$this->uid||$this->connection->current()<1||isset($r['connectionId'])&&$r['connectionId']!==$this->connection->current())throw new ToolFailure('Snooze requires the original persistent connection.');
                if($this->live!==null)($this->live)();else foreach(['sourceMailboxId','snoozeMailboxId']as$key)foreach(['mail_snooze','mail_wake']as$tool)$this->fresh->assertResource($uid,$tool,$r['accountId'],$r[$key]);
            }
        };
    }
    public function preview(string $uid,int $message,int $at,string $zone):array {return $this->native($uid)->preview($uid,$message,$at,$zone,$this->admission($uid,false));}
    public function schedule(string $uid,int $message,int $at,string $zone,string $revision,string $key):array {$this->origins->bind($uid,$key,$this->connection->current(),'mail_snooze');return $this->native($uid)->schedule($uid,$message,$at,$zone,$revision,$key,$this->connection->current(),$this->admission($uid,true));}
    public function origin(string $uid,string $id):array {$r=$this->native($uid)->accessContext($uid,$id);$this->origins->assertCurrent($uid,$r);if($r['connectionId']!==$this->connection->current())throw new ToolFailure('Snooze unavailable.');foreach(['sourceMailboxId','snoozeMailboxId']as$key)$this->access->requireMailbox($uid,$r['accountId'],$r[$key]);return $r;}
    private function fence(string $uid,string $id,string $tool,bool $effect=false):object {
        $origin=$this->origin($uid,$id);$operation=$this->origins->assertCurrent($uid,$origin);$original=$this->fresh->historicalFence($uid,$operation,'mail_snooze',[$tool]);$current=$effect?$this->fresh->activeFence($uid,$tool):null;
        return new class($uid,$origin,$original,$current,$this->origins){
            public function __construct(private string $uid,private array $origin,private \Closure $original,private ?\Closure $current,private MailDeliveryOrigin $origins){}
            public function authorize(string $uid,array $origin):void {if($uid!==$this->uid||$origin!==$this->origin)throw new ToolFailure('Snooze identity changed.');$this->origins->assertCurrent($uid,$origin);($this->original)();$this->current?->__invoke();}
        };
    }
    public function status(string $uid,string $id,string $tool='mail_read_snooze'):array {return $this->native($uid)->status($uid,$id,$this->fence($uid,$id,$tool));}
    public function previewWake(string $uid,string $id):array {return $this->native($uid)->previewWake($uid,$id,$this->fence($uid,$id,'mail_wake'));}
    public function cancel(string $uid,string $id,string $revision):array {return $this->native($uid)->cancel($uid,$id,$revision,$this->fence($uid,$id,'mail_cancel_snooze',true));}
    public function recover(string $uid,string $key):?array {$r=$this->native($uid)->findByOperation($uid,$key);return $r===null?null:$this->status($uid,$r['id'],'mail_snooze');}
    public function workerContext(string $uid,string $id):array {$this->qualified();return $this->services->snoozeAuthorization()->accessContext($uid,$id);}
    public function originalOperation(string $uid,array $origin):string {return $this->origins->assertCurrent($uid,$origin);}
    public function review(string $uid,string $id,object $fence):array {$this->qualified();return $this->services->snoozeAuthorization()->review($uid,$id,$fence);}
    public function dispatch(string $uid,string $id,?string $revision,object $fence):array {
        $native=$this->native($uid);$status=$native->status($uid,$id,$fence);
        return $status['state']==='placing'?$native->place($uid,$id,$fence):$native->wake($uid,$id,$revision,$fence);
    }
    public function due(int $limit=25):array {$this->qualified();return $this->services->snoozeAuthorization()->due($limit);}
    public function queuedAfter(string $cursor,int $limit=25):array {$this->qualified();return $this->services->snoozeAuthorization()->queuedAfter($cursor,$limit);}
    public function denied():\Throwable {$this->qualified();return $this->services->deliveryAuthorization()->denied();}
}
