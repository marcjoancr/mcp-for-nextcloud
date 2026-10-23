<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\Files\File;
use OCP\Files\Folder;

/** Recomputes the approved effect, including a fresh read inside the write's lock check. */
final class PartialEditConfirmation {
    public function __construct(private PartialEditPlan $plans, private PlanState $states) {}

    /** @param array<string, mixed> $arguments */
    public function content(Folder $root, File $note, string $userId, array $arguments, string $mode): string {
        NoteChange::assertCurrent($note, NoteChange::etag($arguments));
        PlanState::require($arguments);
        $current = $this->plans->raw($note);
        $state = NoteChange::state($this->states, $userId, (int) $note->getId(), $current, $mode, $arguments);
        if (!PlanState::matches($state, $arguments)) {
            throw new PlanChanged($this->plans->build($root, $note, $userId, $arguments, $mode, true));
        }
        return NoteChange::apply($mode, $current, $arguments);
    }

    /** @param array<string, mixed> $arguments @return \Closure():void */
    public function writer(Folder $root, File $note, string $userId, array $arguments, string $mode): \Closure {
        return function () use ($root, $note, $userId, $arguments, $mode): void {
            // Nextcloud has no content compare-and-swap: a concurrent write after this last read can still race.
            $updated = $this->content($root, $note, $userId, $arguments, $mode);
            $note->putContent($updated);
        };
    }
}
