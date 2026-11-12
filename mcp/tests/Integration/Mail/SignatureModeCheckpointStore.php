<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Mcp\Operations\OperationStore;
/** Delegates actual SQL unchanged, injecting withdrawal only after an observed journal checkpoint. */
final class SignatureModeCheckpointStore implements OperationStore {
    public function __construct(private OperationStore $store,private \Closure $change,private string $stage){}
    public function get(string $scope, string $id): ?array {return $this->store->get($scope,$id);}
    public function reserve(array $row): bool {return $this->store->reserve($row);}
    public function checkpoint(string $scope, string $id, array $refs): void {$this->store->checkpoint($scope,$id,$refs);if(($this->stage==='precommit'&&($refs['local_completed']??false))||($this->stage==='checkpoint'&&isset($refs['signature_mode'])&&!isset($refs['local_completed'])))($this->change)();}
    public function uncertainLocal(string $scope,string $id,string $tool,int $time): bool {return $this->store->uncertainLocal($scope,$id,$tool,$time);}
    public function finish(string $scope, string $id, string $state, array $refs, int $time): void {if($this->stage==='lost_finish')throw new \RuntimeException('Synthetic receipt acknowledgement lost after native commit');$this->store->finish($scope,$id,$state,$refs,$time);}
    public function claimPartial(string $scope, string $id, string $state): bool {return $this->store->claimPartial($scope,$id,$state);}
    public function settleRepair(string $scope, string $id, array $adopted = []): void {$this->store->settleRepair($scope,$id,$adopted);}
    public function resolveAwaiting(string $scope,string $id,array $refs,int $time): bool {return $this->store->resolveAwaiting($scope,$id,$refs,$time);}
    public function checkpointNative(string $scope, string $id, string $token, string $expectedPhase, array $refs): bool {return $this->store->checkpointNative($scope,$id,$token,$expectedPhase,$refs);}
    public function finishNative(string $scope, string $id, string $token, string $expectedPhase, string $state, array $refs, int $time): bool {return $this->store->finishNative($scope,$id,$token,$expectedPhase,$state,$refs,$time);}
    public function list(string $scope, int $limit, int $offset): array {return $this->store->list($scope,$limit,$offset);}
}
