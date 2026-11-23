<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Trash\CalendarTrashReader;
use OCA\Mcp\Modules\Calendar\Trash\CalendarTrashRestorer;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCP\IConfig;

final class CalendarTrashTest extends CalendarTestCase
{
    private function states(): PlanState
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('fixture-secret');
        return new PlanState($config);
    }

    public function testListUsesNativeActorTrashAndOmitsHiddenSharedDetails(): void
    {
        $this->store->addObject(1, 'keep-deleted.ics', self::ics("UID:keep\nSUMMARY:Restorable\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z"), true);
        $this->store->addObject(4, 'hidden-deleted.ics', self::ics("UID:hidden\nSUMMARY:Secret Trash\nCLASS:PRIVATE\nDTSTART:20260311T110000Z\nDTEND:20260311T120000Z"), true);
        $reader = new CalendarTrashReader(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store,$this->scope), $this->store, new EventRepository($this->store,new Classification()), new Classification(), new EventBuilder());
        $data = json_decode($reader->execute([], 'alice')['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['keep'], array_column($data['items'],'uid'));
        self::assertStringNotContainsString('Secret Trash', json_encode($data));
        self::assertArrayHasKey('object_id',$data['items'][0]);
    }

    public function testRestoreRequiresFreshPlanAndVerifiesNativeReadback(): void
    {
        $ics = self::ics("UID:restore-me\nSUMMARY:Restore me\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z");
        $this->store->addObject(1, 'restore-me-deleted.ics', $ics, true);
        $id = $this->store->objects[1]['restore-me-deleted.ics']['id'];
        $restorer = new CalendarTrashRestorer(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store,$this->scope), $this->store, $this->store, new EventRepository($this->store,new Classification()), new Classification(), $this->states(), new EventBuilder());
        $args = ['object_id'=>$id];
        $plan = $restorer->preview($args,'alice');
        self::assertSame('Restore me',$plan['summary']);
        self::assertSame(false,$plan['scheduling']['sent']);

        $result = $restorer->restore($args + ['plan_state'=>$plan['plan_state']],'alice');
        $data = json_decode($result['content'][0]['text'],true,flags:JSON_THROW_ON_ERROR);
        self::assertTrue($data['restored']);
        self::assertArrayHasKey('restore-me.ics',$this->store->objects[1]);
        self::assertFalse($this->store->objects[1]['restore-me.ics']['deleted']);
    }

    public function testRestoreRejectsCollisionAddedAfterPreview(): void
    {
        $ics = self::ics("UID:restore-me\nSUMMARY:Restore me\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z");
        $this->store->addObject(1, 'restore-me-deleted.ics', $ics, true);
        $id = $this->store->objects[1]['restore-me-deleted.ics']['id'];
        $restorer = new CalendarTrashRestorer(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store,$this->scope), $this->store, $this->store, new EventRepository($this->store,new Classification()), new Classification(), $this->states(), new EventBuilder());
        $args = ['object_id'=>$id];
        $plan = $restorer->preview($args,'alice');
        $this->store->addObject(1,'restore-me.ics',self::ics("UID:other\nSUMMARY:Current\nDTSTART:20260311T110000Z\nDTEND:20260311T120000Z"));
        try {
            $restorer->restore($args + ['plan_state'=>$plan['plan_state']],'alice');
            self::fail('live URI collision must stop restore');
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure) {
            self::assertTrue($this->store->objects[1]['restore-me-deleted.ics']['deleted']);
        }
    }

    public function testRestoreRejectsChangedTrashEtag(): void
    {
        $ics = self::ics("UID:restore-me\nSUMMARY:Restore me\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z");
        $this->store->addObject(1, 'restore-me-deleted.ics', $ics, true);
        $id = $this->store->objects[1]['restore-me-deleted.ics']['id'];
        $restorer = new CalendarTrashRestorer(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store,$this->scope), $this->store, $this->store, new EventRepository($this->store,new Classification()), new Classification(), $this->states(), new EventBuilder());
        $args = ['object_id'=>$id];
        $plan = $restorer->preview($args,'alice');
        $this->store->objects[1]['restore-me-deleted.ics']['etag'] = '"changed"';
        try {
            $restorer->restore($args + ['plan_state'=>$plan['plan_state']],'alice');
            self::fail('changed trash object must not be restored from stale plan');
        } catch (PlanChanged $changed) {
            self::assertArrayHasKey('plan_state',$changed->plan);
            self::assertTrue($this->store->objects[1]['restore-me-deleted.ics']['deleted']);
        }
    }
}
