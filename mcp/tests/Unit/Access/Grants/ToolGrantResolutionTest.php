<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Grants;

use OCA\Mcp\Access\Grants\ToolGrantResolution;
use PHPUnit\Framework\TestCase;

final class ToolGrantResolutionTest extends TestCase {
    public function testUserDecisionOverridesConflictingGroupDecisions(): void {
        $result = ToolGrantResolution::resolve('allow', false, [
            ['decision' => 'deny', 'legacyAllowed' => true, 'groupId' => 'team'],
        ], false);

        self::assertSame(['allowed' => true, 'source' => 'individual_allow', 'groupIds' => []], $result);
    }

    public function testNeutralUserMarkerSuppressesLegacyIndividualAndInheritsGroup(): void {
        $result = ToolGrantResolution::resolve('neutral', false, [
            ['decision' => 'allow', 'legacyAllowed' => false, 'groupId' => 'team'],
        ], false);

        self::assertSame(['allowed' => true, 'source' => 'group', 'groupIds' => ['team']], $result);
    }

    public function testGroupDenyWinsAcrossGroupsAndNeutralSuppressesThatGroupsLegacyGrant(): void {
        $result = ToolGrantResolution::resolve(null, null, [
            ['decision' => 'allow', 'legacyAllowed' => false, 'groupId' => 'team'],
            ['decision' => 'deny', 'legacyAllowed' => true, 'groupId' => 'restricted'],
            ['decision' => 'neutral', 'legacyAllowed' => true, 'groupId' => 'other'],
        ], false);

        self::assertSame(['allowed' => false, 'source' => 'group_deny', 'groupIds' => ['restricted']], $result);
    }

    public function testLegacyIndividualDecisionRemainsAheadOfNewGroupRules(): void {
        $result = ToolGrantResolution::resolve(null, false, [
            ['decision' => 'allow', 'legacyAllowed' => true, 'groupId' => 'team'],
        ], true);

        self::assertSame(['allowed' => false, 'source' => 'legacy_individual', 'groupIds' => []], $result);
    }

    public function testLegacyGroupAndDefaultRemainFallbackWhenNoNewAssignmentExists(): void {
        self::assertSame(
            ['allowed' => true, 'source' => 'legacy_group', 'groupIds' => ['team']],
            ToolGrantResolution::resolve(null, null, [
                ['decision' => null, 'legacyAllowed' => true, 'groupId' => 'team'],
            ], false),
        );
        self::assertSame(
            ['allowed' => false, 'source' => 'default', 'groupIds' => []],
            ToolGrantResolution::resolve(null, null, [], false),
        );
    }

    public static function eligibilityTransitions(): array {
        return [
            'actual loss' => [true, false, true],
            'unknown result' => [true, null, false],
            'already denied' => [false, false, false],
            'still eligible' => [true, true, false],
            'unknown before' => [null, false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eligibilityTransitions')]
    public function testCredentialCutRequiresKnownTrueToKnownFalseTransition(?bool $before, ?bool $after, bool $expected): void {
        self::assertSame($expected, ToolGrantResolution::isEligibilityLoss($before, $after));
    }

    public function testUnknownGroupDenyNeverFallsThroughToAnotherAllow(): void {
        $result = ToolGrantResolution::resolve(null, null, [
            ['decision' => 'allow', 'legacyAllowed' => false, 'groupId' => 'team'],
            ['decision' => 'deny', 'legacyAllowed' => false, 'groupId' => 'unknown', 'trusted' => false],
        ], false);

        self::assertSame(['allowed' => null, 'source' => 'unknown', 'groupIds' => ['unknown']], $result);
    }
}
