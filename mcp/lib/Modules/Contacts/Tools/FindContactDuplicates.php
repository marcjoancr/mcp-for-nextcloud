<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Contacts\Duplicates\ContactDuplicateFinder;

final class FindContactDuplicates implements Tool {
    public function __construct(private ContactDuplicateFinder $finder) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'contacts_find_duplicates',
            module: 'contacts',
            operation: 'read',
            description: 'Lists possible duplicates among visible contacts by matching normalized email or phone, or the same name and organization as a weaker hint. Candidates may represent different people sharing the same details. Includes address books, URIs, and ETags to review the matches.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'addressbook' => DavCollectionSchema::text('Optional visible address book path', 1, 1024),
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    'offset' => DavCollectionSchema::offset(),
                ],
                'additionalProperties' => false,
            ],
            app: 'contacts',
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->finder->execute($arguments, $context->userId);
    }
}
