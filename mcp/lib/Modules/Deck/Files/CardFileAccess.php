<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Files;

use OCP\Files\File;
use OCP\Share\IShare;
interface CardFileAccess {
    public function cardFileContext(string $userId, int $cardId, bool $edit): array;
    public function cardFileShares(string $userId, int $cardId): array;
    public function cardFileShare(string $userId, int $cardId, int $attachmentId): ?IShare;
    public function cardFileShareExists(string $userId, int $cardId, int $attachmentId, File $node): bool;
    public function unlinkCardFile(string $userId, int $cardId, int $attachmentId, array $expected): void;
}
