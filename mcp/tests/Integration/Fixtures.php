<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration;

/** Accounts created by tests/Integration/ci/harness.sh fixtures before the suite runs. */
final class Fixtures {
    /** Member of STAFF. */
    public const ALICE = 'mcp-it-alice';
    /** In no fixture group. */
    public const BOB = 'mcp-it-bob';
    /** Database-backend group holding ALICE only. */
    public const STAFF = 'mcp-it-staff';
    /** Database-backend group without members. */
    public const EMPTY = 'mcp-it-empty';
}
