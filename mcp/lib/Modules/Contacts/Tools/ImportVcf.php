<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Contacts\Exchange\VcfExchange;

final class ImportVcf implements WriteTool {
    public function __construct(private VcfExchange $exchange) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: VcfExchange::IMPORT,
            module: 'contacts',
            operation: 'create',
            description: 'Imports up to 100 contacts (1 MiB) from a vCard file visible to you in Files, including a backup saved by contacts_delete_contact. Skips existing contact UIDs. Show the per-contact plan and wait for explicit approval. Repeat with confirm=true and its plan_state. Confirm changes to a shared address book separately.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'addressbook' => DavCollectionSchema::text('Writable address book path', 1, 1024),
                    'path' => DavCollectionSchema::text('Path to the vCard file within Files', 1, 1024),
                    PlanState::ARGUMENT => PlanState::property(),
                    'confirm_shared' => ['type' => 'boolean', 'description' => 'Separate approval for changes to another owner’s book'],
                ],
                'required' => ['addressbook', 'path'],
                'additionalProperties' => false,
            ],
            app: 'contacts',
            grantAllOf: ['files' => ['read']],
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->exchange->preview($arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->exchange->import($arguments, $context->userId);
    }
}
