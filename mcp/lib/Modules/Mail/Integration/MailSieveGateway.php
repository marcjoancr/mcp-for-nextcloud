<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailFreshAccess,MailDeliveryOrigin};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\{IDBConnection,IConfig};
/** Only this optional-dependency boundary resolves the closed native structured Sieve API. */
class MailSieveGateway {
    public function __construct(private MailServices $services,private MailNativeBoundary $boundary,private IDBConnection $db,private IConfig $config,private MailAccessGuard $access,private MailFreshAccess $fresh,private MailDeliveryOrigin $origins,private ConnectionContext $connection){}
    private function native(string $uid):object {if(!$this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))||$this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES||!$this->boundary->sieveVerified())throw new ToolFailure('Qualified structured Mail filters are unavailable.');return $this->services->guardedSieve($uid);}
    private function fence(string $uid,int $account,string $tool,bool $effect):object {
        $this->access->requireAccount($uid,$account);$live=$effect?$this->fresh->activeFence($uid,$tool):null;$connection=$this->connection->current();if($effect&&$connection<1)throw new ToolFailure('Sieve changes require a persistent connection.');
        return new class($uid,$account,$tool,$connection,$live,$this->fresh,$this->origins,$this->config){
            public function __construct(private string $uid,private int $account,private string $tool,private int $connection,private ?\Closure $live,private MailFreshAccess $fresh,private MailDeliveryOrigin $origins,private IConfig $config){}
            public function authorize(string $uid,array $r):void {
                if($uid!==$this->uid||($r['accountId']??null)!==$this->account)throw new ToolFailure('Sieve identity changed.');
                if(isset($r['connectionId'])){if($r['connectionId']!==$this->connection||$r['sourceTool']!==$this->tool)throw new ToolFailure('Sieve origin changed.');$this->origins->assertCurrent($uid,$r);}
                if($this->live!==null)($this->live)();else $this->fresh->assertResource($uid,$this->tool,$this->account);
                if(in_array($this->tool,['mail_transmit_filters','mail_enable_absence'],true)&&!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new ToolFailure('Mail transmission is disabled by the operator.');
            }
        };
    }
    public function read(string $uid,int $account,string $tool):array {$native=$this->native($uid);$r=$native->read($uid,$account,$this->fence($uid,$account,$tool,false));unset($r[$tool==='mail_read_filters'?'absence':'filters']);return $r;}
    public function preview(string $uid,int $account,string $tool,array $config):array {return $this->native($uid)->preview($uid,$account,$tool,$config,$this->fence($uid,$account,$tool,false));}
    public function apply(string $uid,int $account,string $tool,array $config,string $revision,string $key):array {
        $this->origins->bind($uid,$key,$this->connection->current(),$tool);return $this->native($uid)->apply($uid,$account,$tool,$config,$revision,$key,$this->connection->current(),$this->fence($uid,$account,$tool,true));
    }
    public function status(string $uid,string $id,string $tool='mail_read_sieve_status'):array {
        $native=$this->native($uid);$origin=$native->accessContext($uid,$id);$operation=$this->origins->assertCurrent($uid,$origin);if($origin['connectionId']!==$this->connection->current())throw new ToolFailure('Sieve operation unavailable.');$this->access->requireAccount($uid,$origin['accountId']);$fresh=$this->fresh->historicalFence($uid,$operation,$origin['sourceTool'],[$tool]);
        $fence=new class($uid,$origin,$fresh,$this->origins){
            public function __construct(private string $uid,private array $origin,private \Closure $fresh,private MailDeliveryOrigin $origins){}
            public function authorize(string $uid,array $r):void {if($uid!==$this->uid||($r['accountId']??null)!==$this->origin['accountId']||(isset($r['operationKeyHash'])&&$r!==$this->origin))throw new ToolFailure('Sieve origin changed.');$this->origins->assertCurrent($uid,$this->origin);($this->fresh)();}
        };return $native->status($uid,$id,$fence);
    }
    public function recover(string $uid,string $key,string $tool):?array {$r=$this->native($uid)->findByOperation($uid,$key);return $r===null?null:$this->status($uid,$r['id'],$tool);}
}
