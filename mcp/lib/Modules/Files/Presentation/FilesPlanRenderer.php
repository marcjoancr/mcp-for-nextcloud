<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Modules\Files\Checkout\CheckoutPlanRenderer;
use OCA\Mcp\Modules\Files\Creation\CreationPlanRenderer;
use OCA\Mcp\Modules\Files\Editing\EditingPlanRenderer;
use OCA\Mcp\Modules\Files\Reorganization\ReorganizationPlanRenderer;
use OCA\Mcp\Modules\Files\Sharing\SharingPlanRenderer;
use OCA\Mcp\Modules\Files\Versions\VersionPlanRenderer;

final class FilesPlanRenderer {
    /**
     * @param string $tool tool name
     * @param array<string, mixed> $plan the plan returned by FilesModule::preview()
     * @return string|null Markdown body, or null when the tool is not a Files write or the plan lacks what it needs
     */
    public static function render(string $tool, array $plan): ?string {
        if (in_array($tool, ['files_mkdir', 'files_copy', 'files_move', 'files_move_batch', 'files_undo_batch'], true)) {
            return ReorganizationPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_share', 'files_unshare', 'files_create_upload_link'], true)) {
            return SharingPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_upload', 'files_create'], true)) {
            return CreationPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_checkout'], true)) {
            return CheckoutPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_version_restore'], true)) {
            return VersionPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_edit', 'files_replace'], true)) {
            return EditingPlanRenderer::render($tool, $plan);
        }
        if (in_array($tool, ['files_create_tag', 'files_set_tags'], true)) {
            return self::tags($tool, $plan);
        }
        if ($tool === 'files_set_image_description') {
            return self::imageDescription($plan);
        }
        return null;
    }

    private static function tags(string $tool, array $plan): ?string {
        try {
            if ($tool === 'files_create_tag') {
                $name = PlanFormatting::text($plan, 'name');
                if ($name === null) return null;
                $label = PlanText::quoted($name);
                return ($plan['reused'] ?? false) === true
                    ? Translator::t('Reuse the existing public tag %s; no tag will be created.', [$label])
                    : Translator::t('Create the public, assignable tag %s.', [$label]);
            }
            $changes = $plan['changes'] ?? null;
            if (!is_array($changes) || $changes === [] || count($changes) > 20) return null;
            $lines = [];
            $shared = [];
            foreach ($changes as $change) {
                if (!is_array($change) || !is_string($change['path'] ?? null) || !is_array($change['current'] ?? null)
                    || !is_array($change['add'] ?? null) || !is_array($change['remove'] ?? null)) return null;
                $current = PlanFormatting::strings($change['current']);
                $add = PlanFormatting::strings($change['add']);
                $remove = PlanFormatting::strings($change['remove']);
                $after = self::tagResult($current, $add, $remove);
                $lines[] = Translator::t('Change tags on %s.', [PlanFormatting::bold($change['path'])]);
                $lines[] = '- ' . Translator::t('Before: %s', [self::tagList($current)]);
                $lines[] = '- ' . Translator::t('After: %s', [self::tagList($after)]);
                $lines[] = '';
                if (is_array($change['shared'] ?? null)) $shared[] = $change['shared'];
            }
            while ($lines !== [] && end($lines) === '') array_pop($lines);
            return implode("\n", array_merge($lines, PlanFormatting::sharing([
                'shared' => $shared,
                'requiresSharedConfirmation' => $shared !== [],
            ])));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function tagResult(array $current, array $add, array $remove): array {
        $removed = array_fill_keys(array_map(static fn (string $name): string => mb_strtolower($name, 'UTF-8'), $remove), true);
        $result = [];
        foreach ($current as $name) {
            $key = mb_strtolower($name, 'UTF-8');
            if (!isset($removed[$key])) $result[$key] = $name;
        }
        foreach ($add as $name) $result[mb_strtolower($name, 'UTF-8')] ??= $name;
        $result = array_values($result);
        usort($result, static fn (string $a, string $b): int => strcasecmp($a, $b));
        return $result;
    }

    /** @param list<string> $tags */
    private static function tagList(array $tags): string {
        if ($tags === []) return Translator::t('(none)');
        return implode(', ', array_map(PlanText::quoted(...), $tags));
    }

    private static function imageDescription(array $plan): ?string {
        try {
            $path = PlanFormatting::text($plan, 'path');
            if ($path === null || !is_string($plan['current_description'] ?? null) || !is_string($plan['description'] ?? null)) return null;
            $source = match ($plan['source'] ?? null) {
                'files_metadata' => Translator::t('Nextcloud Files metadata'),
                'photos_metadata' => Translator::t('Photos metadata'),
                'embedded_exif' => Translator::t('embedded EXIF metadata'),
                default => Translator::t('no existing description'),
            };
            $lines = [
                Translator::t('Set the image description for %s.', [PlanFormatting::bold($path)]),
                '- ' . Translator::t('Current source: %s', [$source]),
                '- ' . Translator::t('Before: %s', [PlanText::quoted($plan['current_description'])]),
                '- ' . Translator::t('After: %s', [PlanText::quoted($plan['description'])]),
                '- ' . Translator::t('Only Files metadata will change; the image bytes stay unchanged.'),
            ];
            $shared = is_array($plan['shared'] ?? null) ? [$plan['shared']] : [];
            return implode("\n", array_merge($lines, PlanFormatting::sharing([
                'shared' => $shared,
                'requiresSharedConfirmation' => ($plan['requiresSharedConfirmation'] ?? false) === true,
            ])));
        } catch (\Throwable) {
            return null;
        }
    }
}
