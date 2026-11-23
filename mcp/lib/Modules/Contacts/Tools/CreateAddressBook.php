<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;

final class CreateAddressBook implements WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookCreator $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'contacts_create_addressbook',
            module: 'contacts',
            operation: 'create',
            description: 'Create a personal CardDAV address book using native MKCOL. First call without confirm to inspect the generated address-book path; repeat with confirm=true to create it. Existing shared address books are not modified.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'displayName' => DavCollectionSchema::text('Name of the new address book.', 1, 255),
                ],
                'additionalProperties' => false,
                'required' => ['displayName'],
            ],
            app: 'contacts'
        );
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->execute($arguments, $context->userId);
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->operation->preview($arguments, $context->userId);
    }
}
