<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Composition;

/** Derives only visible recipients; original Bcc is never an input to a reply. */
final class ReplyRecipients {
    public static function derive(array $message, array $ownAddresses, bool $all): array {
        $seen = array_fill_keys(array_map('strtolower', $ownAddresses), true);
        $clean = static function(array $rows) use (&$seen): array {
            $out = [];
            foreach ($rows as $row) {
                $email = trim((string)($row['email'] ?? ''));
                $label = (string)($row['label'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $email . $label)) {
                    throw new \InvalidArgumentException('Invalid mail address or header text.');
                }
                $key = strtolower($email);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = ['email' => $email, 'label' => $label];
            }
            return $out;
        };
        $to = $clean(($message['replyTo'] ?? []) ?: ($message['from'] ?? []));
        if ($to === [] || $all) $to = array_merge($to, $clean($message['to'] ?? []));
        $cc = $all ? $clean($message['cc'] ?? []) : [];
        if ($to === [] && $cc === []) throw new \InvalidArgumentException('No visible reply recipient is available.');
        return ['to' => $to, 'cc' => $cc, 'bcc' => []];
    }
}
