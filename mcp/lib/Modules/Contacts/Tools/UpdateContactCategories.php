<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Contacts\Categories\ContactCategoryEditor;

final class UpdateContactCategories implements WriteTool {
    public function __construct(private ContactCategoryEditor $operation) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'contacts_update_categories',
            module: 'contacts',
            operation: 'edit',
            description: 'Add, remove, or rename one category on up to 100 explicitly selected contacts in one writable address book. Preview each affected contact and its current ETag, then confirm the exact plan. Other categories and vCard properties are preserved. Shared books require separate confirmation.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'addressbook' => DavCollectionSchema::text('Writable address book path returned by List address books.', 1, 1024),
                    'contacts' => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => 100,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'uri' => DavCollectionSchema::text('Contact URI returned by Search contacts.', 1, 255),
                                'etag' => DavCollectionSchema::etag('ETag returned by Search contacts; stale contacts are not changed.'),
                            ],
                            'required' => ['uri', 'etag'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'action' => ['type' => 'string', 'enum' => ['add', 'remove', 'rename']],
                    'category' => DavCollectionSchema::text('Category name to add/remove, or source category to rename.', 1, 255),
                    'replacement' => DavCollectionSchema::text('Replacement category name; required for rename.', 1, 255),
                    PlanState::ARGUMENT => PlanState::property(),
                    'confirm_shared' => ['type' => 'boolean', 'description' => 'Separately acknowledge changes to another owner’s shared address book.'],
                ],
                'required' => ['addressbook', 'contacts', 'action', 'category'],
                'additionalProperties' => false,
            ],
            app: 'contacts',
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->operation->preview($arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->execute($arguments, $context->userId);
    }
}
