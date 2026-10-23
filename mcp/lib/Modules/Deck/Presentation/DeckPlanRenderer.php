<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;
use OCA\Mcp\L10n\Translator;


class DeckPlanRenderer {
    public function __construct(private ?\DateTimeZone $zone = null) {
    }

    /**
     * @param string $tool Tool name
     * @param array<string, mixed> $plan Write plan
     * @return string|null Markdown body, or null to fall back to generic
     */
    public function render(string $tool, array $plan): ?string {
        try {
            $zone = PlanText::resolveZone($plan, $this->zone);
            return match ($tool) {
                'deck_edit_card_labels' => $this->renderLabels($plan),
                'deck_link_card_file' => \OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPlan::render($plan),
                'deck_unlink_card_file' => $this->renderUnlinkFile($plan),
                'deck_add_card_dependency', 'deck_remove_card_dependency' => $this->renderDependency($plan),
                'deck_create_card' => CardPlanRenderer::renderCreate($plan, $zone),
                'deck_edit_card' => CardPlanRenderer::renderEdit($plan, $zone),
                'deck_move_card' => CardPlanRenderer::renderMove($plan),
                'deck_delete_card' => CardPlanRenderer::renderDelete($plan),
                'deck_create_board' => StructurePlanRenderer::renderCreateBoard($plan, $zone),
                'deck_create_stack' => StructurePlanRenderer::renderCreateStack($plan),
                'deck_delete_stack' => StructurePlanRenderer::renderDeleteStack($plan),
                'deck_delete_board' => StructurePlanRenderer::renderDeleteBoard($plan),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
	private function renderUnlinkFile(array $plan): string {
		return Translator::t('Unlink %s from Deck card %s.', [\OCA\Mcp\Mcp\Confirmation\PlanText::em($plan['file']['name']), \OCA\Mcp\Mcp\Confirmation\PlanText::em($plan['card']['title'])])
			. "\n\n" . Translator::t('Association created by: %s', [\OCA\Mcp\Mcp\Confirmation\PlanText::inline($plan['createdBy'])])
			. "\n\n" . CapabilityMessages::fileUnlinkWarning()
			. (PlanText::sharedLine($plan['board']) === [] ? '' : "\n\n" . implode("\n", PlanText::sharedLine($plan['board'])));
	}
	private function renderDependency(array $plan): string {
		$state = static fn (bool $present): string => $present ? Translator::t('present') : Translator::t('absent');
		return Translator::t('Deck dependency: %s → %s.', [\OCA\Mcp\Mcp\Confirmation\PlanText::em($plan['origin']['title']), \OCA\Mcp\Mcp\Confirmation\PlanText::em($plan['target']['title'])])
			. "\n\n" . Translator::t('Before: %s', [$state($plan['before'])])
			. "\n\n" . Translator::t('After: %s', [$state($plan['after'])])
			. "\n\n" . CapabilityMessages::dependencyWriteLimitations()
			. (PlanText::sharedLine($plan['board']) === [] ? '' : "\n\n" . implode("\n", PlanText::sharedLine($plan['board'])));
	}
	private function renderLabels(array $plan): string {
		$labels = static fn (array $items): string => $items === [] ? '—' : implode(', ', array_map(
			static fn (array $label): string => \OCA\Mcp\Mcp\Confirmation\PlanText::inline($label['title']) . ' (' . (int)$label['id'] . ')', $items));
		return Translator::t('Change labels on %s in %s.', [\OCA\Mcp\Mcp\Confirmation\PlanText::em($plan['card']['title']), \OCA\Mcp\Mcp\Confirmation\PlanText::em($plan['board']['name'])])
			. "\n\n" . Translator::t('Before: %s', [$labels($plan['before'])])
			. "\n\n" . Translator::t('After: %s', [$labels($plan['after'])])
			. "\n\n" . CapabilityMessages::planLabels()
			. (PlanText::sharedLine($plan['board']) === [] ? '' : "\n\n" . implode("\n", PlanText::sharedLine($plan['board'])));
	}
}
