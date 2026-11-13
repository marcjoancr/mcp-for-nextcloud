<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
final class FormsPlan {
    public static function build(string $tool,array $args,array $snapshot,string $uid):array {
        $form=is_array($snapshot['form']??null)?$snapshot['form']:[];
        $before=FormsProjection::form($form);
        if(in_array($tool,['forms_submit_response','forms_upload_response_files'],true))$before=array_intersect_key($before,array_flip(['id','title','description','isAnonymous','state','expires','questions','hasMore','nextOffset']));
        if(isset($args['question_id']))foreach($form['questions']??[] as $q)if($q['id']===$args['question_id']) {
            $before['question']=array_intersect_key($q,array_flip(['id','text','description','type','name','isRequired','extraSettings']));
            if(isset($args['option_id']))foreach($q['options']??[] as $o)if($o['id']===$args['option_id'])$before['option']=$o;
        }
        if(isset($args['share_id']))foreach($snapshot['shares']??[] as $share)if($share['id']===$args['share_id'])$before['share']=array_intersect_key($share,array_flip(['id','shareType','permissions','displayName']));
        if(isset($snapshot['comment']))$before['comment']=$snapshot['comment'];
        if(str_contains($tool,'admin_settings'))$before=FormsProjection::read('forms_read_admin_settings',$snapshot['config']??[],[]);
        $changes=$args['fields']??$args;
        unset($changes['confirm'],$changes['plan_state'],$changes['operation_id'],$changes['confirm_shared']);
        $impact=$snapshot['impact']??[];$manifest=$snapshot['file_manifest']??[];
        if($manifest!==[]) {
            $files=array_map(static fn(array $f):array=>array_intersect_key($f,array_flip(['file_id','name','size','sha256'])),$manifest['files']??[]);
            $impact['files']=['files'=>$files]+array_intersect_key($manifest,array_flip(['delete_file_ids','files_share','recovery','folders','restore','backup_id']));
        }
        if(isset($snapshot['file_sources']))$impact['sources']=$snapshot['file_sources'];
        if(isset($snapshot['file_destination']))$impact['destination']=$snapshot['file_destination']['plan'];
        if($tool==='forms_transfer')$impact['transfer']='Verified managed files are copied to the new owner; originals and recovery copies are retained. Shares and automatic exports are reviewed separately.';
        return FormsProjection::bounded(['action'=>$tool,'before'=>$before,'changes'=>self::redact($changes),
            'impact'=>self::redact($impact),
            'requiresSharedConfirmation'=>(isset($form['ownerId'])&&$form['ownerId']!==$uid)||($snapshot['file_destination']['plan']['requiresSharedConfirmation']??false),
            'message'=>'Nothing has been changed. Review the complete change, affected data and native notifications before confirming.']);
    }
    private static function redact(array $value):array {
        foreach($value as $key=>&$item) {
            if(in_array((string)$key,['share_hash','token','uploadToken','file_handles'],true))$item='[provided private capability]';
            elseif(is_array($item))$item=self::redact($item);
        }
        return $value;
    }
}
