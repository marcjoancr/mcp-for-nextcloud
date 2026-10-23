<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

interface LabelsAccess {
    public function listLabels(string $userId, int $boardId, int $limit, int $offset): array;
    public function cardLabelState(string $userId, int $cardId, array $requested): array;
    public function changeCardLabel(string $userId, int $cardId, int $labelId, bool $assign,
		array $requested, array $expected): void;
}
