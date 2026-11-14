<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Access\Grants\GrantPolicy;
/** Private original admission retained across bounded lifecycle steps; never supplied by a tool caller. */
final class FormsBulkAuthority {
    public static function checked(mixed $authority):array {
        if(!is_array($authority)||count($authority)!==3
            ||!in_array($authority['tool']??null,FormsBulkWorkflow::ACTIONS,true))
            throw new FormsBeforeEffect('The original Forms bulk authority is unavailable.');
        foreach(['required_operations'=>'forms','files_operations'=>'files'] as $key=>$module) {
            $operations=$authority[$key]??null;
            if(!is_array($operations)||!array_is_list($operations)||count($operations)>64)
                throw new FormsBeforeEffect('The original Forms bulk authority is invalid.');
            foreach($operations as $operation)if(!is_string($operation)||!in_array($operation,GrantPolicy::CATALOG[$module],true))
                throw new FormsBeforeEffect('The original Forms bulk authority is invalid.');
            if(count(array_unique($operations))!==count($operations))throw new FormsBeforeEffect('The original Forms bulk authority is invalid.');
        }
        if(!in_array(FormsCommands::commands()[$authority['tool']]['operation'],$authority['required_operations'],true))
            throw new FormsBeforeEffect('The original Forms bulk grant is missing.');
        return $authority;
    }
}
