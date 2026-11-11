<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;

/** Durable admission is recorded before native I/O. A lost result is never permission to repeat it. */
final class OperationJournal {
    private const TTL = 900;
    private ?array $active = null;

    public function __construct(private OperationStore $store, private PlanState $proof,
        private ConnectionContext $connection, private ITimeFactory $clock, private ActorIdentity $actors) {}

    public static function property(): array {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128,
            'description' => 'Repeat operation_id from the approved plan with confirm:true; reuse it to inspect/recover a lost response, never generate a replacement after an uncertain result.'];
    }

    public function plan(string $tool, string $uid, array $args, array $plan): array {
        $this->actors->capture($uid);
        $expires = $this->clock->getTime() + self::TTL;
        $nonce = bin2hex(random_bytes(16));
        $base = $nonce . '.' . $expires;
        $effect = $this->effect(array_replace($args, ['plan_state' => $plan['plan_state']]));
        $id = $base . '.' . $this->signature($tool, $uid, $base, $effect);
        return $plan + ['operation_id' => $id, 'operation_expires_at' => $expires];
    }

    public function execute(string $tool, string $uid, array $args, \Closure $effect, ?\Closure $authorizeReplay = null): array {
        $id = $args['operation_id'] ?? '';
        if (!is_string($id) || !preg_match('/^([0-9a-f]{32}\.([0-9]{1,12}))\.([0-9a-f]{64})$/D', $id, $parts)) {
            throw new \InvalidArgumentException('Repeat the operation_id from the approved plan.');
        }
        $fingerprint = $this->effect($args);
        if (!hash_equals($this->signature($tool, $uid, $parts[1], $fingerprint), $parts[3])) {
            throw new ToolFailure('Operation does not match the approved request.');
        }
        $actor = $this->actors->capture($uid);
        $scope = $this->scope($uid);
        $old = $this->store->get($scope, $id);
        if ($old !== null) return $this->replay($uid, $old, $authorizeReplay);
        if ((int)$parts[2] < $this->clock->getTime()) throw new \InvalidArgumentException('Operation plan expired; preview again.');
        $target = $tool==='mail_reconcile_operation' ? 'reconcile:'.($args['target_operation_id']??'') : ($tool==='mail_create_tag' ? 'mail-tags' : (isset($args['account_id']) ? 'account:' . (int)$args['account_id'] : (isset($args['draft_id']) ? 'draft:' . $args['draft_id'] : (isset($args['path']) ? 'file:' . $args['path'] : null))));
        $row = ['scope_key' => $scope, 'actor_uid' => $uid, 'operation_id' => $id, 'tool_name' => $tool, 'effect_digest' => $fingerprint,
            'target_key' => $target === null ? null : hash('sha256', $uid . "\0" . $target),
            'state' => 'running', 'resource_refs' => ['actor_incarnation'=>$actor] + (isset($args['account_id']) ? ['account_id'=>(int)$args['account_id']] : []), 'created_at' => $this->clock->getTime(), 'updated_at' => $this->clock->getTime()];
        if (!$this->store->reserve($row)) {
            $existing = $this->store->get($scope, $id);
            if ($existing !== null) return $this->replay($uid, $existing, $authorizeReplay);
            throw new ToolFailure('Another write owns this target; inspect its operation before changing it.');
        }
        $this->active = ['uid'=>$uid, 'actor'=>$actor, 'scope' => $scope, 'id' => $id, 'tool'=>$tool, 'refs' => $row['resource_refs']];
        $repair = null;
        try {
            $this->assertActiveActor();
            $result = $effect();
            $this->assertActiveActor();
        } catch (LocalCommitUnknown $failure) {
            // Do not write provisional memory back after an ambiguous commit. The connection was discarded.
            $this->active = null;
            if(!$failure->connectionDiscarded)throw new ToolFailure('Local connection outcome unavailable. Inspect the original operation; never repeat it.');
            if(!$this->store->uncertainLocal($scope,$id,$tool,$this->clock->getTime()))throw new ToolFailure('Local commit outcome unavailable. Inspect the original operation; never repeat it.');
            $receipt=$this->get($uid,$id);if($receipt!==null)$receipt['state']='uncertain';
            return ['operation'=>$receipt, 'message'=>'Local commit acknowledgement was lost. Inspect this operation; do not repeat the write.'];
        } catch (PlanChanged|BeforeEffectFailure $e) {
            if(isset($this->active['delegated']))return $this->adoptNative($uid);
            $authorization=$this->active['refs']['authorization']??null;
            $this->active = null;
            $this->store->finish($scope, $id, 'failed_before_effect', ['actor_incarnation'=>$actor]+(is_array($authorization)?['authorization'=>$authorization]:[]), $this->clock->getTime());
            throw $e;
        } catch (\Throwable) {
            if(isset($this->active['delegated']))return $this->adoptNative($uid);
            $repair = $this->active['repair_of'] ?? null;
            $refs = $this->active['refs'];
            $this->active = null;
            $knownEffect=isset($refs['draft_id'])||isset($refs['file_id'])||!empty($refs['staged']);
            $state = !$knownEffect || (($refs['native_write_started'] ?? false) && !isset($refs['draft_id'])) ? 'uncertain' : 'partial';
            $this->store->finish($scope, $id, $state, $refs, $this->clock->getTime());
            if ($repair !== null && $state !== 'uncertain') $this->store->settleRepair($scope, $repair, $refs['staged'] ?? []);
            return ['operation' => $this->get($uid, $id),
                'message' => 'The native write could not be verified. Inspect its state; do not repeat the write.'];
        }
        if(isset($this->active['delegated']))return $this->adoptNative($uid);
        $deferred=$this->active['awaiting_user']??false;
        $repair = $this->active['repair_of'] ?? null;
        $refs = $this->active['refs'];
        $this->active = null;
        if (isset($result['draft']['id'])) $refs['draft_id'] = (int)$result['draft']['id'];
        if (isset($result['deleted_draft_id'])) $refs['draft_id'] = (int)$result['deleted_draft_id'];
        if (isset($result['file']['fileId'])) $refs['file_id'] = (int)$result['file']['fileId'];
        if(isset($result['account_id']))$refs['account_id']=(int)$result['account_id'];
        $state = $deferred ? 'awaiting_user' : (($result['requiresConfirmation'] ?? false) ? 'failed_before_effect' : (($result['partial'] ?? false) ? 'partial' : 'succeeded'));
        $this->store->finish($scope, $id, $state, $refs, $this->clock->getTime());
        if ($repair !== null) $this->store->settleRepair($scope, $repair, $refs['staged'] ?? []);
        return $result + ['operation' => $this->get($uid, $id)];
    }

    /** DB-only native mapper work and its checkpoint must use this same connection. No remote effects are allowed. */
    public function atomicLocal(\OCP\IDBConnection $db, \Closure $write): mixed {
        if($this->active===null||isset($this->active['delegated'])||$db->inTransaction())throw new BeforeEffectFailure('An independent local operation transaction is required.');
        $this->assertActiveActor();
        $before=$this->active['refs'];
        try{$db->beginTransaction();}catch(\Throwable){throw new BeforeEffectFailure('The local transaction could not start.');}
        try{$result=$write();if(!$db->inTransaction())throw new \RuntimeException('The local transaction ended before its completion checkpoint.');$this->checkpoint(['local_completed'=>true]);$this->assertActiveActor();}
        catch(\Throwable $e){
            try{$db->rollBack();}catch(\Throwable){try{$db->close();}catch(\Throwable){throw new LocalCommitUnknown('Local connection could not be discarded.',false);}throw new LocalCommitUnknown('Local rollback outcome unavailable.');}
            $this->active['refs']=$before;
            if($e instanceof PlanChanged||$e instanceof BeforeEffectFailure)throw $e;
            throw new BeforeEffectFailure('The local transaction was rolled back. No change was committed.');
        }
        try{$db->commit();}catch(\Throwable){try{$db->close();}catch(\Throwable){throw new LocalCommitUnknown('Local connection could not be discarded.',false);}throw new LocalCommitUnknown('Local commit outcome unavailable.');}
        return $result;
    }

    /** Transfers the active reservation to exactly one bounded worker. Private token never enters a receipt. */
    public function delegateNative(string $uid,array $descriptor): array {
        $id=$this->activeId($uid);$scope=$this->scope($uid);
        if(isset($this->active['delegated'])||($descriptor['account_id']??null)!==($this->active['refs']['account_id']??null))throw new BeforeEffectFailure('Invalid native delegation.');
        $token=bin2hex(random_bytes(32));
        if(!$this->store->checkpointNative($scope,$id,$token,'',['phase'=>'prepared','descriptor'=>$descriptor,'expires_at'=>$this->clock->getTime()+25]))throw new BeforeEffectFailure('Native operation admission changed.');
        $this->active['delegated']=$token;
        return ['scope'=>$scope,'id'=>$id,'token'=>$token];
    }

    private function adoptNative(string $uid): array {
        $active=$this->active;$this->active=null;
        $this->actors->assertCurrent($uid,$active['actor']);
        for($attempt=0;$attempt<8;$attempt++){
            $row=$this->store->get($active['scope'],$active['id']);
            if($row===null)throw new \RuntimeException('Native operation evidence unavailable.');
            if($row['state']!=='running')break;
            $phase=$row['resource_refs']['mutation']['phase']??'';
            $state=$phase==='completed'?'succeeded':($phase==='prepared'?'failed_before_effect':'uncertain');
            $this->actors->assertCurrent($uid,$active['actor']);
            if($this->store->finishNative($active['scope'],$active['id'],$active['delegated'],$phase,$state,[],$this->clock->getTime()))break;
        }
        return ['operation'=>$this->get($uid,$active['id'])];
    }

    public function scopeKey(string $uid): string {return $this->scope($uid);}
    public function activeId(string $uid): string {
        if($this->active===null || $this->active['scope']!==$this->scope($uid))throw new ToolFailure('A confirmed operation reservation is required.');
        $this->assertActiveActor();
        return $this->active['id'];
    }
    public function awaitingUser(int $expires): void {
        if($this->active===null)throw new ToolFailure('A confirmed operation reservation is required.');
        $this->active['awaiting_user']=true;$this->checkpoint(['user_expires_at'=>$expires,'native_state'=>'unverified','source'=>'native_ui']);
    }
    public function completeExternal(string $uid,string $id,int $account): void {
        $this->activeId($uid);
        $original=$this->privateResources($uid,$id);
        if(!$this->store->resolveAwaiting($this->scope($uid),$id,array_replace($original,['account_id'=>$account,'native_state'=>'observed_in_mail','source'=>'native_ui']),$this->clock->getTime()))throw new ToolFailure('Setup state changed; inspect its outcome.');
    }

    /** Checkpoint only opaque native identities and integrity digests, never message or file content. */
    public function checkpoint(array $refs): void {
        if ($this->active === null) return;
        $this->assertActiveActor();
        if(array_key_exists('actor_incarnation',$refs)&&$refs['actor_incarnation']!==$this->active['actor'])throw new \LogicException('Original actor is immutable.');
        if(isset($this->active['delegated']))throw new \LogicException('Delegated evidence is authoritative.');
        if(isset($refs['authorization'],$this->active['refs']['authorization'])&&$refs['authorization']!==$this->active['refs']['authorization'])throw new \LogicException('Original authorization is immutable.');
        $this->active['refs'] = array_replace($this->active['refs'], $refs);
        $this->store->checkpoint($this->active['scope'], $this->active['id'], $this->active['refs']);
    }

    /** Called after the fresh draft plan was validated, before reusable attachment IDs are touched. */
    public function claimRepair(string $uid, string $id): void {
        $this->privateResources($uid,$id);
        if ($this->active === null || $this->active['scope'] !== $this->scope($uid)
            || !$this->store->claimPartial($this->scope($uid), $id, 'repairing')) {
            throw new ToolFailure('Repair state changed; preview again.');
        }
        $this->active['repair_of'] = $id;
        $this->checkpoint(['repair_source_operation_id'=>$id]);
    }

    public function repairResources(string $uid, string $id): array {
        $row = $this->store->get($this->scope($uid), $id);
        if ($row === null || !($row['state'] === 'partial' || ($row['state'] === 'repairing' && ($this->active['repair_of'] ?? null) === $id && ($this->active['scope'] ?? null) === $this->scope($uid))) || !in_array($row['tool_name'], ['mail_write_draft', 'mail_attach_files'], true)) {
            throw new ToolFailure('This operation has no verified attachment repair state.');
        }
        $this->assertRowActor($uid,$row);
        return $row['resource_refs'];
    }

    /** Private evidence for a fresh effect fence; activeId also binds the originating connection. */
    public function activeAuthorization(string $uid):array {
        $this->activeId($uid);$refs=$this->active['refs']['authorization']??null;
        if(!is_array($refs))throw new ToolFailure('Original Mail authorization unavailable.');
        return ['tool'=>$this->active['tool'],'refs'=>$refs,'actor_incarnation'=>$this->active['actor']];
    }
    /** Trusted server-side authorization only; never serialize this result into a public receipt. */
    public function privateResources(string $uid,string $id):array {
        $row=$this->store->get($this->scope($uid),$id);
        if($row===null)throw new ToolFailure('Operation not found.');
        $this->assertRowActor($uid,$row);
        return $row['resource_refs'];
    }
    /** One immutable descriptor, recorded after ownership admission and before the effect callback proceeds. */
    public function recordAuthorization(string $uid,array $refs):void {
        $this->activeId($uid);
        if(isset($this->active['refs']['authorization']))throw new \LogicException('Original authorization is immutable.');
        $this->checkpoint(['authorization'=>$refs]);
    }

    public function get(string $uid, string $id): ?array {
        $row = $this->store->get($this->scope($uid), $id);
        if($row===null)return null;
        $this->assertRowActor($uid,$row);
        return $this->receipt($row, false);
    }

    public function list(string $uid, int $limit = 20, int $offset = 0): array {
        return $this->page($uid,$limit,$offset)['operations'];
    }

    /** Internal raw cursor count prevents hidden old actors from truncating or rescanning list pages. */
    public function page(string $uid,int $limit,int $offset):array {
        $this->actors->capture($uid);
        $rows=$this->store->list($this->scope($uid),max(1,min(50,$limit)),max(0,min(10000,$offset)));$visible=[];
        foreach($rows as $row) {
            try{$this->assertRowActor($uid,$row);}catch(ActorRetired){continue;}
            $visible[]=$this->receipt($row,false);
        }
        return ['operations'=>$visible,'scanned'=>count($rows)];
    }

    private function replay(string $uid,array $row, ?\Closure $authorize): array {
        $this->assertRowActor($uid,$row);
        $receipt = $this->receipt($row, true);
        if ($authorize !== null) $authorize($receipt);
        return ['operation' => $receipt];
    }

    /** Pin this process admission before any private Mail read or callback is created. */
    public function actor(string $uid):string { return $this->actors->capture($uid); }
    public function assertActor(string $uid,string $original):void { $this->actors->assertCurrent($uid,$original); }
    private function assertActiveActor():void {
        if($this->active===null)throw new ToolFailure('A confirmed operation reservation is required.');
        $this->actors->assertCurrent($this->active['uid'],$this->active['actor']);
    }
    private function assertRowActor(string $uid,array $row):void {
        $token=$row['resource_refs']['actor_incarnation']??'';
        if(!in_array($row['actor_uid']??null,[$uid,''],true)||($row['scope_key']??null)!==$this->scope($uid)||!is_string($token)||$token==='')throw new ActorRetired();
        $this->actors->assertCurrent($uid,$token);
        if(!hash_equals($this->actors->capture($uid),$token))throw new ActorRetired();
    }

    private function receipt(array $row, bool $replayed): array {
        $state = $row['state'];
        if($state==='running' && isset($row['resource_refs']['mutation']['expires_at']) && $this->clock->getTime()>(int)$row['resource_refs']['mutation']['expires_at'])$state='uncertain';
        if ($state === 'running' && $this->clock->getTime() - (int)$row['updated_at'] > self::TTL) $state = 'uncertain';
        if($state==='awaiting_user' && $this->clock->getTime()>(int)($row['resource_refs']['user_expires_at']??0))$state='expired';
        if(isset($row['resource_refs']['delivery_id'])&&(!is_string($row['resource_refs']['delivery_id'])||preg_match('/^[a-f0-9]{64}$/D',$row['resource_refs']['delivery_id'])!==1))unset($row['resource_refs']['delivery_id']);
        $extra=array_intersect_key($row['resource_refs'],array_flip(['native_state','source','source_tool','source_tools','authentication_observation','reconciled_operation_id']));
        if ($row['tool_name']==='mail_test_connection' && isset($row['resource_refs']['protocols'])) $extra['protocols']=ProtocolDiagnostics::sanitize($row['resource_refs']['protocols']);
        if(isset($row['resource_refs']['mutation']))$extra['mutation']=array_intersect_key($row['resource_refs']['mutation'],array_flip(['phase','remote','cache']));
        return $extra+['operation_id' => $row['operation_id'], 'tool' => $row['tool_name'], 'state' => $state,
            'resource_refs' => array_intersect_key($row['resource_refs'], array_flip(array_merge(['snooze_id','delivery_id','draft_id', 'file_id','account_id'],$row['tool_name']==='mail_create_tag'?['tag_id']:(in_array($row['tool_name'],['mail_add_alias','mail_update_alias','mail_delete_alias'],true)?['alias_id']:[])))), 'replayed' => $replayed, 'safe_to_retry' => false,
            'updated_at' => (int)$row['updated_at']];
    }

    private function scope(string $uid): string { return hash('sha256', $uid . "\0" . $this->connection->current()); }

    private function signature(string $tool, string $uid, string $base, string $effect): string {
        return $this->proof->of('mail_operation', $uid, [$this->connection->current(), $tool, $base, $effect]);
    }

    private function effect(array $args): string {
        unset($args['operation_id'], $args['confirm'], $args['confirm_shared']);
        $sort = function(array $a) use (&$sort): array {
            if (!array_is_list($a)) ksort($a);
            foreach ($a as &$v) if (is_array($v)) $v = $sort($v);
            return $a;
        };
        // A keyed fingerprint avoids turning the operation table into an offline dictionary of mail bodies.
        return $this->proof->of('mail_effect', '', $sort($args));
    }
}
