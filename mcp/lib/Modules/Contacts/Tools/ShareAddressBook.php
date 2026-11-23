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

final class ShareAddressBook implements WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookShareManager $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'contacts_share_addressbook',
            module: 'contacts',
            operation: 'share',
            description: 'Share one of your own address books with a local Nextcloud user as read-only or read-write, change access, or remove an existing share. First call without confirm to review the recipient and action; repeat with confirm=true. Native Nextcloud sharing rules still apply.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Owned address-book path returned by List address books.', 1, 1024),
                    'shareWith' => DavCollectionSchema::text('Exact local Nextcloud user ID.', 1, 255),
                    'access' => [
                        'type' => 'string',
                        'enum' => ['read', 'write', 'remove'],
                        'description' => 'Read-only or read-write access to grant, or remove to revoke an existing share.',
                    ],
                ],
                'additionalProperties' => false,
                'required' => ['addressbook', 'shareWith', 'access'],
            ],
            app: 'contacts'
        );
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->share($arguments, $context->userId);
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->operation->preview($arguments, $context->userId);
    }
}
