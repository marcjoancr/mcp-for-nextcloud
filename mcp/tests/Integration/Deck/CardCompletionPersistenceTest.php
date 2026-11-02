<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Completion edits persist exactly as requested and remain isolated to the card's authorized user. */
#[Group('deck')]
final class CardCompletionPersistenceTest extends IntegrationTestCase {
    /** @return array<string, mixed> */
    private function payload(ToolReply $reply): array {
        self::assertTrue($reply->ok(), $reply->describe());
        $data = $reply->structured();
        return $data !== [] ? $data : $reply->json();
    }

    /** @param list<string> $uids @return array{service:string,users:array<string,array<string,string>>} */
    private function snapshotPolicy(array $uids): array {
        $config = Server::get(IConfig::class);
        $keys = [GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_deck_read', 'grant_deck_edit'];
        $users = [];
        foreach ($uids as $uid) {
            foreach ($keys as $key) {
                $users[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__mcp_it_missing__');
            }
        }
        return ['service' => $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__mcp_it_missing__'), 'users' => $users];
    }

    /** @param array{service:string,users:array<string,array<string,string>>} $snapshot */
    private function restorePolicy(array $snapshot): void {
        $config = Server::get(IConfig::class);
        if ($snapshot['service'] === '__mcp_it_missing__') {
            $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
        } else {
            $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $snapshot['service']);
        }
        foreach ($snapshot['users'] as $uid => $values) {
            foreach ($values as $key => $value) {
                if ($value === '__mcp_it_missing__') {
                    $config->deleteUserValue($uid, 'mcp', $key);
                } else {
                    $config->setUserValue($uid, 'mcp', $key, $value);
                }
            }
        }
    }

    public function testCompletionTransitionsPreserveTheNativeTimestampAndOtherCardFields(): void {
        $owner = DeckFixture::OWNER;
        $client = new McpClient(LoginFactory::basic($owner));
        $policy = Server::get(GrantPolicy::class);
        $snapshot = $this->snapshotPolicy([$owner]);
        $fixture = null;
        try {
            $policy->setGlobalEnabled(true);
            $policy->setEligible($owner, true);
            $policy->setConnected($owner, true);
            foreach (['read', 'edit'] as $operation) { $policy->setGrant($owner, 'deck', $operation, true); }
            $fixture = DeckFixture::run($owner, 'completion-seed');
            $cardId = $fixture['card'];

            self::assertNull(DeckFixture::run($owner, 'completion-read', ['card' => $cardId])['done']);
            $policy->setGrant($owner, 'deck', 'edit', false);
            $grantDenied = $client->tool('deck_edit_card', ['cardId' => $cardId, 'completed' => true]);
            self::assertSame(-32602, $grantDenied->body['error']['code'] ?? null, 'the MCP catalog refuses deck_edit_card when deck.edit is denied');
            self::assertSame('Unknown tool', $grantDenied->body['error']['message'] ?? null);
            self::assertNull(DeckFixture::run($owner, 'completion-read', ['card' => $cardId])['done']);
            $policy->setGrant($owner, 'deck', 'edit', true);
            $completed = $this->payload($client->write('deck_edit_card', ['cardId' => $cardId, 'completed' => true]));
            self::assertIsInt($completed['done']);
            $firstDone = DeckFixture::run($owner, 'completion-read', ['card' => $cardId]);
            self::assertSame($completed['done'], $firstDone['done']);
            self::assertSame('Native completion target', $firstDone['title']);
            self::assertSame('Preserve this description', $firstDone['description']);

            $again = $this->payload($client->write('deck_edit_card', ['cardId' => $cardId, 'completed' => true]));
            self::assertSame($firstDone['done'], $again['done'], 'repeating completed=true preserves the original native timestamp');
            self::assertSame($firstDone, DeckFixture::run($owner, 'completion-read', ['card' => $cardId]));

            $cleared = $this->payload($client->write('deck_edit_card', ['cardId' => $cardId, 'completed' => false]));
            self::assertNull($cleared['done']);
            self::assertNull(DeckFixture::run($owner, 'completion-read', ['card' => $cardId])['done']);
        } finally {
            if ($fixture !== null) { DeckFixture::run($owner, 'completion-cleanup', $fixture); }
            $this->restorePolicy($snapshot);
        }
    }

    public function testNativeReadOnlyBoardCannotBeEditedEvenWhenTheMcpGrantAllowsIt(): void {
        $owner = DeckFixture::OWNER;
        $reader = DeckFixture::READER;
        $policy = Server::get(GrantPolicy::class);
        $snapshot = $this->snapshotPolicy([$owner, $reader]);
        $fixture = null;
        try {
            $policy->setGlobalEnabled(true);
            $policy->setEligible($owner, true);
            $policy->setConnected($owner, true);
            foreach (['read', 'edit'] as $operation) { $policy->setGrant($owner, 'deck', $operation, true); }
            $fixture = DeckFixture::run($owner, 'completion-seed');
            $policy->setEligible($reader, true);
            $policy->setConnected($reader, true);
            foreach (['read', 'edit'] as $operation) { $policy->setGrant($reader, 'deck', $operation, true); }
            $client = new McpClient(LoginFactory::basic($reader));
            self::assertSame(['canRead' => true], DeckFixture::run($reader, 'completion-can-read', $fixture));
            $read = $client->tool('deck_read_card', ['cardId' => $fixture['card']]);
            self::assertTrue($read->ok(), 'the user has native read-only board access: ' . $read->describe());
            $edit = $client->tool('deck_edit_card', ['cardId' => $fixture['card'], 'completed' => true]);
            self::assertTrue($edit->isError(), 'native Deck read-only ACL blocks the edit despite an MCP edit grant: ' . $edit->describe());
            self::assertNull(DeckFixture::run($owner, 'completion-read', ['card' => $fixture['card']])['done']);
        } finally {
            if ($fixture !== null) { DeckFixture::run($owner, 'completion-cleanup', $fixture); }
            $this->restorePolicy($snapshot);
        }
    }

    public function testAnotherUserCannotCompleteAnOwnersPrivateCard(): void {
        $owner = DeckFixture::OWNER;
        $outsider = DeckFixture::OUTSIDER;
        $policy = Server::get(GrantPolicy::class);
        $snapshot = $this->snapshotPolicy([$owner, $outsider]);
        $fixture = null;
        try {
            $policy->setGlobalEnabled(true);
            $policy->setEligible($owner, true);
            $policy->setConnected($owner, true);
            foreach (['read', 'edit'] as $operation) { $policy->setGrant($owner, 'deck', $operation, true); }
            $fixture = DeckFixture::run($owner, 'completion-seed');
            $policy->setEligible($outsider, true);
            $policy->setConnected($outsider, true);
            $policy->setGrant($outsider, 'deck', 'edit', true);
            $reply = (new McpClient(LoginFactory::basic($outsider)))->tool('deck_edit_card', ['cardId' => $fixture['card'], 'completed' => true]);
            self::assertTrue($reply->isError(), $reply->describe());
            self::assertNull(DeckFixture::run($owner, 'completion-read', ['card' => $fixture['card']])['done']);
        } finally {
            if ($fixture !== null) { DeckFixture::run($owner, 'completion-cleanup', $fixture); }
            $this->restorePolicy($snapshot);
        }
    }
}
