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
    public const INSTALLED_MANIFEST_SHA256='c5c3d199f2142a31de44f5a9ba6afd9560fd6d8136f97d5f58dfc7d2c43637e6';
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
