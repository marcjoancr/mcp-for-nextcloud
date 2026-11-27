<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
final class FormsWritePlanRenderer {
    public function render(string $tool,array $plan):string {
        $lines=[ToolPresentation::title($tool),Translator::t('Nothing has been changed.')];
        foreach(['before'=>'Current values','changes'=>'Proposed changes','impact'=>'Native effects'] as $field=>$title) {
            $lines[]=Translator::t($title).': '.PlanText::quoted(json_encode($plan[$field]??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }
        if($plan['requiresSharedConfirmation']??false) {
            $lines[]=($plan['official_authoring']??false)
                ? Translator::t('Separate confirmation is required for the reviewed effects.')
                : Translator::t('This form belongs to another person. Separate shared-resource confirmation is required.');
        }
        return implode("\n\n",$lines);
    }
}
