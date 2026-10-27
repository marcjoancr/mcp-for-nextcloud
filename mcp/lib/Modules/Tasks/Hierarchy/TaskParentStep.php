<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Hierarchy;
use OCA\Mcp\Modules\Tasks\Content\TaskDocument;
use OCA\Mcp\Modules\Tasks\Content\TaskRelations;
use OCA\Mcp\Modules\Tasks\Reading\ParentTitles;
use OCA\Mcp\Modules\Tasks\Hierarchy\TaskHierarchyMessages;

use Closure;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Confirmation\WriteGate;

/**
 * What the `parent` argument adds to a create or an edit: the hierarchy check and the parent titles in the plan,
 * the hierarchy lock around a confirmed write that sets a parent, and a warning when the chain read back after the
 * write loops through the task (a concurrent change no lock of this app covers).
 *
 * The check runs in the preview and again in the confirmed call, on the state read at that moment; omitting
 * `parent` or sending null never reads the hierarchy, so a task can always be made top-level.
 */
final class TaskParentStep {
	/**
	 * Receives the hierarchy rules, the title lookup and the lock.
	 *
	 * @param TaskHierarchy $hierarchy parent check and loop detection
	 * @param ParentTitles $titles titles of the parents a plan names
	 * @param TaskRelations $relations reader of parent links
	 * @param TaskDocument $data parser of the prepared task
	 * @param TaskHierarchyLock $lock serializes confirmed MCP writes that set a parent; disabled locking refuses the write
	 * @return void
	 */
	public function __construct(
		private TaskHierarchy $hierarchy,
		private ParentTitles $titles,
		private TaskRelations $relations,
		private TaskDocument $data,
		private TaskHierarchyLock $lock,
	) {
	}

	/**
	 * Checks the requested parent and names the parents in the plan.
	 *
	 * @param array{calendar:Calendar, row:?array, after:?\Sabre\VObject\Component\VCalendar, plan:array<string, mixed>} $prepared result of the capability preparation
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @param bool $existing whether the task already exists (edit) and may carry subtasks
	 * @return array{calendar:Calendar, row:?array, after:?\Sabre\VObject\Component\VCalendar, plan:array<string, mixed>} the prepared write, its plan with `parents`
	 * @throws ToolFailure when the parent cannot be used
	 */
	public function plan(array $prepared, array $arguments, string $userId, bool $existing): array {
		$after = $prepared['after'];
		$uid = $after === null ? null : (string)$this->data->master($after)->UID;
		if (($arguments['parent'] ?? null) !== null && $uid !== null) {
			$this->hierarchy->assertParent($prepared['calendar'], $userId, $arguments['parent'], $uid, $existing);
		}
		$prepared['plan']['parents'] = $this->titles->of($prepared['calendar'], $userId, [
			$prepared['plan']['before']['parent'] ?? null,
			$after === null ? null : $this->relations->parent($this->data->master($after)),
		]);
		return $prepared;
	}

	/**
	 * Runs a confirmed write under the hierarchy lock when it sets a parent; any other write runs as it is. Without a
	 * lock that excludes, a write that sets a parent is refused before anything runs.
	 *
	 * @template T
	 * @param Calendar $calendar writable calendar the arguments address
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param Closure(): T $write the prepare, check and write of the confirmed call
	 * @return T what the write returned
	 * @throws ToolFailure when another MCP request is changing the subtasks of the calendar, or no lock can serialize
	 *     the write
	 */
	public function guarded(Calendar $calendar, array $arguments, Closure $write): mixed {
		if (($arguments['parent'] ?? null) === null || !WriteGate::confirmed($arguments)) {
			return $write();
		}
		return $this->lock->run($calendar->id, $write);
	}

	/**
	 * Adds a warning to the answer of a write that set a parent when the chain read back now loops through the task.
	 *
	 * @param array<string, mixed> $answer the written task, as TaskWriter::save() returned it
	 * @param Calendar $calendar calendar of the task
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array<string, mixed> the answer, with `warning` when a loop runs through the task
	 */
	public function afterWrite(array $answer, Calendar $calendar, array $arguments): array {
		if (($arguments['parent'] ?? null) === null || !isset($answer['uid'])) {
			return $answer;
		}
		if ($this->hierarchy->loopsThrough($calendar->id, (string)$answer['uid'])) {
			$answer['warning'] = TaskHierarchyMessages::loopAfterWrite();
		}
		return $answer;
	}
}
