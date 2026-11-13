<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Integration\FormsPublication;
use PHPUnit\Framework\TestCase;
final class FormsPublicationTest extends TestCase {
    public function testSourceQualificationDoesNotPublishAnUnimplementedEffectAdapter():void {
        $native=['qualified'=>true,'protocol'=>1,'actions'=>['forms_create','forms_update']];
        self::assertTrue(FormsPublication::supported('forms_create',$native));
        self::assertTrue(FormsPublication::supported('forms_download_results',$native));
        self::assertFalse(FormsPublication::supported('forms_link_results_file',$native));
        self::assertFalse(FormsPublication::supported('forms_unlink_results_file',$native));
        self::assertFalse(FormsPublication::supported('forms_add_comment',$native));
        self::assertFalse(FormsPublication::supported('forms_list_comments',$native));
        self::assertFalse(FormsPublication::supported('forms_create',['qualified'=>false]));
    }
}
