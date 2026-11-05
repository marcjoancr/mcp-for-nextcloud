<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

final class SetImageDescription implements WriteTool {
    public const NAME = 'files_set_image_description';

    public function __construct(private DescriptionService $descriptions, private FilesAccessGuard $visibility, private PlanState $states) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: self::NAME, module: 'files', operation: 'description_edit',
            description: 'Set an image description in Nextcloud Files metadata after preview and confirmation.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to your Files root.'],
                    'description' => ['type' => 'string', 'maxLength' => DescriptionService::MAX_BYTES, 'description' => 'Plain-text description; an empty string explicitly clears any embedded fallback.'],
                    'plan_state' => PlanState::property(),
                    'confirm_shared' => ['type' => 'boolean', 'description' => EditingMessages::confirmShared()],
                ],
                'required' => ['path', 'description'], 'additionalProperties' => false,
            ],
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        $before = NodeAccess::run(fn (): array => $this->descriptions->preview($context->userId, $arguments['path'], $arguments['description'], $this->visibility));
        return $this->plan($arguments, $context->userId, $before);
    }

    public function execute(array $arguments, ToolContext $context): array {
        PlanState::require($arguments);
        $before = NodeAccess::run(fn (): array => $this->descriptions->read($context->userId, $arguments['path'], $this->visibility));
        $expected = $this->states->of(self::NAME, $context->userId, $this->shown($arguments, $before['state']));
        if (!PlanState::matches($expected, $arguments)) {
            throw new PlanChanged($this->plan($arguments, $context->userId, $before));
        }
        return NodeAccess::run(function () use ($arguments, $context, $before): array {
            $result = $this->descriptions->write($context->userId, $arguments['path'], $arguments['description'], $before['state'], $this->visibility, (bool)($arguments['confirm_shared'] ?? false));
            return ToolResult::json($result['shared_confirmation'] ?? $result);
        });
    }

    /** @param array<string, mixed> $before @return array<string, mixed> */
    private function plan(array $arguments, string $userId, array $before): array {
        return [
            'path' => $arguments['path'],
            'current_description' => $before['description'],
            'description' => $arguments['description'],
            'source' => $before['source'],
            'access' => $before['access'] ?? null,
            'shared' => $before['shared'] ?? null,
            'requiresSharedConfirmation' => ($before['shared'] ?? null) !== null,
            'warnings' => [Translator::t('Only the Nextcloud Files metadata value will change. The image bytes will remain unchanged.')],
            PlanState::ARGUMENT => $this->states->of(self::NAME, $userId, $this->shown($arguments, $before['state'])),
        ];
    }

    /** @param array<string, mixed> $arguments @return array{path:string,description:string,state:string} */
    private function shown(array $arguments, string $state): array {
        return ['path' => $arguments['path'], 'description' => $arguments['description'], 'state' => $state];
    }
}
