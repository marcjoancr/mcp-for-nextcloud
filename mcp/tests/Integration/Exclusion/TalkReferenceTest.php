<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Tests\Integration\Support\McpClient;

/**
 * V9, phase 1 (real): a real Talk conversation with a real room share of a file. When the file is excluded by a tag,
 * directly or through an ancestor, talk_read_messages keeps the message and its type and withholds the attachment id
 * for every participant; attaching or quoting the excluded file is refused like a file that does not exist. The
 * phase 2 case, a file only outside the scope of Files with no tag, belongs to B8 and is not claimed here.
 */
final class TalkReferenceTest extends ExclusionTestCase {
    /** @return array{string, int} conversation token and attachment id of a freshly attached file */
    private function conversationWithAttachment(McpClient $alice): array {
        $room = $alice->write('talk_create_group', ['name' => 'mcp-it ' . $this->run, 'participants' => [self::BOB]]);
        $this->assertTrue($room->ok(), 'talk_create_group: ' . $room->describe());
        $token = (string)($room->json()['conversation_token'] ?? '');
        $this->assertNotSame('', $token, 'the conversation has a token: ' . $room->describe());

        $this->makeFile('home', 'open/talk.txt', 'talk attachment');
        $attach = $alice->write('talk_attach_file', ['conversation_token' => $token, 'path' => $this->path('home', 'open', 'talk.txt')]);
        $this->assertTrue($attach->ok(), 'talk_attach_file: ' . $attach->describe());
        $id = $attach->json()['attachmentId'] ?? null;
        $this->assertIsInt($id, 'the attachment has an id: ' . $attach->describe());
        return [$token, $id];
    }

    /** @return list<array<string, mixed>> */
    private function messages(McpClient $client, string $token): array {
        $reply = $client->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
        $this->assertTrue($reply->ok(), 'talk_read_messages: ' . $reply->describe());
        $messages = $reply->json()['messages'] ?? null;
        $this->assertIsArray($messages, $reply->describe());
        return $messages;
    }

    /** @param list<array<string, mixed>> $messages */
    private function attachmentIds(array $messages): array {
        return array_values(array_filter(array_map(static fn (array $m) => $m['attachmentId'] ?? null, $messages), static fn ($id) => $id !== null));
    }

    public function testTheAttachmentIdOfAFileExcludedByATagIsWithheldFromEveryParticipant(): void {
        $alice = $this->mcp();
        $bob = $this->mcp(self::BOB);
        [$token, $id] = $this->conversationWithAttachment($alice);
        $this->exclude();

        // Control: an exclusion is configured, the file is not excluded, and the id is reported to both.
        $this->assertSame([$id], $this->attachmentIds($this->messages($alice, $token)), 'alice sees the id before the tag');
        $this->assertSame([$id], $this->attachmentIds($this->messages($bob, $token)), 'bob sees the id before the tag');
        $before = count($this->messages($alice, $token));

        $file = $this->node(self::ALICE, $this->path('home', 'open', 'talk.txt'));
        $this->tag($file);
        foreach (['alice' => $alice, 'bob' => $bob] as $who => $client) {
            $messages = $this->messages($client, $token);
            $this->assertSame([], $this->attachmentIds($messages), "$who: the id of the file excluded by its own tag is withheld");
            $this->assertCount($before, $messages, "$who: the message itself stays");
            $reply = $client->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
            $this->assertStringNotContainsString('talk.txt', $reply->raw, "$who: no file name in the answer");
        }

        $this->untag($file);
        $this->assertSame([$id], $this->attachmentIds($this->messages($bob, $token)), 'the id returns when the tag is removed');

        $this->tag($this->node(self::ALICE, $this->path('home', 'open')));
        foreach (['alice' => $alice, 'bob' => $bob] as $who => $client) {
            $this->assertSame([], $this->attachmentIds($this->messages($client, $token)), "$who: the id of a file under an excluded ancestor is withheld");
        }
    }

    public function testAttachingOrQuotingAnExcludedFileIsRefusedLikeAMissingOneAndPostsNothing(): void {
        $alice = $this->mcp();
        [$token, $id] = $this->conversationWithAttachment($alice);
        $before = count($this->messages($alice, $token));
        $this->makeFile('home', 'locked/sensitive.txt', 'sensitive');
        $this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
        $this->tag($this->node(self::ALICE, $this->path('home', 'open', 'talk.txt')));
        $this->exclude();

        $excluded = ['conversation_token' => $token, 'path' => $this->path('home', 'locked', 'sensitive.txt')];
        $missing = ['conversation_token' => $token, 'path' => $this->path('home', 'locked', 'nothing-here.txt')];
        $reference = $alice->tool('talk_attach_file', ['conversation_token' => $token, 'path' => '/mcp-it-missing-' . $this->run . '.txt']);
        $this->assertTrue($reference->isError(), 'a missing file is refused: ' . $reference->describe());
        foreach ([$alice->tool('talk_attach_file', $excluded), $alice->write('talk_attach_file', $excluded),
            $alice->tool('talk_attach_file', $missing), $alice->write('talk_attach_file', $missing)] as $reply) {
            $this->assertTrue($reply->isError(), 'attaching a file under an excluded folder is refused: ' . $reply->describe());
            $this->assertSame($reference->text(), $reply->text(), 'and reads like a file that does not exist');
        }

        $bogus = $alice->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => 2000000000]);
        $this->assertTrue($bogus->isError(), 'quoting an attachment that does not exist is refused: ' . $bogus->describe());
        foreach ([$alice->tool('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id]),
            $alice->write('talk_quote_file', ['conversation_token' => $token, 'attachment_id' => $id])] as $reply) {
            $this->assertTrue($reply->isError(), 'quoting the attachment of an excluded file is refused: ' . $reply->describe());
            $this->assertSame($bogus->text(), $reply->text(), 'and reads like an attachment that does not exist');
        }
        $this->assertCount($before, $this->messages($alice, $token), 'nothing was posted');
    }
}
