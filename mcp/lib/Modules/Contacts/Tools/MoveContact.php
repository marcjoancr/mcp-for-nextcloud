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

final class MoveContact implements WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Relocation\ContactTransfer $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'contacts_move_contact',
            module: 'contacts',
            operation: 'create',
            description: 'Move a contact between writable address books. Native CardDAV creates and verifies the destination first; a verified vCard backup is saved before the source is permanently deleted. If backup or deletion fails, the source remains and the result explains the partial outcome. Preview first and repeat with confirm=true and the source ETag from the plan; shared books require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'sourceAddressbook' => DavCollectionSchema::text('Source address-book path returned by List address books.', 1, 1024),
                    'sourceUri' => DavCollectionSchema::text('Source contact URI returned by Search contacts.', 1, 255),
                    'targetAddressbook' => DavCollectionSchema::text('Writable destination address-book path.', 1, 1024),
                    'etag' => DavCollectionSchema::etag('Source ETag returned by the preview; required when confirming the move.'),
                    PlanState::ARGUMENT => PlanState::property(),
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'Acknowledge changes to shared source or destination address books after seeing their owner notices.',
                    ],
                ],
                'additionalProperties' => false,
                'required' => ['sourceAddressbook', 'sourceUri', 'targetAddressbook'],
            ],
            app: 'contacts',
            destructiveHint: true,
            grantAllOf: ['contacts' => ['read', 'delete']]
        );
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->moveContact($arguments, $context->userId);
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->operation->previewMove($arguments, $context->userId);
    }
}
