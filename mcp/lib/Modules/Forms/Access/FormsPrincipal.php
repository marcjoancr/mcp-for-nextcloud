<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Access;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** The independently pinned native graph reads principal/app/role authority without request-cache reuse. */
final class FormsPrincipal {
    public function __construct(private \Closure $native) {}
    public function assert(string $uid,bool $requiresAdmin=false):void {
        try {($this->native)($uid,$requiresAdmin);}
        catch(\Throwable) {throw new ToolFailure(ToolFailure::FORBIDDEN);}
    }
}
