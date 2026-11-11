<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Access;
use OCA\Mcp\Db\OperationOriginsMapper;
use OCA\Mcp\Mcp\Operations\{OperationJournal,OperationStore,ActorIdentity,ActorRetired};
/** Queue provenance is the original durable reservation, never the current UID or child ticket. */
class MailDeliveryOrigin {
    public function __construct(private OperationOriginsMapper $origins,private OperationJournal $journal,private OperationStore $store,private ActorIdentity $actors) {}
    public function bind(string $uid,string $nativeKey,int $connection,string $source):void {
        $id=$this->journal->activeId($uid);$scope=$this->journal->scopeKey($uid);$original=$this->journal->activeAuthorization($uid);
        if($scope!==hash('sha256',$uid."\0".$connection)||$source!==$original['tool']||$nativeKey!==hash('sha256',$scope."\0".$id))throw new ActorRetired();
        $this->origins->bind($scope,$id);
    }
    /** Caller supplies only trusted native metadata; no private artifact is fetched. */
    public function assertCurrent(string $uid,array $native):string {
        $key=$native['operationKeyHash']??'';
        if(!is_string($key))throw new ActorRetired();
        $origin=$this->origins->get($key);if($origin===null)throw new ActorRetired();
        $connection=$native['connectionId']??null;$source=$native['sourceTool']??null;$account=$native['accountId']??null;
        if(!is_int($connection)||$connection<1||!in_array($source,['mail_send','mail_schedule','mail_snooze'],true)||!is_int($account)||$account<1||$origin['scope_key']!==hash('sha256',$uid."\0".$connection)
            ||$key!==hash('sha256',hash('sha256',$origin['scope_key']."\0".$origin['operation_id'])))throw new ActorRetired();
        $row=$this->store->get($origin['scope_key'],$origin['operation_id']);$refs=$row['resource_refs']??[];
        if(!in_array($row['actor_uid']??null,[$uid,''],true)||($row['tool_name']??null)!==$source||($refs['account_id']??null)!==$account||!is_array($refs['authorization']??null)||!is_string($refs['actor_incarnation']??null))throw new ActorRetired();
        if($source==='mail_snooze')foreach(['sourceMailboxId','snoozeMailboxId']as$field){$box=$native[$field]??null;if(!is_int($box)||$box<1||!in_array(['account'=>$account,'mailbox'=>$box],$refs['authorization']['mail']??[],true))throw new ActorRetired();}
        $this->actors->assertCurrent($uid,$refs['actor_incarnation']);
        return $origin['operation_id'];
    }
}
