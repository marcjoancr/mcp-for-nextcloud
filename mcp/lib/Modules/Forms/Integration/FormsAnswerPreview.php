<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Integration;
use OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect;
use OCA\Mcp\Modules\Forms\Responses\FormsAnswers;
/** Native validation inspects opaque uploads; only a confirmed apply may consume them. */
final class FormsAnswerPreview {
    public static function validate(object $guard,string $uid,string $action,array $input,array $questions,array $origin,string $revision):array {
        try {
            $answers=FormsAnswers::native($input['answers'],$questions,static fn(int $question,string $handle):array=>['mcpHandle'=>$handle]);
            $input['answers']=$answers;$input['_mcp_origin']=$origin;
            $guard->preflightAnswers($uid,$action,$input,$revision);
            return $answers;
        } catch(\Throwable) {
            throw new FormsBeforeEffect('The answers, uploaded files or submission permissions are no longer valid. Review the current form before submitting.');
        }
    }
}
