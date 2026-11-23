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
use OCA\Mcp\Modules\Contacts\Exchange\VcfExchange;

final class ReconcileVcf implements WriteTool {
    public function __construct(private VcfExchange $exchange) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'contacts_reconcile_vcf',
            module: 'contacts',
            operation: 'edit',
            description: 'Reconcile existing contacts from a vCard file by exact, unambiguous UID. The plan shows changed and unchanged fields. Only fields present in the source are replaced; local fields absent from the source and unknown vCard properties are preserved. A verified vCard backup is saved before each update. Missing or ambiguous UIDs are reported for separate review. Preview first and confirm its exact plan; shared books require separate confirmation.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'addressbook' => DavCollectionSchema::text('Writable address book path returned by List address books.', 1, 1024),
                    'path' => DavCollectionSchema::text('Authorized vCard file path within Files.', 1, 1024),
                    PlanState::ARGUMENT => PlanState::property(),
                    'confirm_shared' => ['type' => 'boolean', 'description' => 'Separately acknowledge changing another owner’s shared address book.'],
                ],
                'required' => ['addressbook', 'path'],
                'additionalProperties' => false,
            ],
            app: 'contacts',
            destructiveHint: true,
            grantAllOf: ['files' => ['read'], 'contacts' => ['read']],
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->exchange->previewReconcile($arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($this->preview($arguments, $context));
        }
        return $this->exchange->reconcile($arguments, $context->userId);
    }
}
