<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;
/** Independently pinned by the MCP artifact; the installed companion cannot invent its own trust root. */
final class FormsGuardIdentity {
    public const INSTALLED_MANIFEST_SHA256='4c821aa0573d0549004b89e654d0ee1d2eb2e8f5d2a0b9a76d57bc7374a57602';
    public function __construct(private string $expected=self::INSTALLED_MANIFEST_SHA256) {}
    public function matches(string $root):bool {
        $path=$root.'/mcp-guard-installed.json';
        if(preg_match('/^[a-f0-9]{64}$/D',$this->expected)!==1||is_link($path)||!is_file($path))return false;
        $size=filesize($path);
        if(!is_int($size)||$size<1||$size>1048576)return false;
        $actual=hash_file('sha256',$path);
        return is_string($actual)&&hash_equals($this->expected,$actual);
    }
}
