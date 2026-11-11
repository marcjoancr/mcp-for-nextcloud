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
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private IConfig $config,private ?\OCP\IDBConnection $db=null){}
    /** Read-only source/provider evidence; this never resolves a transport or confers authorization. */
    public function available(string $uid):bool {try{$this->admit($uid);return true;}catch(\Throwable){return false;}}
    public function enabled():bool {return $this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false);}
    public function preview(string $uid,int $draft):array {return $this->native($uid)->preview($uid,$draft);}
    public function queue(string $uid,int $draft,string $revision,string $operation,int $connection,string $source,int $at,string $zone,?array $sentTarget=null):array {
        return $this->native($uid)->queue($uid,$draft,$revision,$operation,$connection,$source,$at,$zone,$sentTarget);
    }
    public function findByOperation(string $uid,string $key):?array {return $this->native($uid)->findByOperation($uid,$key);}
    public function accessContext(string $uid,string $id):array {return $this->native($uid)->accessContext($uid,$id);}
    public function status(string $uid,string $id):array {return $this->native($uid)->status($uid,$id);}
    public function cancel(string $uid,string $id,string $revision):array {return $this->native($uid)->cancel($uid,$id,$revision);}
    public function beforeWireBlock(string $uid):\Throwable {$this->admit($uid);return $this->services->beforeWireBlock($uid);}
    public function blockBeforeWire(string $uid,string $id):array {return $this->native($uid)->blockBeforeWire($uid,$id);}
    public function dispatch(string $uid,string $id,object $fence):array {return $this->native($uid)->dispatch($uid,$id,$fence);}
    public function previewSent(string $uid,string $id):array {$this->admit($uid);return $this->services->sentCopyRepair($uid)->preview($uid,$id);}
    public function repairSent(string $uid,string $id,string $revision,object $fence):array {$this->admit($uid);return $this->services->sentCopyRepair($uid)->repair($uid,$id,$revision,$fence);}
    public function hasUnresolved(string $uid,int $account):bool {
        if(!$this->boundary->deliverySourceVerified())return false;
        if(!$this->services->isEnabledFor($uid))throw new ToolFailure('Mail account state is unavailable.');
        return $this->services->guardedDelivery($uid)->hasUnresolved($uid,$account);
    }
    public function due(int $limit=50):array {
        if(!$this->enabled())return [];
        if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())throw new ToolFailure('The qualified Mail delivery worker is unavailable.');
        return $this->services->guardedDeliveryWorker()->due(max(1,min(50,$limit)));
    }
    /** Reviews outstanding consent even while dispatch is disabled; contains no message data. */
    public function queuedAfter(string $cursor,int $limit=25):array {
        if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())return [];
        return $this->services->guardedDeliveryWorker()->queuedAfter($cursor,max(1,min(25,$limit)));
    }
    private function qualifiedProvider():bool {return $this->db!==null&&$this->db->getDatabaseProvider()===\OCP\IDBConnection::PLATFORM_POSTGRES;}
    private function admit(string $uid):void {
        if(!$this->services->isEnabledFor($uid)||!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||!$this->qualifiedProvider()||!$this->boundary->deliveryVerified())throw new ToolFailure('The qualified native Mail delivery dependency is unavailable.');
    }
    private function native(string $uid):object {$this->admit($uid);return $this->services->guardedDelivery($uid);}
}
