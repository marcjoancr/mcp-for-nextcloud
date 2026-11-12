<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Composition;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\MailBodyText;

/** Native compose preferences, applied once to a new local draft before approval. No transport. */
final class MessageComposer {
    public function __construct(private MailServices $native) {}

    public function preferences(string $uid, int $account, ?int $alias): array {
        $a = $this->native->accountService($uid)->find($uid, $account)->getMailAccount();
        $identity = $alias === null ? $a : $this->native->aliasesService($uid)->find($alias, $uid);
        if ($alias !== null && (int)$identity->getAccountId() !== $account) {
            throw new ToolFailure('Sender identity is unavailable.');
        }
        $signature = $identity->getSignature() ?? '';
        $mode = $a->getEditorMode();
        if (!is_string($signature) || strlen($signature) > 2097152 || !in_array($mode, ['plaintext', 'richtext', 'html'], true)) {
            throw new ToolFailure('Native composition settings exceed the supported draft limits. Use Mail.');
        }
        return ['signature' => $signature, 'editor_mode' => $mode,
            'signature_above_quote' => (bool)$a->isSignatureAboveQuote(),
            'reply_on_top' => $this->native->userPreferences($uid)->getPreference($uid, 'reply-mode', 'top') === 'top'];
    }

    /** $quote contains complete, bounded plain source text, never raw inbound HTML. */
    public function compose(array $fields, array $args, array $preferences, ?array $quote): array {
        $signature = $preferences['signature'];
        $signatureHtml = str_contains($signature, '>') ? $signature : self::html($signature);
        $signaturePlain = str_contains($signature, '>') ? MailBodyText::htmlToText($signature) : $signature;
        $html = $args['is_html'] ?? (array_key_exists('body_html', $args) && !array_key_exists('body_plain', $args)
            || in_array($preferences['editor_mode'], ['richtext', 'html'], true));
        $body = $html
            ? (array_key_exists('body_html', $args) ? $fields['body_html'] : self::html($fields['body_plain']))
            : (array_key_exists('body_plain', $args) ? $fields['body_plain'] : MailBodyText::htmlToText($fields['body_html']));
        // Select the active body before promotion: an inactive HTML alternative must never reappear.
        // The native composer promotes image signatures rather than silently dropping them.
        $doc = new \DOMDocument();
        if ($signatureHtml !== '') {
            $previous = libxml_use_internal_errors(true);
            try { $doc->loadHTML('<?xml encoding="UTF-8">' . $signatureHtml, LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            if (!$html && $doc->getElementsByTagName('img')->length > 0) {
                $body = self::html($body);
                $html = true;
            }
        }
        if ($html) {
            $sig = $signature === '' ? '' : '<div class="signature">' . $signatureHtml . '</div>';
            $quoted = $quote === null ? '' : '<div class="quote">' . self::html($quote['attribution'] ?? '')
                . '<blockquote>' . self::html($quote['text']) . '</blockquote></div>';
            // A caller can provide an existing native signature; never insert a second one.
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try { $document->loadHTML('<?xml encoding="UTF-8"><body>' . $body . '</body>', LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            $xpath = new \DOMXPath($document);
            if ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " signature ") and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " quote ")])]')->length > 0) $sig = '';
        } else {
            $sig = $signaturePlain === '' ? '' : "-- \n" . $signaturePlain;
            $quoted = $quote === null ? '' : ($quote['attribution'] ?? '') . "\n" . '> ' . str_replace("\n", "\n> ", $quote['text']);
            if ($sig !== '' && str_ends_with(rtrim($body), rtrim($sig))) $sig = '';
        }
        // Native findPosition: signature above the first quote, otherwise document end.
        $parts = $preferences['reply_on_top'] ? [$body, $quoted] : [$quoted, $body];
        if ($preferences['signature_above_quote'] && $quoted !== '') array_splice($parts, $preferences['reply_on_top'] ? 1 : 0, 0, [$sig]);
        else $parts[] = $sig;
        $body = implode($html ? '' : "\n\n", array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
        $fields['is_html'] = $html;
        $fields['body_plain'] = $html ? '' : $body;
        $fields['body_html'] = $html ? $body : '';
        \OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields($fields);
        $fields['composition'] = ['mode' => 'user_preferences', 'reply_on_top' => $preferences['reply_on_top'],
            'signature_above_quote' => $preferences['signature_above_quote'], 'signature_included' => $sig !== '',
            'quote_included' => $quote !== null];
        return $fields;
    }

    private static function html(string $text): string {
        return str_replace(["\r\n", "\r", "\n"], '<br>', htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
