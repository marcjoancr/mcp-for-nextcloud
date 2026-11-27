<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect;
use OCA\Mcp\Modules\Forms\Integration\FormsOfficialAuthoring;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class FormsOfficialAuthoringTest extends TestCase {
    public function testMismatchingReadBackRemainsUncertain(): void {
        $calls = [];
        $form = ['id' => 3, 'ownerId' => 'alice', 'permissions' => ['edit'], 'title' => 'Previous', 'questions' => [], 'shares' => []];
        $api = static function (string $uid, string $action, array $args) use (&$calls, $form): mixed {
            self::assertSame('alice', $uid);
            $calls[] = $action;
            return $action === 'getForm' ? $form : 3;
        };
        $gateway = new FormsOfficialAuthoring(static fn (): bool => true, $api, $this->createStub(IURLGenerator::class));
        $args = ['id' => 3, 'fields' => ['title' => 'Requested']];
        $snapshot = $gateway->snapshot('alice', 'forms_update', $args);
        $result = $gateway->apply('alice', 'forms_update', $args, $snapshot['revision'], 'ignored');
        self::assertSame('uncertain', $result['state']);
        self::assertSame(1, count(array_keys($calls, 'updateForm', true)));
        self::assertSame('getForm', end($calls));
        self::assertNull($gateway->lookup('alice', 'ignored'));
    }

    public function testChangedPreviewIsRejectedBeforeDispatch(): void {
        $reads = 0;
        $api = static function (string $uid, string $action, array $args) use (&$reads): array {
            self::assertSame('getForm', $action);
            return ['id' => 3, 'ownerId' => 'alice', 'permissions' => ['edit'], 'title' => 'Revision ' . ++$reads,
                'questions' => [], 'shares' => []];
        };
        $gateway = new FormsOfficialAuthoring(static fn (): bool => true, $api, $this->createStub(IURLGenerator::class));
        $args = ['id' => 3, 'fields' => ['title' => 'Requested']];
        $snapshot = $gateway->snapshot('alice', 'forms_update', $args);
        $this->expectException(FormsBeforeEffect::class);
        $gateway->apply('alice', 'forms_update', $args, $snapshot['revision'], 'ignored');
    }

    public function testInterruptedTitleUpdateRetainsTheKnownPrivateFormId(): void {
        $calls = [];
        $api = static function (string $uid, string $action, array $args) use (&$calls): array {
            $calls[] = $action;
            if ($action === 'newForm') {
                return ['id' => 8, 'ownerId' => 'alice'];
            }
            throw new \RuntimeException('The acknowledgement did not arrive.');
        };
        $gateway = new FormsOfficialAuthoring(static fn (): bool => true, $api, $this->createStub(IURLGenerator::class));
        $args = ['title' => 'Requested'];
        $snapshot = $gateway->snapshot('alice', 'forms_create', $args);
        $result = $gateway->apply('alice', 'forms_create', $args, $snapshot['revision'], 'ignored');
        self::assertSame(['state' => 'uncertain', 'references' => ['form_id' => 8]], $result);
        self::assertSame(['newForm', 'updateForm'], $calls);
    }
}
