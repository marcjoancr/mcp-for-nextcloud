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
use OCA\Mcp\Modules\Contacts\Exchange\VcfBundle;
use OCA\Mcp\Modules\Contacts\Exchange\VcfExchange;

final class ExportVcf implements Tool {
    public function __construct(private VcfExchange $exchange) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'contacts_export_vcf',
            module: 'contacts',
            operation: 'read',
            description: 'Returns up to 100 selected contacts from a visible address book as a text/vcard document to save as a .vcf file (1 MiB). The output contains complete cards, including custom properties.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'addressbook' => DavCollectionSchema::text('Visible address book path', 1, 1024),
                    'uris' => [
                        'type' => 'array', 'items' => DavCollectionSchema::text('Contact URI from search', 1, 255),
                        'minItems' => 1, 'maxItems' => VcfBundle::MAX_CARDS, 'uniqueItems' => true,
                    ],
                ],
                'required' => ['addressbook', 'uris'],
                'additionalProperties' => false,
            ],
            app: 'contacts',
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->exchange->export($arguments, $context->userId);
    }
}
