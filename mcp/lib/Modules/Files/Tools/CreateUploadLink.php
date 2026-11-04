<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Sharing\ShareWriter;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\Files\IRootFolder;

/** Creates password-protected links that allow upload-only folder access. */
final class CreateUploadLink implements WriteTool {
    public function __construct(private IRootFolder $rootFolder, private ShareWriter $writer) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_create_upload_link',
            module: 'files',
            app: 'files_sharing',
            operation: 'upload_link',
            destructiveHint: true,
            description: SharingMessages::uploadLinkTool(),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()],
                    'expires' => ['type' => 'string', 'minLength' => 1, 'description' => SharingMessages::shareExpiresParam()],
                    'expiry_mode' => ['type' => 'string', 'enum' => ['preserve', 'set', 'remove'], 'description' => SharingMessages::shareExpiryModeParam()],
                    'password_mode' => ['type' => 'string', 'enum' => ['preserve', 'set'], 'default' => 'preserve', 'description' => SharingMessages::uploadPasswordModeParam()],
                    PlanState::ARGUMENT => PlanState::property(),
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $root = $this->rootFolder->getUserFolder($context->userId);
        $arguments = $this->writerArguments($arguments);
        return NodeAccess::run(fn (): array => ToolResult::structured(
            ToolResult::json($result = $this->writer->applyUploadLink($root, $context->userId, $arguments))['content'][0]['text'],
            $result,
        ));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $root = $this->rootFolder->getUserFolder($context->userId);
        $arguments = $this->writerArguments($arguments);
        return NodeAccess::run(fn (): array => $this->writer->planUploadLink($root, $context->userId, $arguments));
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function writerArguments(array $arguments): array {
        return $arguments + ['with' => 'link', 'permission' => 'view', 'password_mode' => 'preserve'];
    }
}
