<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
/** One irrevocable claim, one transport invocation; no recovery callback can submit mail. */
final class DispatchAttempt {
    public static function run(\Closure $claim,\Closure $send,\Closure $finish):string {
        // Deliberately outside catch: an unknown commit must escape before transport construction.
        if(!$claim())return 'unchanged';
        try{$send();if(!$finish('accepted'))throw new \RuntimeException('Acceptance persistence unavailable.');return 'accepted';}
        catch(\Throwable){try{$finish('uncertain');}catch(\Throwable){}return 'uncertain';}
    }
}
