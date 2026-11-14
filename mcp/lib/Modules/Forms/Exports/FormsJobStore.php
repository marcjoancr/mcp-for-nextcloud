<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
interface FormsJobStore {
    public function get(string $id):?array;
    public function create(array $job):bool;
    public function compareAndSwap(array $expected,array $next):bool;
    public function acknowledgeLinkedDelivery(array $expected,array $next,array $nativeReceipt):bool;
}
