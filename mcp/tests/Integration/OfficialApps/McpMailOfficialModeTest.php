<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\OfficialApps;

use OCA\Mcp\Tests\Integration\Mail\ImapProbe;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
use OCP\IConfig;
use OCP\Server;

final class McpMailOfficialModeTest extends ExclusionTestCase {
    public function testOfficialModeKeepsPrivateDraftsOutOfNativeMailAndHonorsRevocation(): void {
        if (getenv('MCP_IT_WITH_MAIL') !== '1' || getenv('MCP_IT_MAIL_GUARD') !== '0') {
            self::markTestSkipped('Official disposable Mail cell only');
        }
        $draftTools = ['mail_read_owned_drafts', 'mail_write_owned_draft', 'mail_delete_owned_draft'];
        $legacyTools = ['mail_write_draft', 'mail_send'];
        $mode = 'app.mcp.official-apps.mail';
        $config = Server::get(IConfig::class);
        $previous = $config->getSystemValue($mode, null);
        $snapshot = ToolPermissionFixture::capture([self::ALICE, self::BOB], [...$draftTools, ...$legacyTools]);
        try {
            $config->setSystemValue($mode, false);
            ToolPermissionFixture::assign(self::ALICE, array_fill_keys($legacyTools, 'allow'));
            $legacy = LoginFactory::basic(self::ALICE);
            self::assertContains('mail_write_draft', $this->published($legacy));

            $config->setSystemValue($mode, true);
            self::assertNotContains('mail_write_draft', $this->published($legacy));
            foreach ($legacyTools as $tool) {
                $reply = (new McpClient($legacy))->tool($tool);
                self::assertSame('Unknown tool', $reply->body['error']['message'] ?? null);
            }
            self::assertNotContains('mail_read_owned_drafts', $this->published($legacy));
            foreach ([self::ALICE, self::BOB] as $uid) {
                ToolPermissionFixture::assign($uid, array_fill_keys($draftTools, 'allow'));
            }

            $alice = new McpClient(LoginFactory::bearer(self::ALICE));
            $bob = new McpClient(LoginFactory::basic(self::BOB));
            $ownAccount = $this->account(self::ALICE);
            $bobAccount = $this->account(self::BOB);
            $nativeBefore = $this->nativeDraftCount($ownAccount);
            $imap = new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'), (int)getenv('MCP_IT_IMAP_PORT'), 'alice', (string)getenv('MCP_IT_IMAP_PASSWORD'));
            try {
                $remoteBefore = $imap->state();
                $input = ['account_id' => $ownAccount, 'subject' => 'Private fixture draft', 'body_plain' => 'Private fixture text'];
                $preview = $alice->tool('mail_write_owned_draft', $input);
                self::assertTrue($preview->ok(), $preview->describe());
                self::assertTrue($preview->structured()['requiresConfirmation']);
                $confirmed = $input + ['plan_state' => $preview->structured()['plan_state']];
                $aliceBasic = new McpClient(LoginFactory::basic(self::ALICE));
                $otherConnection = $aliceBasic->write('mail_write_owned_draft', $confirmed);
                self::assertTrue($otherConnection->isError() || ($otherConnection->structured()['requiresConfirmation'] ?? false));
                $write = $alice->write('mail_write_owned_draft', $confirmed);
                self::assertTrue($write->ok(), $write->describe());
                $draft = $write->json()['draft'];
                self::assertSame(1, $draft['revision']);
                $replay = $alice->write('mail_write_owned_draft', $confirmed);
                self::assertTrue($replay->isError() || ($replay->structured()['requiresConfirmation'] ?? false));
                self::assertCount(1, $alice->tool('mail_read_owned_drafts', ['account_id' => $ownAccount])->json()['drafts']);
                $id = $draft['owned_draft_id'];
                $own = ['account_id' => $ownAccount, 'owned_draft_id' => $id];

                $read = $alice->tool('mail_read_owned_drafts', $own);
                self::assertTrue($read->ok(), $read->describe());
                self::assertSame($input['body_plain'], $read->json()['draft']['body_plain']);
                self::assertTrue($bob->tool('mail_read_owned_drafts', $own)->isError());
                self::assertTrue($bob->tool('mail_read_owned_drafts', ['account_id' => $bobAccount, 'owned_draft_id' => $id])->isError());

                $replacement = $own + ['revision' => 1, 'subject' => 'New fixture subject', 'body_plain' => 'Edited text'];
                $plan = $alice->tool('mail_write_owned_draft', $replacement);
                self::assertTrue($plan->ok(), $plan->describe());
                $arguments = $replacement + ['plan_state' => $plan->structured()['plan_state']];
                $edit = $alice->write('mail_write_owned_draft', $arguments);
                self::assertTrue($edit->ok(), $edit->describe());
                self::assertSame(2, $edit->json()['draft']['revision']);
                $stale = $alice->write('mail_write_owned_draft', $arguments);
                self::assertTrue($stale->isError() || ($stale->structured()['requiresConfirmation'] ?? false));
                self::assertSame(2, $alice->tool('mail_read_owned_drafts', $own)->json()['draft']['revision']);

                ToolPermissionFixture::assign(self::ALICE, ['mail_read_owned_drafts' => 'deny']);
                self::assertFalse($alice->tool('mail_read_owned_drafts', $own)->ok());
                $delete = $own + ['revision' => 2];
                $drop = $alice->tool('mail_delete_owned_draft', $delete);
                self::assertTrue($drop->ok(), $drop->describe());
                self::assertArrayNotHasKey('subject', $drop->structured()['draft']);
                self::assertArrayNotHasKey('body_plain', $drop->structured()['draft']);
                $removed = $alice->write('mail_delete_owned_draft', $delete + ['plan_state' => $drop->structured()['plan_state']]);
                self::assertTrue($removed->ok(), $removed->describe());
                self::assertSame($id, $removed->json()['deleted_owned_draft_id']);
                self::assertSame($nativeBefore, $this->nativeDraftCount($ownAccount));
                self::assertSame($remoteBefore, $imap->state());
            } finally {
                $imap->close();
            }
        } finally {
            if ($previous === null) {
                $config->deleteSystemValue($mode);
            } else {
                $config->setSystemValue($mode, $previous);
            }
            ToolPermissionFixture::restore($snapshot);
        }
    }

    private function published(Credential $credential): array {
        $response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
            'Authorization: ' . $credential->authorization(),
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        self::assertSame(200, $response['status']);
        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        return array_column($body['result']['tools'], 'name');
    }

    private function nativeDraftCount(int $accountId): int {
        $query = $this->official()->getQueryBuilder();
        $result = $query->select($query->func()->count('*'))->from('mail_local_messages')
            ->where($query->expr()->eq('account_id', $query->createNamedParameter($accountId)))->executeQuery();
        try {
            return (int)$result->fetchOne();
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * @param string $uid owner provisioned by the official Mail cell
     * @return int sole account id, read directly without loading a Mail service into this process
     */
    private function account(string $uid): int {
        $query = $this->official()->getQueryBuilder();
        $result = $query->select('id')->from('mail_accounts')->where($query->expr()->eq('user_id', $query->createNamedParameter($uid)))->executeQuery();
        try { $rows = $result->fetchAll(); } finally { $result->closeCursor(); }
        self::assertCount(1, $rows);
        return (int)$rows[0]['id'];
    }

}
