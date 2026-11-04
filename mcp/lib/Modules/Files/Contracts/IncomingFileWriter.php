<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Contracts;

/** Writes incoming binary content through the native Files creation policy. */
interface IncomingFileWriter {
    /** @return array<string, mixed> a no-write preview of the destination and its current Files access */
    public function preview(string $userId, string $path, int $size): array;

    /** @return array<string, mixed> a file receipt or a shared-write confirmation request */
    public function save(string $userId, string $path, string $content, bool $confirmShared): array;
}
