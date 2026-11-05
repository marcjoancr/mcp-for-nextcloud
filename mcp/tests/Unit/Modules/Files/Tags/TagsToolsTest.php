<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Tags;

use OCA\Mcp\Modules\Files\Tools\ListTags;
use OCA\Mcp\Modules\Files\Tools\CreateTag;
use OCA\Mcp\Modules\Files\Tools\SetFileTags;
use OCA\Mcp\Modules\Files\Tags\NativeTags;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

final class TagsToolsTest extends TestCase {
    public function testPublicToolsDeclareSeparateTagGrantsAndWriteContracts(): void {
        $list = ListTags::definition();
        $create = CreateTag::definition();
        $set = SetFileTags::definition();

        self::assertSame('tags_read', $list->operation);
        self::assertSame('tags_create', $create->operation);
        self::assertSame('tags_edit', $set->operation);
        self::assertContains(\OCA\Mcp\Mcp\Tools\WriteTool::class, class_implements(CreateTag::class));
        self::assertContains(\OCA\Mcp\Mcp\Tools\WriteTool::class, class_implements(SetFileTags::class));
        self::assertSame(['name'], $create->inputSchema['required']);
        self::assertSame(['paths', 'add'], $set->inputSchema['required']);
    }

    public function testConfiguredBackupFilesCannotBeRetagged(): void {
        $manager = $this->createMock(ISystemTagManager::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createStub(IUser::class));
        $root = $this->createMock(IRootFolder::class);
        $root->expects(self::never())->method('getUserFolder');
        $service = $this->nativeTags($manager, $users, root: $root);
        $this->expectException(ToolFailure::class);
        $service->setPreview(['/MCP backups/image.jpg'], ['Tacos'], [], 'alice');
    }

    public function testListingExcludesInvisibleAndNonAssignableCategories(): void {
        $user = $this->createMock(IUser::class);
        $visible = $this->tag('1', 'Tacos', true, true);
        $private = $this->tag('2', 'Private', false, true);
        $restricted = $this->tag('3', 'Admin only', true, false);
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->method('getAllTags')->willReturn([$visible, $private, $restricted]);
        $manager->method('canUserSeeTag')->willReturnMap([
            [$visible, $user, true], [$private, $user, true], [$restricted, $user, true],
        ]);
        $manager->method('canUserAssignTag')->willReturnMap([
            [$visible, $user, true], [$private, $user, true], [$restricted, $user, false],
        ]);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);

        $result = $this->nativeTags($manager, $users)->list('alice');

        self::assertSame([['id' => '1', 'name' => 'Tacos']], $result['tags']);
    }

    public function testConfiguredProtectedTagsAreNotListedOrAssignable(): void {
        $user = $this->createMock(IUser::class);
        $tag = $this->tag('2', 'Do not show', true, true);
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->method('getAllTags')->willReturn([$tag]);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $guard = $this->getMockBuilder(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->disableOriginalConstructor()->onlyMethods(['getHiddenTagIds'])->getMock();
        $guard->method('getHiddenTagIds')->willReturn(['2']);
        $tags = $this->nativeTags($manager, $users, null, $guard);

        self::assertSame([], $tags->list('alice')['tags']);
        $this->expectException(ToolFailure::class);
        $tags->createPreview('Do not show', 'alice');
    }

    public function testCurrentTagsRefuseAPathBeforeReadingTagAssignmentsWhenVisibilityRejectsIt(): void {
        $user = $this->createMock(IUser::class);
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
        $folder = $this->createMock(Folder::class);
        $folder->expects(self::once())->method('get')->with('secret.txt')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $guard = $this->getMockBuilder(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->disableOriginalConstructor()->onlyMethods(['assertVisible'])->getMock();
        $guard->expects(self::once())->method('assertVisible')->with($file)->willThrowException(new ToolFailure(ToolFailure::NOT_FOUND));
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->expects(self::never())->method('getTagIdsForObjects');
        $manager = $this->createMock(ISystemTagManager::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);

        $this->expectException(ToolFailure::class);
        $this->nativeTags($manager, $users, $root, $guard, $mapper)->list('alice', 'secret.txt');
    }

    public function testNameValidationUsesTheNativeSixtyFourByteLimitWithoutTruncatingUtf8(): void {
        $tags = $this->nativeTags($this->createMock(ISystemTagManager::class), $this->createMock(IUserManager::class));

        $this->expectException(ToolFailure::class);
        $tags->createPreview(str_repeat('🍕', 17), 'alice');
    }

    public function testInvalidUtf8TagNameIsRefused(): void {
        $tags = $this->nativeTags($this->createMock(ISystemTagManager::class), $this->createMock(IUserManager::class));
        $this->expectException(ToolFailure::class);
        $tags->createPreview("bad\xFF", 'alice');
    }

    public function testAddAndRemoveCannotNameTheSameNativeTagWithDifferentCase(): void {
        $tags = $this->nativeTags($this->createMock(ISystemTagManager::class), $this->createMock(IUserManager::class));
        $this->expectException(ToolFailure::class);
        $tags->setPreview(['/report.txt'], ['Tacos'], ['tacos'], 'alice');
    }

    public function testCreatePreviewReusesCaseInsensitiveVisibleAssignableDuplicate(): void {
        $user = $this->createMock(IUser::class);
        $tag = $this->tag('17', 'Tacos', true, true);
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->expects(self::once())->method('getAllTags')->with(null, 'tacos')->willReturn([$tag]);
        $manager->method('canUserSeeTag')->with($tag, $user)->willReturn(true);
        $manager->method('canUserAssignTag')->with($tag, $user)->willReturn(true);
        $manager->expects(self::never())->method('canUserCreateTag');
        $manager->expects(self::never())->method('createTag');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);

        $plan = $this->nativeTags($manager, $users)->createPreview('tacos', 'alice');

        self::assertTrue($plan['reused']);
        self::assertSame('17', $plan['tag_id']);
    }

    public function testNativeCreationPolicyIsCheckedBeforeTagMutation(): void {
        $user = $this->createMock(IUser::class);
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->method('getAllTags')->with(null, 'Tacos')->willReturn([]);
        $manager->method('canUserCreateTag')->with($user)->willReturn(false);
        $manager->expects(self::never())->method('createTag');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);

        $this->expectException(ToolFailure::class);
        $this->nativeTags($manager, $users)->createPreview('Tacos', 'alice');
    }

    public function testConfirmedCreateUsesNativePolicyAndCreatesOnlyPublicAssignableTag(): void {
        $user = $this->createMock(IUser::class);
        $tag = $this->tag('9', 'Tacos', true, true);
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->method('getAllTags')->willReturn([]);
        $manager->method('canUserCreateTag')->with($user)->willReturn(true);
        $manager->method('canUserSeeTag')->with($tag, $user)->willReturn(true);
        $manager->method('canUserAssignTag')->with($tag, $user)->willReturn(true);
        $manager->expects(self::once())->method('createTag')->with('Tacos', true, true)->willReturn($tag);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $tags = $this->nativeTags($manager, $users);

        $plan = $tags->createPreview('Tacos', 'alice');
        self::assertSame(64, strlen($plan['plan_state']));
        $result = $tags->create('Tacos', 'alice', $plan['plan_state']);

        self::assertTrue($result['created']);
        self::assertSame(['id' => '9', 'name' => 'Tacos'], $result['tag']);
    }

    public function testBobPathIsResolvedAgainstBobsHomeAndRejectedBeforeTagLookup(): void {
        $user = $this->createMock(IUser::class);
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
        $bobFolder = $this->createMock(Folder::class);
        $bobFolder->expects(self::once())->method('get')->with('alice/secret.txt')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->expects(self::once())->method('getUserFolder')->with('bob')->willReturn($bobFolder);
        $guard = $this->getMockBuilder(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->disableOriginalConstructor()->onlyMethods(['assertVisible'])->getMock();
        $guard->expects(self::once())->method('assertVisible')->with($file)->willThrowException(new ToolFailure(ToolFailure::NOT_FOUND));
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->method('getAllTags')->willReturn([]);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('bob')->willReturn($user);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->expects(self::never())->method('getTagIdsForObjects');

        $this->expectException(ToolFailure::class);
        $this->nativeTags($manager, $users, $root, $guard, $mapper)->list('bob', 'alice/secret.txt');
    }

    public function testNonWritableFileIsRefusedBeforeNativeTagPolicyOrMutation(): void {
        [$tags, $manager, $mapper] = $this->assignmentFixture(false);
        $this->expectException(ToolFailure::class);
        $tags->setPreview(['/report.txt'], ['Tacos'], [], 'alice');
    }

    public function testRestrictedTagCannotBeAssignedAndPreviewDoesNotMutate(): void {
        [$tags, $manager, $mapper] = $this->assignmentFixture(true, false);
        $mapper->expects(self::never())->method('assignTags');
        $mapper->expects(self::never())->method('unassignTags');

        $this->expectException(ToolFailure::class);
        $tags->setPreview(['/report.txt'], ['Tacos'], [], 'alice');
    }

    public function testChangedCurrentTagsInvalidateConfirmationBeforeAnyAssignment(): void {
        $first = $this->createMock(ISystemTag::class);
        $first->method('getId')->willReturn('1');
        $first->method('isUserVisible')->willReturn(true);
        $first->method('isUserAssignable')->willReturn(true);
        $first->method('getName')->willReturnOnConsecutiveCalls('Original', 'Changed');
        [$tags, $manager, $mapper] = $this->assignmentFixture(true, true, $first);
        $plan = $tags->setPreview(['/report.txt'], ['Tacos'], [], 'alice');
        $mapper->expects(self::never())->method('assignTags');
        $mapper->expects(self::never())->method('unassignTags');

        $this->expectException(PlanChanged::class);
        $tags->set(['/report.txt'], ['Tacos'], [], 'alice', $plan['plan_state'], false);
    }

    public function testRecreatedSameNameTagWithDifferentNativeIdInvalidatesConfirmation(): void {
        $current = $this->createMock(ISystemTag::class);
        $current->method('getId')->willReturnOnConsecutiveCalls('1', '1', '3', '3');
        $current->method('getName')->willReturn('Old');
        $current->method('isUserVisible')->willReturn(true);
        $current->method('isUserAssignable')->willReturn(true);
        [$tags, $manager, $mapper] = $this->assignmentFixture(true, true, $current);
        $plan = $tags->setPreview(['/report.txt'], ['Tacos'], [], 'alice');
        $mapper->expects(self::never())->method('assignTags');
        $mapper->expects(self::never())->method('unassignTags');

        $this->expectException(PlanChanged::class);
        $tags->set(['/report.txt'], ['Tacos'], [], 'alice', $plan['plan_state'], false);
    }

    public function testConfirmedAssignmentAddsAndRemovesOnlyRequestedNativeIds(): void {
        [$tags, $manager, $mapper] = $this->assignmentFixture(true);
        $plan = $tags->setPreview(['/report.txt'], ['Tacos'], ['Old'], 'alice');
        self::assertSame('42', $plan['changes'][0]['file_id']);
        self::assertSame(['2'], $plan['changes'][0]['add_ids']);
        self::assertSame(['1'], $plan['changes'][0]['remove_ids']);
        $mapper->expects(self::once())->method('assignTags')->with('42', 'files', ['2']);
        $mapper->expects(self::once())->method('unassignTags')->with('42', 'files', ['1']);

        $result = $tags->set(['/report.txt'], ['Tacos'], ['Old'], 'alice', $plan['plan_state'], false);

        self::assertSame(['/report.txt'], $result['updated']);
        self::assertSame(['Tacos'], $result['added']);
        self::assertSame(['Old'], $result['removed']);
    }

    public function testSharedWriteRequiresSeparateConfirmationBeforeAssignment(): void {
        $notice = ['requiresConfirmation' => true, 'scope' => 'shared', 'resource' => '/report.txt', 'message' => 'Confirm shared change.'];
        $shared = $this->createMock(SharedWriteGuard::class);
        $shared->method('guard')->willReturnCallback(static fn ($node, $uid, $path, $confirmed) => $confirmed ? null : $notice);
        [$tags, $manager, $mapper] = $this->assignmentFixture(true, true, null, $shared);
        $plan = $tags->setPreview(['/report.txt'], ['Tacos'], [], 'alice');
        self::assertTrue($plan['changes'][0]['shared']['requiresConfirmation']);
        $mapper->expects(self::once())->method('assignTags')->with('42', 'files', ['2']);

        $unconfirmed = $tags->set(['/report.txt'], ['Tacos'], [], 'alice', $plan['plan_state'], false);
        self::assertSame($notice, $unconfirmed);

        $confirmed = $tags->set(['/report.txt'], ['Tacos'], [], 'alice', $plan['plan_state'], true);
        self::assertSame(['/report.txt'], $confirmed['updated']);
    }

    private function nativeTags(ISystemTagManager $manager, IUserManager $users, ?IRootFolder $root = null, ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $guard = null, ?ISystemTagObjectMapper $mapper = null, ?SharedWriteGuard $sharedWrites = null): NativeTags {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturn('test-secret');
        $shared = $sharedWrites ?? $this->getMockBuilder(SharedWriteGuard::class)->disableOriginalConstructor()->getMock();
        if ($sharedWrites === null) $shared->method('guard')->willReturn(null);
        return new NativeTags($root ?? $this->createMock(IRootFolder::class), $manager, $mapper ?? $this->createMock(ISystemTagObjectMapper::class), $users, $guard ?? $this->getMockBuilder(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->disableOriginalConstructor()->getMock(), new PlanState($config), $shared, \OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture::location($this));
    }

    private function tag(string $id, string $name, bool $visible, bool $assignable): ISystemTag {
        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn($id);
        $tag->method('getName')->willReturn($name);
        $tag->method('isUserVisible')->willReturn($visible);
        $tag->method('isUserAssignable')->willReturn($assignable);
        return $tag;
    }

    /** @return array{NativeTags,ISystemTagManager,ISystemTagObjectMapper,IUser} */
    private function assignmentFixture(bool $writable, bool $assignable = true, ?ISystemTag $current = null, ?SharedWriteGuard $sharedWrites = null): array {
        $user = $this->createMock(IUser::class);
        $old = $current ?? $this->tag('1', 'Old', true, true);
        $new = $this->tag('2', 'Tacos', true, $assignable);
        $manager = $this->createMock(ISystemTagManager::class);
        $manager->method('getTagsByIds')->willReturn([$old]);
        $manager->method('getAllTags')->willReturnCallback(static fn ($visibility, $name): array => match ($name) { 'Tacos' => [$new], 'Old' => [$old], default => [] });
        $manager->method('canUserSeeTag')->willReturn(true);
        $manager->method('canUserAssignTag')->willReturnCallback(static fn (ISystemTag $tag): bool => $tag->isUserAssignable());
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(42);
        $file->method('isReadable')->willReturn(true);
        $file->method('isUpdateable')->willReturn($writable);
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('report.txt')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $guard = $this->getMockBuilder(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->disableOriginalConstructor()->onlyMethods(['assertVisible', 'getHiddenTagIds'])->getMock();
        $guard->method('assertVisible');
        $guard->method('getHiddenTagIds')->willReturn([]);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturn(['42' => ['1']]);
        return [$this->nativeTags($manager, $users, $root, $guard, $mapper, $sharedWrites), $manager, $mapper, $user];
    }
}
