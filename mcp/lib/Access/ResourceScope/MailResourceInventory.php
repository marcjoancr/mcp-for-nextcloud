<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Access\ResourceScope;
/** Bounded owner-only picker metadata, independent of the saved tool policy. */
interface MailResourceInventory {
    /** @return list<array{id:string,name:string,path:?string,kind:string,excluded:bool}> */
    public function resources(string $uid):array;
}
