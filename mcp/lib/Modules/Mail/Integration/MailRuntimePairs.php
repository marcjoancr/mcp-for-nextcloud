<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
/** Exact native runtime admission. Extended cores stay closed until native qualification. */
final class MailRuntimePairs {
    public const EXTENDED_CORES=['33.0.10','34.0.5'];
    public static function matches(string $core,string $mail):bool {
        $segment='(?:0|[1-9][0-9]*)';
        if(preg_match('/^('.$segment.'\.'.$segment.'\.'.$segment.')(?:\.'.$segment.')?$/D',$core,$m)!==1)return false;
        if($m[1]==='35.0.2')return in_array($mail,['5.12.5','5.12.5.6','5.12.5.7','5.12.5.8','5.12.5.9','5.12.5.10','5.12.5.11','5.12.5.12'],true);
        return $mail==='5.12.5.8'&&in_array($m[1],self::EXTENDED_CORES,true);
    }
}
