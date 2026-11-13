<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Execution;
/** Exact receipt CAS for adopting committed native evidence; never grants permission to replay. */
interface FormsOutcomeStore {
    public function reconcile(array $expected,string $state,array $refs,int $time):bool;
}
