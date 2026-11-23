<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
final class CreateContact implements \OCA\Mcp\Mcp\Tools\WriteTool
{
    public function __construct(private \OCA\Mcp\Modules\Contacts\Creation\ContactCreator $operation)
    {
    }
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
    {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
            name: 'contacts_create_contact',
            module: 'contacts',
            operation: 'create',
            description: 'Create a contact in a writable personal or shared address book. Returns a detailed plan without writing. Show it to the user, wait for explicit approval, then repeat with confirm=true and the optional etag from the plan. Shared collections also require confirm_shared=true.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object) [
                    'addressbook' => DavCollectionSchema::text('Address book path returned by List address books.', 1, 1024),
                    'name' => DavCollectionSchema::text('Contact name. Omit to preserve its existing value.', 1, 1024),
                    'organization' => DavCollectionSchema::text('Organization company name (first ORG component); existing departments and units are preserved.', 0, 1024),
                    'title' => DavCollectionSchema::text('Contact title. Omit to preserve its existing value.', 0, 1024),
                    'note' => DavCollectionSchema::text('Contact note. Omit to preserve its existing value.', 0, 65536),
                    'url' => DavCollectionSchema::text('Contact url. Omit to preserve its existing value.', 0, 1024),
                    'addresses' => [
                        'type' => 'array',
                        'maxItems' => 30,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'postOfficeBox' => DavCollectionSchema::text('Post office box.', 0, 1024),
                                'extended' => DavCollectionSchema::text('Extended address or apartment.', 0, 1024),
                                'street' => DavCollectionSchema::text('Street address.', 0, 1024),
                                'locality' => DavCollectionSchema::text('City or locality.', 0, 1024),
                                'region' => DavCollectionSchema::text('Region or state.', 0, 1024),
                                'postalCode' => DavCollectionSchema::text('Postal code.', 0, 128),
                                'country' => DavCollectionSchema::text('Country.', 0, 255),
                                'type' => DavCollectionSchema::text('Address type, such as HOME or WORK.', 0, 32),
                            ],
                            'additionalProperties' => false,
                        ],
                        'description' => 'Replace structured ADR addresses in order. Omit to keep; [] to clear.',
                    ],
                    'birthday' => DavCollectionSchema::text('Birthday in a vCard date format, such as YYYY-MM-DD. Use an empty string to clear.', 0, 64),
                    'preferredEmail' => DavCollectionSchema::text('Email address to mark as preferred. It must exactly match an email on this contact; empty clears the preference.', 0, 1024),
                    'categories' => [
                        'type' => 'array',
                        'maxItems' => 100,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'description' => 'Replace vCard CATEGORIES (the groups shown by contacts_list_groups). Omit to keep; [] to clear.',
                    ],
                    'familyName' => DavCollectionSchema::text('Structured N family name.', 0, 1024),
                    'givenName' => DavCollectionSchema::text('Structured N given name.', 0, 1024),
                    'additionalName' => DavCollectionSchema::text('Structured N additional name.', 0, 1024),
                    'namePrefix' => DavCollectionSchema::text('Structured N honorific prefix.', 0, 255),
                    'nameSuffix' => DavCollectionSchema::text('Structured N honorific suffix.', 0, 255),
                    'emails' => [
                        'type' => 'array',
                        'maxItems' => 50,
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 1024,
                            'description' => 'Value.',
                        ],
                        'description' => 'Replace the emails in order; existing parameters are preserved. Omit to keep all values, [] to clear them.',
                    ],
                    'phones' => [
                        'type' => 'array',
                        'maxItems' => 50,
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 1024,
                            'description' => 'Value.',
                        ],
                        'description' => 'Replace the phones in order; existing parameters are preserved. Omit to keep all values, [] to clear them.',
                    ],
                    'confirm_shared' => [
                        'type' => 'boolean',
                        'description' => 'Acknowledge changing a collection owned by another person after showing the shared notice.',
                    ],
                ],
                'additionalProperties' => false,
                'required' => [
                    'addressbook',
                    'name',
                ],
            ],
            app: 'contacts'
        );
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($this->preview($arguments, $context));
        }
        return $this->operation->execute($arguments, $context->userId);
    }
    public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
    {
        return $this->operation->preview($arguments, $context->userId);
    }
}
