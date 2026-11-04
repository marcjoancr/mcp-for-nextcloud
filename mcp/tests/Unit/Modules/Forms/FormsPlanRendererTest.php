<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Tests\Support\Forms\FormsWritePlanFixtures;
use PHPUnit\Framework\TestCase;

final class FormsPlanRendererTest extends TestCase {
	use \OCA\Mcp\Tests\Support\Mcp\AssertsReadablePlans;

	public function testEveryFormsWriteHasAReadableInertPlan(): void {
		[$module, $plans] = FormsWritePlanFixtures::create();
		$this->assertEveryWriteToolHasAReadablePlan($module, $plans);
	}

	public function testExportPlanNamesTheSourcePageAndReportsOmittedData(): void {
		$module = FormsWritePlanFixtures::create()[0];
		$rendered = $module->renderPlan('forms_export_responses', [
			'formId' => 21, 'offset' => 50, 'limit' => 50, 'rows' => 3,
			'partial' => true, 'nextOffset' => 100, 'questionsTruncated' => true,
			'answersTruncated' => true, 'rowOrByteBoundReached' => true,
			'truncatedCells' => 1, 'fileAnswersOmitted' => true,
			'destination' => ['path' => 'exports/[hostile](javascript:alert(1)).csv'],
			'plan_state' => str_repeat('a', 64),
		]);
		self::assertStringContainsString('Form ID: 21', $rendered);
		self::assertStringContainsString('offset 50, limit 50', $rendered);
		self::assertStringContainsString('offset 100', $rendered);
		self::assertStringContainsString('Question metadata exceeded', $rendered);
		self::assertStringContainsString('File answers were omitted', $rendered);
		self::assertStringNotContainsString('](javascript:', $rendered);
		self::assertStringNotContainsString(str_repeat('a', 64), $rendered);
	}
}
