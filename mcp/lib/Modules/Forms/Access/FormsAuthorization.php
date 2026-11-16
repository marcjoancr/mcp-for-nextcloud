<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Access;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Argument-dependent authority is explicit and cannot be inferred from discovery's any-of check. */
final class FormsAuthorization {
    public static function fileOperations(string $tool,array $args,array $snapshot):array {
        if(isset($snapshot['historical']['files_operations']))return $snapshot['historical']['files_operations'];
        $ops=FormsCommands::definitions()[$tool]['grantAllOf']['files']??[];
        if($tool==='forms_transfer') {
            $files=$snapshot['counts']['files']??null;$unavailable=$snapshot['historical_unavailable_files']??0;$shares=$snapshot['counts']['managed_shares']??null;
            if(is_int($files)&&$files>=0&&is_int($unavailable)&&$unavailable>=0&&$files>$unavailable
                ||is_int($shares)&&$shares>0)$ops=array_merge($ops,['read','create']);
        } elseif($tool==='forms_restore') {
            $files=$snapshot['counts']['files']??null;$unavailable=$snapshot['historical_unavailable_files']??0;
            if(is_int($files)&&$files>=0&&is_int($unavailable)&&$unavailable>=0&&$files>$unavailable)$ops=array_merge($ops,['read','create']);
        }
        if($args['include_files']??false)$ops[]='read';
        if($args['overwrite']??false)$ops[]='edit';
        if($snapshot['file_manifest']['files_share']??false)$ops[]='share';
        return array_values(array_unique($ops));
    }
    public static function operations(string $tool,array $args,array $snapshot):array {
        if(isset($snapshot['historical']['required_operations']))return $snapshot['historical']['required_operations'];
        $op=FormsCommands::commands()[$tool]['operation']??throw new ToolFailure('Unknown Forms operation.');
        if($tool==='forms_share')$ops=[($args['kind']??'')==='link'?'public_link':'share'];
        elseif(in_array($tool,['forms_update_share','forms_unshare'],true)) {
            if($snapshot===[])return [];
            $share=null;foreach($snapshot['shares']??[] as $candidate)if(($candidate['id']??null)===($args['share_id']??null))$share=$candidate;
            if($share===null||!isset($share['shareType']))throw new ToolFailure(ToolFailure::NOT_FOUND);
            $ops=[(int)$share['shareType']===3?'public_link':'share'];
        } else $ops=[$op];
        if($tool==='forms_update') {
            if(isset($args['fields']['access']))$ops[]='public_link';
            foreach(array_keys($args['fields']??[]) as $field)if(str_starts_with($field,'confirmationEmail')){$ops[]='configure_notifications';break;}
        }
        if($args['include_respondents']??false)$ops[]='read_respondents';
        if($args['include_files']??false)$ops[]='read_response_files';
        return array_values(array_unique($ops));
    }
}
