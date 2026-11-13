<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;
interface FormsGateway {
    public function capabilities(string $uid):array;
    public function snapshot(string $uid,string $action,array $input):array;
    public function read(string $uid,string $action,array $input):array;
    public function apply(string $uid,string $action,array $input,string $revision,string $operationKey):array;
    public function lookup(string $uid,string $operationKey,?array $context=null):?array;
}
