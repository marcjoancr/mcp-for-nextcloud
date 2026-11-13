<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
interface FormsSpool {
    public function reserve(string $job,string $uid):void;
    public function assertActive(string $job,string $uid):void;
    public function seal(string $job):void;
    public function cleanup():void;
    public function put(string $job,string $content):array;
    public function get(string $job,array $part):string;
}
