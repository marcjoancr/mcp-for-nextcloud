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
use OCA\Mcp\Modules\Contacts\Merging\ContactMerger;

final class MergeContacts implements WriteTool
{
    public function __construct(private ContactMerger $operation)
    {
    }

    public static function definition(): ToolDefinition
    {
        $choice = [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 255,
            'description' => 'URI of one contact shown for this conflict, or clear to remove the property.',
        ];
        return new ToolDefinition(
            name: ContactMerger::TOOL,
            module: 'contacts',
            operation: 'edit',
            description: 'Merge 2 to 10 contacts from one writable address book into the selected contact. Combines distinct multi-value and unknown vCard properties, preserving parameters. Review conflicts and explicitly choose a source contact or clear value for each. Every original contact is backed up and verified before the consolidated card is written; sources are deleted only after the consolidated card is read back and verified. Preview first, then confirm with its plan_state and every current ETag. Shared books require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'groups' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 5,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'addressbook' => DavCollectionSchema::text('Writable address-book path returned by List address books.', 1, 1024),
                                'keepUri' => DavCollectionSchema::text('URI of the contact that will keep its UID and address.', 1, 255),
                                'contacts' => [
                                    'type' => 'array',
                                    'minItems' => 2,
                                    'maxItems' => 10,
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'uri' => DavCollectionSchema::text('Contact URI returned by Find duplicate contacts.', 1, 255),
                                            'etag' => DavCollectionSchema::etag('Current ETag returned by Find duplicate contacts.'),
                                        ],
                                        'required' => ['uri', 'etag'],
                                        'additionalProperties' => false,
                                    ],
                                    'description' => 'The bounded duplicate group to merge; include keepUri and every source URI exactly once.',
                                ],
                                'choices' => [
                                    'type' => 'object',
                                    'properties' => (object) [
                                        'name' => $choice,
                                        'structuredName' => $choice,
                                        'organization' => $choice,
                                        'title' => $choice,
                                        'note' => $choice,
                                        'url' => $choice,
                                        'birthday' => $choice,
                                            'preferredEmail' => $choice,
                                    ],
                                    'additionalProperties' => false,
                                    'description' => 'For each conflict shown in the preview, choose a candidate URI or clear. Omit fields without conflicts.',
                                ],
                            ],
                            'required' => ['addressbook', 'keepUri', 'contacts'],
                            'additionalProperties' => false,
                        ],
                        'description' => 'Merge each group independently. Each group must contain 2–10 contacts from one writable address book. Keep groups disjoint.',
                    ],
                    PlanState::ARGUMENT => PlanState::property(),
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'Acknowledge the effect on other people when the address book is shared.',
                    ],
                ],
                'required' => ['groups'],
                'additionalProperties' => false,
            ],
            app: 'contacts',
            destructiveHint: true,
            grantAllOf: ['contacts' => ['read', 'delete']],
        );
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
            return $this->operation->mergeBatch($arguments, $context->userId);
    }

    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->operation->previewBatch($arguments, $context->userId);
    }
}
