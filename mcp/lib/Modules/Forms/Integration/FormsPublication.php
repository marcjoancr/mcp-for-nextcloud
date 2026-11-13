<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;
/** A qualified native build does not by itself prove an MCP orchestration path is implemented. */
final class FormsPublication {
    private const MCP=['forms_search','forms_read_result','forms_summarize_results','forms_download_results','forms_export_results','forms_continue_operation','forms_cancel_operation','forms_generate_link_token'];
    private const READS=['forms_read_settings','forms_read_question','forms_list_shares','forms_read_links','forms_get_embed','forms_read_own_responses','forms_read_response','forms_list_response_files','forms_read_admin_settings','forms_get_operation','forms_list_operations'];
    public static function supported(string $tool,array $native):bool {
        if($tool==='forms_capabilities')return true;
        if(($native['qualified']??false)!==true||($native['protocol']??null)!==1)return false;
        return in_array($tool,self::MCP,true)||in_array($tool,self::READS,true)||in_array($tool,$native['actions']??[],true);
    }
}
