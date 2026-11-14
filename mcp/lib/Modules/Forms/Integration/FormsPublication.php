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
        if(in_array($tool,['forms_read_operation_impact','forms_read_recovered_comments'],true))return in_array($tool,self::MCP,true)
            &&($native['qualified']??false)===true&&($native['protocol']??null)===1&&($native['bulk_lifecycle']??false)===true
            &&($tool!=='forms_read_recovered_comments'||($native['comments']??false)===true);
        if(in_array($tool,['forms_list_comments','forms_add_comment','forms_update_comment','forms_delete_comment'],true))return in_array($tool,self::MCP,true)&&($native['qualified']??false)===true&&($native['protocol']??null)===1&&($native['comments']??false)===true;
        if(($native['qualified']??false)!==true||($native['protocol']??null)!==1)return false;
        if(in_array($tool,['forms_delete_form','forms_delete_responses','forms_transfer'],true))return ($native['bulk_lifecycle']??false)===true
            &&($native['bulk_source_writer_ready']??false)===true&&in_array($tool,$native['actions']??[],true);
        if(in_array($tool,['forms_link_results_file','forms_unlink_results_file'],true))return in_array($tool,self::MCP,true)&&($native['linked_exports']??false)===true&&($native['file_sinks']??false)===true;
        if($tool==='forms_read_own_response'&&($native['own_response_pages']??false)!==true)return false;
        if($tool==='forms_read_submission_form'&&($native['public_submission_form']??false)!==true)return false;
        if(in_array($tool,['forms_submit_response','forms_update_response','forms_update_own_response'],true)&&($native['answers_preflight']??false)!==true)return false;
        if(in_array($tool,['forms_export_results','forms_save_response_file','forms_link_results_file'],true)&&($native['file_sinks']??false)!==true)return false;
        return in_array($tool,self::MCP,true)||in_array($tool,self::READS,true)||in_array($tool,$native['actions']??[],true);
    }
}
