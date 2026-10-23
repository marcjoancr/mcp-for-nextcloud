<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck\Tools;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinking;
final class LinkCardFile implements WriteTool {
    public const TOOL = 'deck_link_card_file';
    public function __construct(private CardFileLinking $operation, private PreviewBoundary $previews) {}
    public static function description(): string {
        return 'Links one existing visible file to a Deck card by a native Deck file share, with read-only access for current and future board members. Requires the three grants deck.edit, files.read and files.share, native EDIT/READ/SHARE, a complete recipient preview and confirm:true with its plan_state. The plan names the file, its owner, whether it is a reshare, the current recipient total, a limited name list and the shares you created. Refuses Guests, group-only sharing, a group or team on the board when group sharing is off, mandatory internal expiry, a recipient that is not a fully resolved local user, group or team, folders, archived cards or boards, and any failure to read those facts. Available on Nextcloud 32 through 35. No upload, copy or file deletion. Native sharing hooks and notifications may run; optional default internal expiry does not apply to this Deck share. Does not suggest changing a sharing policy or signing in another way. A policy enabled later, a membership change, and the gap between the last check and the native share are outside this check: there is no continuous protection and no automatic revocation.';
    }
    public static function definition(): ToolDefinition {
        return new ToolDefinition(self::TOOL, 'deck', 'edit', self::description(), DeckSchema::schema([
            'cardId'=>DeckSchema::integer('Card ID.', 1),
            'fileId'=>DeckSchema::integer('Id of the existing visible File in your Files scope. Folders are refused.', 1),
            'confirm_shared'=>['type'=>'boolean','description'=>'Set to true only after the user confirms linking a shared file or changing another user\'s board.'], PlanState::ARGUMENT=>PlanState::property(),
        ], ['cardId', 'fileId']), 'deck', grantAllOf: ['files'=>['read', 'share']]);
    }
    public function execute(array $arguments, ToolContext $context): array { return $this->operation->handle($arguments, $context->userId); }
    public function preview(array $arguments, ToolContext $context): array { return $this->previews->run(self::TOOL, $context->userId, fn()=> $this->operation->preview($arguments, $context->userId)); }
}
