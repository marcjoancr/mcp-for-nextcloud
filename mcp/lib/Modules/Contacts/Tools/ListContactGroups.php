<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;

final class ListContactGroups implements \OCA\Mcp\Mcp\Tools\Tool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Groups\ContactGroupReader $operation)
    {
    }

    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'contacts_list_groups',
            module: 'contacts',
            operation: 'read',
            description: 'List the distinct vCard CATEGORIES used as contact groups in your visible address books, optionally in one address book. Results include the number of contacts in each group.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Address book path returned by List address books. Omit to scan all visible address books.', 1, 1024),
                    'limit' => DavCollectionSchema::limit(),
                    'offset' => DavCollectionSchema::offset(),
                ],
                'additionalProperties' => false,
            ],
            app: 'contacts'
        );
    }

    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->execute($arguments, $context->userId);
    }
}
