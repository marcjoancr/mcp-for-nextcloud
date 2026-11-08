<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;

interface OperationStore {
    public function get(string $scope, string $id): ?array;
    public function reserve(array $row): bool;
    public function checkpoint(string $scope, string $id, array $refs): void;
    public function finish(string $scope, string $id, string $state, array $refs, int $time): void;
    public function claimPartial(string $scope, string $id, string $state): bool;
    public function settleRepair(string $scope, string $id, array $adopted = []): void;
    public function list(string $scope, int $limit, int $offset): array;
}
