<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Scheduling\InvitationResponse;

final class RespondToInvitation implements WriteTool {
    public const NAME = 'calendar_respond_to_invitation';

    public function __construct(private InvitationResponse $operation) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: self::NAME,
            module: 'calendar',
            operation: 'edit',
            description: 'Responds as you to an invitation on your own calendar. The change can notify the organizer through Nextcloud. Show the preview and obtain explicit approval before repeating with confirm=true and the preview etag. Email delivery is not verified.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'calendar' => DavCollectionSchema::calendar('Calendar path, as returned by calendar_list_calendars'),
                    'uid' => DavCollectionSchema::uid(),
                    'response' => ['type' => 'string', 'enum' => InvitationResponse::RESPONSES],
                    'etag' => DavCollectionSchema::etag(),
                ],
                'additionalProperties' => false,
                'required' => ['calendar', 'uid', 'response'],
            ],
            app: 'calendar',
            destructiveHint: true,
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->operation->preview($arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->execute($arguments, $context->userId);
    }
}
