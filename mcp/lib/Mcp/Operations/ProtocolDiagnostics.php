<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;

/** Minimal non-secret diagnostics that can safely survive response loss and receipt compaction. */
final class ProtocolDiagnostics {
    public static function sanitize(mixed $results): array {
        if (!is_array($results)) return [];
        $safe=[];
        foreach (['imap','smtp'] as $protocol) {
            if (!array_key_exists($protocol,$results)) continue;
            $r=$results[$protocol];
            if (!is_array($r) || !in_array($r['status']??null,['ok','failed','unsupported','unknown'],true)
                || !in_array($r['category']??null,['authenticated','connection_or_authentication_failed','policy_refused','revision_changed','unsupported_transport','process_unavailable','missing_native_cli','unverified_result','timeout'],true)) {
                $safe[$protocol]=['status'=>'unknown','category'=>'unverified_result'];
            } else $safe[$protocol]=['status'=>$r['status'],'category'=>$r['category']];
        }
        return $safe;
    }
}
