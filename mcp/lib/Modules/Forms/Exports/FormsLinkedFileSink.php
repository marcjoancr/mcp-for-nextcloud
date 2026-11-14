<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
/** Files adapter remains subject to the original actor's scopes, audience, preimage and recovery policy. */
interface FormsLinkedFileSink {
    public function snapshot(array $claim):array;
    public function references(array $claim,array $submission,\Closure $source):array;
    public function write(array $claim,string $content,array $job,\Closure $source):array;
}
