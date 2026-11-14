<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\IDBConnection;
/** Effect and operation row share the primary transaction; retries only read the exact key. */
final class ApprovedFormsOperation {
    public function __construct(private IDBConnection $db, private FormsRevisionFence $fence, private FormsEffectOutbox $outbox) {}
    public function lookup(string $uid, string $key): ?array {
        $row = $this->db->executeQuery('SELECT actor_hash, receipt FROM *PREFIX*forms_mcp_operations WHERE operation_key = ?', [$key])->fetch();
        if (!$row) { return null; }
        if (!hash_equals($row['actor_hash'], OriginContext::identity($uid))) { throw new \RuntimeException('Operation is unavailable'); }
        $receipt = json_decode($row['receipt'], true, 64, JSON_THROW_ON_ERROR);
        $receipt['phases']['events'] = $this->outbox->phases($key);
        foreach ($receipt['phases']['events'] as $event) {
            if ($event['phase'] !== 'delivered') { $receipt['state'] = 'partial'; }
        }
        $files=\OCP\Server::get(ManagedSharePhase::class)->phase($key);
        if ($files!==null) {$receipt['phases']['files']=$files;if ($files!=='native_files_shares_verified') {$receipt['state']='partial';}}
        return $receipt;
    }
    public function apply(string $uid, string $action, array $input, string $expectedRevision, string $operationKey, callable $authorize, callable $work): array {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $operationKey)) { throw new \OCA\Forms\Mcp\BeforeEffect('Invalid operation key'); }
        $intent=$input;unset($intent['_mcp_origin']);
        $fingerprint = hash_hmac('sha256', json_encode([OriginContext::identity($uid),$action,$intent], JSON_THROW_ON_ERROR), \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
        $effectsStarted=false;
        try {$result = $this->fence->transaction(function () use ($uid,$action,$input,$expectedRevision,$operationKey,$fingerprint,$authorize,$work,&$effectsStarted): array {
            $authorize();
            $row = $this->db->executeQuery('SELECT fingerprint FROM *PREFIX*forms_mcp_operations WHERE operation_key = ?', [$operationKey])->fetch();
            if ($row) {
                if (!hash_equals($row['fingerprint'], $fingerprint)) { throw new \OCA\Forms\Mcp\BeforeEffect('Operation key conflict'); }
                return $this->lookup($uid,$operationKey);
            }
            // Acquiring the fence increments exactly once; compare the preceding committed generation.
            if ((string)((int)$this->fence->revision() - 1) !== $expectedRevision) { throw new \OCA\Forms\Mcp\BeforeEffect('Forms preview is stale'); }
            $this->outbox->operation($operationKey);
            \OCP\Server::get(ManagedSharePhase::class)->operation($operationKey);
            $effectsStarted=true;
            try { $outcome = $work(); } finally { $this->outbox->operation(null); \OCP\Server::get(ManagedSharePhase::class)->operation(null); }
            $receipt = ['state'=>'succeeded','operation_key'=>$operationKey,'references'=>$outcome['references'] ?? [],
                'revision'=>$this->fence->revision(),'phases'=>['database'=>'committed','files'=>$outcome['files_phase']??'not_applicable','events'=>[]]];
            $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_operations (operation_key, actor_hash, fingerprint, receipt) VALUES (?, ?, ?, ?)',
                [$operationKey, OriginContext::identity($uid),$fingerprint,json_encode($receipt,JSON_THROW_ON_ERROR)]);
            return $receipt + ['data'=>$outcome['data'] ?? null];
        });} catch (\Throwable $error) {
            // Fence admission can reject before the operation callback starts.
            // Once it starts, a Files effect may exist even if SQL rolls back.
            if (!$effectsStarted && !$error instanceof \OCA\Forms\Mcp\BeforeEffect) {throw new \OCA\Forms\Mcp\BeforeEffect($error->getMessage(),0,$error);}
            throw $error;
        }
        $durable = $this->lookup($uid,$operationKey);
        return array_replace($result,$durable ?? []);
    }
}
