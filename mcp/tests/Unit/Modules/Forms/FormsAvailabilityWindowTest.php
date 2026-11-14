<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\FormsToolCatalog;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use PHPUnit\Framework\TestCase;
final class FormsAvailabilityWindowTest extends TestCase {
    public function testOneEnumerationUsesOneCoherentAvailabilitySnapshotAndTheNextEnumerationIsFresh():void {
        $factories=[];foreach(array_keys(FormsToolCatalog::definitions()) as $name)$factories[$name]=static fn()=>throw new \LogicException('Discovery cannot execute a tool.');
        $enabled=true;
        $window=static function()use(&$enabled):\Closure {
            $current=$enabled;$enabled=!$enabled;
            return static fn(string $tool):bool=>!isset(FormsCommands::commands()[$tool])||$tool==='forms_capabilities'||$current;
        };
        $module=new FormsModule($factories,null,$window);
        $first=array_column($module->definitions(),'name');
        foreach(array_keys(FormsCommands::commands()) as $tool)self::assertContains($tool,$first,'One enumeration mixed qualification windows.');
        $second=array_column($module->definitions(),'name');
        self::assertSame(['forms_list','forms_read','forms_read_responses','forms_summarize_responses','forms_export_responses','forms_capabilities'],$second,
            'A cached qualification survived into the next enumeration.');
    }
    public function testExistingPerToolAvailabilityCallbacksRemainSupported():void {
        $factories=[];foreach(array_keys(FormsToolCatalog::definitions()) as $name)$factories[$name]=static fn()=>throw new \LogicException('Discovery cannot execute a tool.');
        $module=new FormsModule($factories,static fn(string $tool):bool=>$tool==='forms_capabilities');
        self::assertSame(['forms_capabilities'],array_column($module->definitions(),'name'));
    }
}
