<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

final class ListAddressBookShares implements Tool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookShareManager $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'contacts_list_addressbook_shares',
            module: 'contacts',
            operation: 'read',
            description: 'List local Nextcloud users with whom one of your own address books is shared, including read or edit access. Shares of books owned by other people are not shown.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Owned address-book path returned by List address books.', 1, 1024),
                ],
                'additionalProperties' => false,
                'required' => ['addressbook'],
            ],
            app: 'contacts'
        );
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->operation->list($arguments, $context->userId);
    }
}
