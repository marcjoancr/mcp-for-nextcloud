<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

/** Reads forms through Forms' own permission checks. */
interface FormsReader {
    /** @return list<array<string, mixed>> */
    public function list(string $userId): array;

    /** Null means the current Forms user cannot access this form. @return array<string, mixed>|null */
    public function read(string $userId, int $formId): ?array;
}
