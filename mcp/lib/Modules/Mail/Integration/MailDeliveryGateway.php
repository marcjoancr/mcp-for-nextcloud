<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IConfig;

/** Closed native queue boundary; ordinary OutboxService and mutable native send APIs are never resolved. */
class MailDeliveryGateway {
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private IConfig $config,private MailGateway $accounts,private \OCA\Mcp\Modules\Mail\Access\MailAccessGuard $access,private ?\OCP\IDBConnection $db=null,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $freshAccess=null){}
    /** Read-only source/provider evidence; this never resolves a transport or confers authorization. */
    public function available(string $uid):bool {try{$this->admit($uid);return true;}catch(\Throwable){return false;}}
    public function enabled():bool {return $this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false);}
    public function preview(string $uid,int $draft,string $source='mail_send'):array {$this->accounts->requireDraft($uid,$draft);return $this->native($uid)->preview($uid,$draft,$this->fence($uid,$source,false));}
    public function queue(string $uid,int $draft,string $revision,string $operation,int $connection,string $source,int $at,string $zone,?array $sentTarget=null):array {
        $this->accounts->requireDraft($uid,$draft);return $this->native($uid)->queue($uid,$draft,$revision,$operation,$connection,$source,$at,$zone,$sentTarget,$this->fence($uid,$source,true));
    }
    public function findByOperation(string $uid,string $key):?array {$this->access->assertReadable($uid);$r=$this->native($uid)->findByOperation($uid,$key);if($r!==null)$this->access->requireAccount($uid,(int)$r['accountId']);return $r;}
    public function accessContext(string $uid,string $id):array {$this->access->assertReadable($uid);$r=$this->native($uid)->accessContext($uid,$id);$this->access->requireAccount($uid,(int)$r['accountId']);return $r;}
    public function status(string $uid,string $id):array {$this->accessContext($uid,$id);return $this->native($uid)->status($uid,$id);}
    public function cancel(string $uid,string $id,string $revision):array {$this->accessContext($uid,$id);$this->freshAccess?->assertActive($uid);return $this->native($uid)->cancel($uid,$id,$revision);}
    public function beforeWireBlock(string $uid):\Throwable {$this->admit($uid);return $this->services->beforeWireBlock($uid);}
    public function blockBeforeWire(string $uid,string $id):array {return $this->native($uid)->blockBeforeWire($uid,$id);}
    public function dispatch(string $uid,string $id,object $fence):array {return $this->native($uid)->dispatch($uid,$id,$fence);}
    public function previewSent(string $uid,string $id):array {$this->accessContext($uid,$id);$this->admit($uid);return $this->services->sentCopyRepair($uid)->preview($uid,$id);}
    public function repairSent(string $uid,string $id,string $revision,object $fence):array {$this->admit($uid);return $this->services->sentCopyRepair($uid)->repair($uid,$id,$revision,$fence);}
    /** These internal calls may only inspect metadata or terminal-block a queued entry, never transmit. */
    public function workerContext(string $uid,string $id):array {return $this->authorization()->context($uid,$id);}
    public function reviewAuthorization(string $uid,string $id,object $fence):array {return $this->authorization()->review($uid,$id,$fence);}
    public function workerBeforeWireBlock():\Throwable {return $this->authorization()->denied();}
    private function authorization():object {
        if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())throw new ToolFailure('The qualified Mail authorization worker is unavailable.');
        return $this->services->deliveryAuthorization();
    }
    public function hasUnresolved(string $uid,int $account):bool {
        if(!$this->boundary->deliverySourceVerified())return false;
        if(!$this->services->isEnabledFor($uid))throw new ToolFailure('Mail account state is unavailable.');
        return $this->services->guardedDelivery($uid)->hasUnresolved($uid,$account);
    }
    public function due(int $limit=50):array {
        if(!$this->enabled())return [];
        if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())throw new ToolFailure('The qualified Mail delivery worker is unavailable.');
        return $this->authorization()->due(max(1,min(50,$limit)));
    }
    /** Reviews outstanding consent even while dispatch is disabled; contains no message data. */
    public function queuedAfter(string $cursor,int $limit=25):array {
        if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())return [];
        return $this->authorization()->queuedAfter($cursor,max(1,min(25,$limit)));
    }
    private function fence(string $uid,string $source,bool $effect):object {
        if(!in_array($source,['mail_send','mail_schedule'],true))throw new ToolFailure('Mail action unavailable.');
        return new class($uid,$source,$effect,$this->accounts,$this->freshAccess){
            public function __construct(private string $uid,private string $tool,private bool $effect,private MailGateway $accounts,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $fresh){}
            public function authorize(string $uid,int $account):void {
                if($uid!==$this->uid)throw new ToolFailure('Mail authorization changed.');
                if($this->effect)$this->fresh?->assertActive($uid);else $this->fresh?->assertResource($uid,$this->tool,$account);
                $this->accounts->requireAccount($uid,$account);
            }
        };
    }
    private function qualifiedProvider():bool {return $this->db!==null&&$this->db->getDatabaseProvider()===\OCP\IDBConnection::PLATFORM_POSTGRES;}
    private function admit(string $uid):void {
        if(!$this->services->isEnabledFor($uid)||!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())throw new ToolFailure('The qualified native Mail delivery dependency is unavailable.');
    }
    private function native(string $uid):object {$this->admit($uid);return $this->services->guardedDelivery($uid);}
}
