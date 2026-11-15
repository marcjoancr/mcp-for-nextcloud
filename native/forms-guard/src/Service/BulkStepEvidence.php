<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Reconcile only a terminal step's durable postcommit witness; never substitute current job status. */
final class BulkStepEvidence {
    /** @param callable(string):?string $phase Read-only phase lookup for the exact native operation key. */
    public static function resolve(array $receipt,array $job,callable $phase):array {
        if(!AuthoringScale::metadata($job['action']??'')
            ||($job['state']??null)!=='partial'||($job['phase']??null)!=='complete'
            ||($job['share_events']??false)!==true||($job['source_effects']??false)!==true
            ||($job['done']??null)!==1||isset($job['claim'])
            ||($receipt['state']??null)!=='partial'||($receipt['phase']??null)!=='complete'
            ||($receipt['next']??null)!==false||($receipt['bulk_id']??null)!==($job['id']??null)
            ||($receipt['operation_key']??null)!==($job['key']??null)
            ||($receipt['version']??null)!==($job['version']??null)
            ||($receipt['references']['form_id']??null)!==(int)($job['form']['id']??0)
            ||($receipt['references']['backup_format']??null)!=='authoring_effects_v1'
            ||($receipt['references']['evidence_id']??null)!==($job['id']??null)
            ||($receipt['references']['recovery_scope']??null)!=='none'
            ||($receipt['phases']['database']??null)!=='committed'
            ||count($receipt['phases']['events']??[])!==1
            ||($receipt['phases']['events'][0]['kind']??null)!=='files_share'){
            return $receipt;
        }
        $current=$phase($job['key']);
        if($current===null){return $receipt;}
        $receipt['phases']['files']=match($current){
            'native_files_shares_verified'=>'verified',
            'native_files_shares_pending'=>'pending',
            default=>'unknown',
        };
        $receipt['phases']['events'][0]['phase']=match($current){
            'native_files_shares_verified'=>'attempted_unverified',
            'native_files_shares_pending'=>'pending',
            default=>'uncertain',
        };
        return $receipt;
    }
}
