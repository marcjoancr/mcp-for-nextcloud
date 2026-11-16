<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization;
use PHPUnit\Framework\TestCase;

final class FormsNativeHomeAuthorizationTest extends TestCase {
    public function testFutureHomeRequiresTransferOrRestoreAndBothOriginalFilesOperations(): void {
        FormsNativeAuthorization::assertNativeHomeContext(['tool'=>'forms_transfer','references'=>['files_operations'=>['read','create']]],'recipient');
        FormsNativeAuthorization::assertNativeHomeContext(['tool'=>'forms_restore','references'=>['files_operations'=>['create','read']]],'actor');
        foreach ([
            [['tool'=>'forms_transfer','references'=>['files_operations'=>['read']]],'recipient'],
            [['tool'=>'forms_transfer','references'=>['files_operations'=>[]]],'recipient'],
            [['tool'=>'forms_delete_form','references'=>['files_operations'=>['read','create']]],'recipient'],
            [['tool'=>'forms_transfer','references'=>['files_operations'=>['read','create']]],''],
        ] as [$context, $target]) {
            try {
                FormsNativeAuthorization::assertNativeHomeContext($context,$target);
                self::fail('Unqualified future Home authority was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('Forms destination authority is unavailable.', $error->getMessage());
            }
        }
    }
}
