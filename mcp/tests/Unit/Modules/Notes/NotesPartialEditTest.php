<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Notes;

use OCA\Mcp\Tests\Support\Notes\NotesTestCase;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotesPartialEditTest extends NotesTestCase {
    private const PATH = '/alice/files/Notes/Work/Before.md';

    private function plan(array $args): array {
        return $this->module->preview('notes_edit', ['id' => $this->id] + $args, 'alice');
    }
    private function write(array $args): array {
        $reply = $this->module->call('notes_edit', ['id' => $this->id] + $args, 'alice');
        return json_decode($reply['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
    private function approve(array $args): array {
        $plan = $this->plan($args);
        return $this->write($args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
    }
    public static function endings(): array {
        return [
            ["a\nb\n", 'c', "a\nb\nc\n"],
            ["a\nb", 'c', "a\nb\nc"],
            ["a\r\nb\r\n", "c\nd", "a\r\nb\r\nc\r\nd\r\n"],
            ["a\r\nb", "c\r\nd", "a\r\nb\r\nc\r\nd"],
            ['', 'c', 'c'],
        ];
    }
    #[DataProvider('endings')]
    public function testAppendPreservesLineEndings(string $before, string $append, string $expected): void {
        $this->tree->nodes[self::PATH]['content'] = $before;
        $out = $this->approve(['append' => $append]);
        self::assertSame($expected, $this->tree->nodes[self::PATH]['content']);
        self::assertSame($this->tree->nodes[self::PATH]['etag'], $out['etag']);
        self::assertArrayNotHasKey('content', $out);
    }
    public function testPartialPlanIsBoundedAndDoesNotRepeatTheNote(): void {
        $this->tree->nodes[self::PATH]['content'] = "marker\n" . str_repeat("unchanged line\n", 1000);
        $plan = $this->plan(['old' => 'marker', 'new' => 'replacement']);
        self::assertSame('replace', $plan['mode']);
        self::assertSame(['content'], $plan['changed']);
        self::assertArrayNotHasKey('content', $plan['note']['before']);
        self::assertArrayNotHasKey('content', $plan['note']['after']);
        self::assertStringContainsString('+replacement', $plan['diff']);
        self::assertLessThan(4000, strlen(json_encode($plan)));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['plan_state']);
        self::assertSame([], $this->tree->ops);
    }
    public function testReplaceKeepsEveryOtherByteAndAllowsTitle(): void {
        $this->tree->nodes[self::PATH]['content'] = "one\r\ntwo\r\n";
        $this->approve(['old' => 'two', 'new' => '', 'title' => 'After']);
        self::assertSame("one\r\n\r\n", $this->tree->nodes['/alice/files/Notes/Work/After.md']['content']);
    }
    public static function invalidModes(): array {
        return [[['content' => 'x', 'append' => 'y']], [['append' => 'y', 'old' => 'Original', 'new' => 'x']], [['old' => 'x']], [['new' => 'x']]];
    }
    #[DataProvider('invalidModes')]
    public function testInvalidModesNeverWrite(array $args): void {
        try {
            $this->plan($args);
            self::fail('Invalid mode accepted');
        } catch (\InvalidArgumentException|ToolFailure $e) {
            self::assertSame([], $this->tree->ops);
        }
    }
    public function testConfirmedPartialRequiresEtag(): void {
        $this->expectException(ToolFailure::class);
        $this->write(['append' => 'x']);
    }
    public function testConfirmedPartialRequiresPlanState(): void {
        $this->expectException(ArgumentValidationException::class);
        $this->write(['append' => 'x', 'etag' => $this->tree->nodes[self::PATH]['etag']]);
    }
    public function testStaleEtagRefusesWithCurrentEtag(): void {
        $plan = $this->plan(['append' => 'x']);
        $this->tree->nodes[self::PATH]['etag'] = 'changed-etag';
        try {
            $this->write(['append' => 'x', 'etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
            self::fail('Stale write accepted');
        } catch (ToolFailure $e) {
            self::assertStringContainsString('changed-etag', $e->getMessage());
            self::assertSame([], $this->tree->ops);
        }
    }
    public function testChangedContentUnderSameEtagReturnsNewPlan(): void {
        $plan = $this->plan(['append' => 'x']);
        $this->tree->nodes[self::PATH]['content'] = 'other edit';
        try {
            $this->write(['append' => 'x', 'etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
            self::fail('Changed content overwritten');
        } catch (PlanChanged $e) {
            self::assertNotSame($plan['plan_state'], $e->plan['plan_state']);
            self::assertNotEmpty($e->plan['warnings']);
            self::assertSame('other edit', $this->tree->nodes[self::PATH]['content']);
            self::assertSame([], $this->tree->ops);
        }
    }
    public static function changedArguments(): array {
        return [[['append' => 'y']], [['append' => 'x', 'title' => 'After']], [['old' => 'Original', 'new' => 'x']]];
    }
    #[DataProvider('changedArguments')]
    public function testPlanCannotConfirmDifferentArguments(array $args): void {
        $plan = $this->plan(['append' => 'x']);
        $this->expectException(PlanChanged::class);
        $this->write($args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
    }
    public function testReplacementCountsOverlappingMatches(): void {
        $this->tree->nodes[self::PATH]['content'] = 'banana';
        $this->expectException(ToolFailure::class);
        $this->plan(['old' => 'ana', 'new' => 'x']);
    }
    public function testOutputSizeIsCheckedBeforeApproval(): void {
        $this->expectException(ToolFailure::class);
        $this->plan(['append' => str_repeat('x', 1048576)]);
    }
    public function testSchemaDeclaresPartialInputsAndPlanState(): void {
        $defs = array_column($this->module->definitions(), null, 'name');
        $props = $defs['notes_edit']['inputSchema']['properties'];
        foreach (['append', 'old', 'new', 'plan_state'] as $key) self::assertArrayHasKey($key, $props);
        self::assertSame(65536, $props['old']['maxLength']);
        self::assertCount(7, $defs);
    }

    public function testPartialWriterRechecksContentImmediatelyBeforeWriting(): void {
        $args = ['append' => 'x'];
        $plan = $this->plan($args);
        $args += ['id' => $this->id, 'etag' => $plan['etag'], 'plan_state' => $plan['plan_state']];
        $confirmation = $this->capability(\OCA\Mcp\Modules\Notes\Editing\PartialEditConfirmation::class);
        $root = $this->capability(\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class)->folder('alice', false);
        $note = $this->capability(\OCA\Mcp\Modules\Notes\Storage\NoteLookup::class)->find($root, $this->id);
        self::assertSame("# Original\nx", $confirmation->content($root, $note, 'alice', $args, 'append'));
        $writer = $confirmation->writer($root, $note, 'alice', $args, 'append');
        $this->tree->nodes[self::PATH]['content'] = 'outside edit';
        try {
            $writer();
            self::fail('Concurrent content overwritten');
        } catch (PlanChanged $e) {
            self::assertSame('outside edit', $this->tree->nodes[self::PATH]['content']);
            self::assertSame([], $this->tree->ops);
        }
    }
    public function testPartialEditWarnsForLockAndNeverBorrowsIt(): void {
        $this->locks->put(new \OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLock($this->id, \OCP\Files\Lock\ILock::TYPE_APP, 'text'));
        $plan = $this->plan(['append' => 'x']);
        self::assertArrayHasKey('lock', $plan);
        try {
            $this->write(['append' => 'x', 'etag' => $plan['etag'], 'plan_state' => $plan['plan_state']]);
            self::fail('Locked note changed');
        } catch (\OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure $e) {
            self::assertSame([], $this->tree->ops);
            self::assertSame([], $this->locks->forbidden);
        }
    }
    public function testPartialEditReportsContentSavedIfRenameLaterLocks(): void {
        $this->tree->afterWrite = function (): void {
            $this->locks->put(new \OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLock($this->id, \OCP\Files\Lock\ILock::TYPE_APP, 'text'));
        };
        try {
            $this->approve(['append' => 'x', 'title' => 'After']);
            self::fail('Rename passed its new lock');
        } catch (\OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure $e) {
            self::assertStringEndsWith('The new content was saved; only the title was not changed.', $e->getMessage());
            self::assertSame("# Original\nx", $this->tree->nodes[self::PATH]['content']);
        }
    }
    public function testPartialEditKeepsSharedConfirmation(): void {
        $this->tree->nodes[self::PATH]['scope'] = 'shared';
        $args = ['append' => 'x'];
        $plan = $this->plan($args);
        $args += ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']];
        $asked = $this->write($args);
        self::assertTrue($asked['requiresConfirmation']);
        self::assertSame([], $this->tree->ops);
        $this->write($args + ['confirm_shared' => true]);
        self::assertSame("# Original\nx", $this->tree->nodes[self::PATH]['content']);
    }

    public function testLargePartialDiffIsBoundedAndOnlyRetainsNearbyContext(): void {
        $this->tree->nodes[self::PATH]['content'] = "marker\n" . str_repeat("unchanged line\n", 1000);
        $plan = $this->plan(['old' => 'marker', 'new' => str_repeat("new line\n", 1000)]);
        self::assertLessThanOrEqual(2020, mb_strlen($plan['diff']));
        self::assertStringEndsWith('[diff truncated]', $plan['diff']);
        self::assertLessThan(5000, strlen(json_encode($plan)));
    }
}
