<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;

interface DependencyAccess extends DependencyNodeSource {
    public function dependencyNode(string $userId, int $cardId, ?array &$view = null): array;
    public function dependencyChangeState(string $userId, int $originId, int $targetId, bool $assign,
		bool $checkCycle = true): array;
    public function changeDependency(string $userId, int $originId, int $targetId, bool $assign, array $expected): void;
}
