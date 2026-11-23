<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;

final class CopyContact implements WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Relocation\ContactTransfer $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'contacts_copy_contact',
            module: 'contacts',
            operation: 'create',
            description: 'Copy a contact to another writable address book. The destination uses a new UID and is verified after native CardDAV creation; the source is never changed. Preview first and repeat with confirm=true.',
            inputSchema: self::schema(),
            app: 'contacts',
            grantAllOf: ['contacts' => ['read']]
        );
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [
                'sourceAddressbook' => DavCollectionSchema::text('Source address-book path returned by List address books.', 1, 1024),
                'sourceUri' => DavCollectionSchema::text('Source contact URI returned by Search contacts.', 1, 255),
                'targetAddressbook' => DavCollectionSchema::text('Writable destination address-book path.', 1, 1024),
                'etag' => DavCollectionSchema::etag(),
                PlanState::ARGUMENT => PlanState::property(),
                'confirm_shared' => [
                    'type' => 'boolean',
                    'description' => 'Acknowledge writing to a shared destination address book after seeing its owner notice.',
                ],
            ],
            'additionalProperties' => false,
            'required' => ['sourceAddressbook', 'sourceUri', 'targetAddressbook'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->copyContact($arguments, $context->userId);
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->operation->previewCopy($arguments, $context->userId);
    }
}
