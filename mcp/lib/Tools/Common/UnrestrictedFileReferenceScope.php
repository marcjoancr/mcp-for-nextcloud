<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCP\Files\Node;

/**
 * The empty scope step: no Files scope exists yet, so it narrows nothing. The Files scope slice replaces this
 * registration with the ScopeGuard; the order of the gates stays in {@see OutputReferenceFilter}.
 */
final class UnrestrictedFileReferenceScope implements FileReferenceScope {
    public function restricts(string $userId): bool {
        return false;
    }

    public function allows(string $userId, Node $node): bool {
        return true;
    }
}
