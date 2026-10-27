<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Tasks;
use OCA\Mcp\Modules\Tasks\Content;
use OCA\Mcp\Modules\Tasks\Execution;
use OCA\Mcp\Modules\Tasks\Reading;
final class TasksModuleFactory {
    public static function create($access, $store, $tasks, $dav, $guard, $trash, $time, $references, $zones = null, $locks = null): \OCA\Mcp\Modules\Tasks\TasksModule {
        $categories = new Content\TaskCategories();
        $relations = new Content\TaskRelations();
        $data = new Content\TaskDocument($categories, $relations);
        $policy = new Content\TaskPolicy($data);
        $patch = new Content\TaskPatch($data, $categories, new Content\TaskDates(), $relations);
        $output = new \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput($data, $references);
        $repository = new Reading\TaskRepository($store, $data, $policy);
        $plans = new Execution\TaskPlan($guard, $output, $zones);
        $writer = new Execution\TaskWriter($dav, $store, $data, $output);
        $parent = new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskParentStep(
            new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskHierarchy($store, $tasks, $data, $relations, $repository),
            new Reading\ParentTitles($store, $data, $repository), $relations, $data,
            new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskHierarchyLock($locks ?? new \OCA\Mcp\Tests\Unit\Modules\Tasks\IneffectiveLocks()),
        );
        return new \OCA\Mcp\Modules\Tasks\TasksModule([
            'tasks_list_calendars' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\ListCalendars(new \OCA\Mcp\Modules\Tasks\Reading\TaskCalendarReader($access)),
            'tasks_list_tasks' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\ListTasks(new \OCA\Mcp\Modules\Tasks\Reading\TaskLister($access, $store, $tasks, $repository, $output, $categories)),
            'tasks_read_task' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\ReadTask(new \OCA\Mcp\Modules\Tasks\Reading\TaskReader($access, $repository, $output)),
            'tasks_create_task' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\CreateTask(new \OCA\Mcp\Modules\Tasks\Creation\TaskCreator($access, $plans, $time, $patch, $writer, $parent)),
            'tasks_edit_task' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\EditTask(new \OCA\Mcp\Modules\Tasks\Editing\TaskEditor($access, $plans, $time, $repository, $output, $policy, $patch, $writer, $parent)),
            'tasks_complete_task' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\CompleteTask(new \OCA\Mcp\Modules\Tasks\Completion\TaskCompleter($access, $plans, $time, $repository, $output, $policy, $patch, $writer)),
            'tasks_delete_task' => static fn () => new \OCA\Mcp\Modules\Tasks\Tools\DeleteTask(new \OCA\Mcp\Modules\Tasks\Deletion\TaskDeleter($access, $plans, $time, $repository, $output, $policy, $trash, $dav, $store)),
        ], ($references)->assertAvailable(...));
    }
}
