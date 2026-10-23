<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Notes\Content\NoteContent;
use OCA\Mcp\Modules\Notes\Storage\NoteMetadata;
use OCA\Mcp\Modules\Notes\Storage\NoteNames;
use OCA\Mcp\Modules\Notes\Execution\NotePlanNotice;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCP\Files\File;
use OCP\Files\Folder;

/** Plans the partial edit without copying the note into the response. */
final class PartialEditPlan {
    public function __construct(
        private \OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard $fileTypes,
        private NoteContent $content,
        private NoteMetadata $metadata,
        private NoteNames $names,
        private RenameTarget $rename,
        private PlanState $states,
        private NodeAccessInfo $access,
        private NotePlanNotice $notice,
    ) {}

    public function raw(File $note): string {
        if ($note->getSize() > NoteContent::MAX_BYTES) {
            throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(NoteContent::MAX_BYTES));
        }
        $current = (string) $note->getContent();
        $this->content->assertSize($current);
        return $current;
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function build(Folder $root, File $note, string $userId, array $arguments, string $mode, bool $changed = false): array {
        NoteChange::assertCurrent($note, $arguments['etag'] ?? null);
        if (!$note->isUpdateable()) {
            throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::forbidden());
        }
        $snapshot = $this->fileTypes->snapshot();
        $snapshot->assertWritableName($note->getName());
        if (isset($arguments['title'])) {
            $snapshot->assertWritableName($this->names->renamedName($note, $arguments['title']));
        }
        $current = $this->raw($note);
        $updated = NoteChange::apply($mode, $current, $arguments);
        $info = $this->metadata->info($note, $root);
        $title = $info['title'];
        if (isset($arguments['title'])) {
            $this->rename->resolve($note, $arguments['title']);
            $title = pathinfo($this->names->renamedName($note, $arguments['title']), PATHINFO_FILENAME);
        }
        $lock = $this->notice->lock($note, $userId);
        $warnings = array_merge($changed ? [['message' => ChangeMessages::notePlanChanged()]] : [], $lock['warnings'] ?? []);
        return NoteChangePlan::of($mode, $info, $title, $current, $updated, $arguments) + [
            PlanState::ARGUMENT => NoteChange::state($this->states, $userId, (int) $note->getId(), $current, $mode, $arguments),
            'access' => $this->access->describe($note, $userId),
            'shared' => $this->notice->shared($note, $userId),
            'recoverable' => true,
        ] + ($warnings === [] ? [] : ['warnings' => $warnings]) + $lock;
    }
}
