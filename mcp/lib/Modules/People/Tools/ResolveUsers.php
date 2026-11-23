<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\People\Tools;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\Search\PeopleResolver;
use OCA\Mcp\Modules\People\Search\PeopleSearch;
final class ResolveUsers implements Tool {
    public function __construct(private PeopleResolver $resolver) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(name:'users_resolve', module:'people', operation:'read',
            description:'Resolves a bounded batch of names, account IDs or e-mail addresses through Nextcloud sharing visibility. Returns exact matches and suggestions with only visible profile details. Multiple matches stay ambiguous; confirm the intended account before acting on a person.',
            inputSchema:['type'=>'object','properties'=>['queries'=>['type'=>'array','minItems'=>1,'maxItems'=>PeopleResolver::MAX_QUERIES,
                'items'=>['type'=>'string','minLength'=>PeopleSearch::MIN_TERM,'maxLength'=>PeopleSearch::MAX_TERM],
                'description'=>'Names, account IDs or e-mail addresses to resolve (1 to 20 queries, 2 to 100 characters each).']],
                'required'=>['queries'],'additionalProperties'=>false]);
    }
    public function execute(array $arguments, ToolContext $context): array {
        return ToolResult::json($this->resolver->resolve($arguments, $context->userId));
    }
}
