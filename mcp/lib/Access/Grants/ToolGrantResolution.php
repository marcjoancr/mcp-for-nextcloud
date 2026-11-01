<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants;

/** Resolves per-tool decisions while retaining the grants from the pre-tool catalog as fallbacks. */
final class ToolGrantResolution {
    /**
     * @param 'allow'|'deny'|'neutral'|null $userDecision explicit user tool decision, if present
     * @param bool|null $legacyUserDecision old user module/operation decision, if present
     * @param list<array{decision:'allow'|'deny'|'neutral'|null,legacyAllowed:bool,groupId:string,trusted?:bool}> $groups
     * @return array{allowed:?bool,source:string,groupIds:list<string>}
     */
    /** Credentials are cut only when a known-eligible user becomes known-ineligible. Unknown is never a loss. */
    public static function isEligibilityLoss(?bool $before, ?bool $after): bool {
        return $before === true && $after === false;
    }

    public static function resolve(?string $userDecision, ?bool $legacyUserDecision, array $groups, bool $default): array {
        if ($userDecision === 'allow' || $userDecision === 'deny') {
            return ['allowed' => $userDecision === 'allow', 'source' => 'individual_' . $userDecision, 'groupIds' => []];
        }
        if ($userDecision === null && $legacyUserDecision !== null) {
            return ['allowed' => $legacyUserDecision, 'source' => 'legacy_individual', 'groupIds' => []];
        }

        $allows = $legacyAllows = $denies = $unknownAllows = $unknownDenies = [];
        foreach ($groups as $group) {
            $trusted = $group['trusted'] ?? true;
            $decision = $group['decision'];
            if (!$trusted) {
                if ($decision === 'deny') {
                    $unknownDenies[] = $group['groupId'];
                } elseif ($decision === 'allow' || ($decision === null && $group['legacyAllowed'])) {
                    $unknownAllows[] = $group['groupId'];
                }
                continue;
            }
            if ($decision === 'deny') {
                $denies[] = $group['groupId'];
            } elseif ($decision === 'allow') {
                $allows[] = $group['groupId'];
            } elseif ($decision === null && $group['legacyAllowed']) {
                $legacyAllows[] = $group['groupId'];
            }
        }
        if ($denies !== []) {
            sort($denies, SORT_STRING);
            return ['allowed' => false, 'source' => 'group_deny', 'groupIds' => $denies];
        }
        if ($unknownDenies !== []) {
            sort($unknownDenies, SORT_STRING);
            return ['allowed' => null, 'source' => 'unknown', 'groupIds' => $unknownDenies];
        }
        if ($allows !== [] || $legacyAllows !== []) {
            $sources = [...$allows, ...$legacyAllows];
            sort($sources, SORT_STRING);
            return ['allowed' => true, 'source' => $allows !== [] ? 'group' : 'legacy_group', 'groupIds' => $sources];
        }
        if (!$default && $unknownAllows !== []) {
            sort($unknownAllows, SORT_STRING);
            return ['allowed' => null, 'source' => 'unknown', 'groupIds' => $unknownAllows];
        }
        return ['allowed' => $default, 'source' => 'default', 'groupIds' => []];
    }
}
