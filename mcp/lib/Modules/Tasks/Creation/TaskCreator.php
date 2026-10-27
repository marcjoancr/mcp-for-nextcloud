<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Creation;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Modules\Tasks\Content\TaskPatch;
use OCA\Mcp\Modules\Tasks\Execution\TaskPlan;
use OCA\Mcp\Modules\Tasks\Execution\TaskWriter;
final class TaskCreator
{
    public function __construct(
        private CalendarAccess $access,
        private TaskPlan $plans,
        private ITimeFactory $time,
        private TaskPatch $patch,
        private TaskWriter $writer,
        private \OCA\Mcp\Modules\Tasks\Hierarchy\TaskParentStep $parent,
    )
    {
    }
    private function prepare(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar'], 'VTODO');
        $row = null;
        $before = null;
        $now = $this->time->now();
        $after = $this->patch->create($arguments, $now);
        $plan = $this->plans->build(
            'tasks_create_task',
            $calendar,
            $row,
            $before,
            $after,
            $userId,
            true,
            false
        );
        return compact(
            'calendar',
            'row',
            'after',
            'plan'
        );
    }
    public function preview(array $arguments, string $userId): array
    {
        return $this->parent->plan($this->prepare($arguments, $userId), $arguments, $userId, false)['plan'];
    }
    public function execute(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar'], 'VTODO');
        return $this->parent->guarded($calendar, $arguments, function () use ($arguments, $userId): array {
            $prepared = $this->parent->plan($this->prepare($arguments, $userId), $arguments, $userId, false);
            $this->plans->assertAcknowledged($prepared['plan'], $arguments);
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($this->parent->afterWrite(
                $this->writer->save($prepared, $userId), $prepared['calendar'], $arguments,
            ));
        });
    }
}
