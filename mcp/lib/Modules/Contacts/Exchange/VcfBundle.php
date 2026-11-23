<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Exchange;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use Sabre\VObject\Component\VCard;

final class VcfBundle {
    public const MAX_BYTES = 1048576;
    public const MAX_CARDS = 100;

    public function __construct(private ContactCard $cards) {}

    /** @return list<array{index:int,card:?VCard}> */
    public function read(string $input): array {
        if (strlen($input) > self::MAX_BYTES) {
            throw new ToolFailure(Translator::t('The vCard file exceeds the byte limit.'));
        }
        $input = str_starts_with($input, "\xEF\xBB\xBF") ? substr($input, 3) : $input;
        $lines = preg_split('/\r\n|\r|\n/', $input) ?: [];
        $frame = null;
        $rows = [];
        foreach ($lines as $line) {
            if (strcasecmp($line, 'BEGIN:VCARD') === 0) {
                if ($frame !== null) {
                    $rows[] = ['index' => count($rows) + 1, 'card' => null];
                }
                if (count($rows) >= self::MAX_CARDS) {
                    throw new ToolFailure(Translator::t('The vCard file contains too many contacts.'));
                }
                $frame = [$line];
            } elseif ($frame !== null) {
                $frame[] = $line;
                if (strcasecmp($line, 'END:VCARD') === 0) {
                    $rows[] = ['index' => count($rows) + 1, 'card' => $this->parse($frame)];
                    $frame = null;
                }
            } elseif (trim($line) !== '') {
                throw new ToolFailure(Translator::t('The file is not a vCard bundle.'));
            }
        }
        if ($frame !== null) {
            $rows[] = ['index' => count($rows) + 1, 'card' => null];
        }
        if ($rows === []) {
            throw new ToolFailure(Translator::t('The file is not a vCard bundle.'));
        }
        return $rows;
    }

    /** @param list<string> $lines */
    private function parse(array $lines): ?VCard {
        try {
            $card = $this->cards->parse(implode("\r\n", $lines) . "\r\n");
            $uid = (string)$card->UID;
            return in_array((string)($card->VERSION ?? ''), ['3.0', '4.0'], true) && trim($uid) !== '' && strlen($uid) <= 255
                ? $card
                : null;
        } catch (ToolFailure) {
            return null;
        }
    }
}
