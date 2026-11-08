<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Composition;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;

final class ReplyComposer {
    public function __construct(private MailMessageLocator $locator, private \Closure $reader,
        private MailServices $services, private \Closure $allowsTool) {}

    public function identities(string $uid, object $account, ?int $aliasId): array {
        $aliases = $this->services->aliasesService($uid)->findAll((int)$account->getId(), $uid);
        $own = [(string)$account->getEmail()];
        $sender = ['email' => (string)$account->getEmail(), 'name' => (string)$account->getName(), 'alias_id' => null];
        foreach ($aliases as $alias) {
            $own[] = (string)$alias->getAlias();
            if ($aliasId !== null && (int)$alias->getId() === $aliasId && (int)$alias->getAccountId() === (int)$account->getId()) {
                $sender = ['email' => (string)$alias->getAlias(), 'name' => (string)$alias->getName(), 'alias_id' => $aliasId];
            }
        }
        if ($aliasId !== null && $sender['alias_id'] === null) throw new ToolFailure('Sender identity is unavailable.');
        return ['sender' => $sender, 'own' => $own];
    }

    public function reply(string $uid, int $accountId, int $messageId, bool $all, array $own): array {
        if (!($this->allowsTool)($uid, 'mail_read_message')) throw new ToolFailure('Reading the source message is not permitted.');
        $found = $this->locator->locate($uid, $messageId);
        if ((int)$found['account']->getId() !== $accountId) throw new ToolFailure('The source message is unavailable in this account.');
        $row = ($this->reader)($uid)->readRow($uid, $found, 1);
        $id = (string)$found['message']->getMessageId();
        if ($id === '' || strlen($id) > 998 || preg_match('/[\r\n\x00]/', $id)) {
            throw new ToolFailure('This message has no usable Message-ID; create an explicitly new message instead.');
        }
        $addresses = [];
        foreach (['from', 'replyTo', 'to', 'cc'] as $field) {
            $group = $row[$field] ?? ['count' => 0, 'addresses' => []];
            if ($group['count'] > count($group['addresses'])) throw new ToolFailure('The complete recipient list is unavailable.');
            $addresses[$field] = array_map(static fn(array $r): array => ['email' => $r['email'], 'label' => $r['name'] ?? ''], $group['addresses']);
        }
        return ReplyRecipients::derive($addresses, $own, $all)
            + ['in_reply_to' => $id, 'reply_to_message_id' => $messageId];
    }
}
