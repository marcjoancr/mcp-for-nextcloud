<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\{FormsToolCatalog,FormsModule,FormsReader};
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\{Tool,WriteTool};
use PHPUnit\Framework\TestCase;
final class FormsExtendedCatalogTest extends TestCase {
    public function testEveryExtendedToolHasExplicitImplementationAndClassification():void {
        $all=FormsToolCatalog::definitions();
        foreach(FormsCommands::commands() as $name=>$command) {
            self::assertArrayHasKey($name,$all);
            $class='OCA\\Mcp\\Modules\\Forms\\Tools\\'.$command['class'];
            self::assertTrue(is_a($class,Tool::class,true),$name);
            self::assertSame(!$command['read'],WriteGate::isWrite($all[$name]),$name);
            if(!$command['read'])self::assertTrue(is_a($class,WriteTool::class,true),$name);
            self::assertContains($command['operation'],GrantPolicy::CATALOG['forms']);
            self::assertFalse(GrantPolicy::defaultGranted('forms',$command['operation']));
        }
    }
    public function testLegacyReaderModuleDoesNotPublishUnqualifiedWrites():void {
        $reader=new class implements FormsReader {
            public function list(string $uid):array{return [];}
            public function read(string $uid,int $id):?array{return null;}
        };
        $module=FormsModule::withReader($reader);
        self::assertSame(['forms_list','forms_read','forms_read_responses','forms_summarize_responses','forms_export_responses'],array_column($module->definitions(),'name'));
    }
    public function testAdministrationIsExplicitlyRoleRestricted():void {
        $all=FormsToolCatalog::definitions();
        self::assertTrue($all['forms_read_admin_settings']['requiresAdmin']);
        self::assertTrue($all['forms_update_admin_settings']['requiresAdmin']);
    }
}
