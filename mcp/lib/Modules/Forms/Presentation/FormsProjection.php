<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Native entities are untrusted data; every public surface has an explicit projection. */
final class FormsProjection {
    public const FORM_FIELDS=['id','title','description','ownerId','created','lastUpdated','expires','access','isAnonymous','submitMultiple','allowEditSubmissions','maxSubmissions','showExpiration','submissionMessage','allowComments','confirmationEmailEnabled','confirmationEmailSubject','confirmationEmailBody','confirmationEmailQuestionId','state','lockedBy','lockedUntil','permissions'];
    private const QUESTION_FIELDS=['id','formId','text','description','name','type','isRequired','order','extraSettings','accept'];
    public static function form(array $raw,array $args=[]):array {
        $out=array_intersect_key($raw,array_flip(self::FORM_FIELDS));
        if(isset($raw['questions'])&&is_array($raw['questions'])) {
            $questions=$raw['questions'];$offset=$args['offset']??0;$limit=$args['limit']??50;
            $out['questions']=array_map(static fn(array $q):array=>array_intersect_key($q,array_flip(self::QUESTION_FIELDS)),array_slice($questions,$offset,$limit));
            $out+=self::paging(count($questions),$offset,count($out['questions']));
        }
        return self::bounded($out);
    }
    public static function read(string $tool,array $raw,array $args):array {
        if(in_array($tool,['forms_search','forms_read_result'],true))return self::bounded($raw);
        if($tool==='forms_read_operation_impact') {
            $allowed=($raw['collection']??null)==='files'?['file_id','path','name','size','etag','owner','action','destination','recovery']:
                ['native_share_id','recipient','type','folder_ids','managed_share_ids','action','destination','destination_owner','source_action','permissions'];
            return self::bounded(array_intersect_key($raw,array_flip(['collection','total','hasMore','nextCursor']))+
                ['items'=>array_map(static fn(array $row):array=>array_intersect_key($row,array_flip($allowed)),$raw['items']??[])]);
        }
        if($tool==='forms_read_recovered_comments') {
            $rows=[];foreach($raw['comments']??[] as $row)$rows[]=array_intersect_key($row,array_flip(['id','parent_id','root_id','message','verb','created_at','latest_child_at','expire_at','historical']))+
                ['author'=>array_intersect_key($row['author']??[],array_flip(['type','id','identity_status']))];
            return self::bounded(['comments'=>$rows]+array_intersect_key($raw,array_flip(['total','restored_form_id','read_only','hasMore','nextCursor'])));
        }
        if($tool==='forms_read_submission_form')return self::submissionForm($raw,$args);
        if($tool==='forms_read_own_response'){self::nativePage($raw,$args);return self::response($raw,$args);}
        if($tool==='forms_read_settings')return self::bounded(self::form($raw,$args)+(isset($raw['linkedExport'])?['linkedExport'=>array_intersect_key($raw['linkedExport'],array_flip(['form_id','association_id','version','state','pending','last_completed_generation']))]:[]));
        if($tool==='forms_read_question') {
            $out=array_intersect_key($raw,array_flip(self::QUESTION_FIELDS));$offset=$args['offset']??0;$limit=$args['limit']??50;
            $options=$raw['options']??[];
            $out['options']=array_map(static fn(array $o):array=>array_intersect_key($o,array_flip(['id','questionId','text','order','optionType'])),array_slice($options,$offset,$limit));
            return self::bounded($out+self::paging(count($options),$offset,count($out['options'])));
        }
        if(in_array($tool,['forms_list_shares','forms_read_links','forms_get_embed'],true)) {
            $items=$raw['shares']??$raw;$items=array_values(array_filter($items,'is_array'));
            if($tool==='forms_read_links')$items=array_values(array_filter($items,static fn(array $s):bool=>($s['shareType']??null)===3));
            $offset=$args['offset']??0;$limit=$args['limit']??50;$out=[];
            foreach(array_slice($items,$offset,$limit) as $share) {
                $s=array_intersect_key($share,array_flip(['id','formId','shareType','permissions','displayName']));
                if(($share['shareType']??null)!==3&&isset($share['shareWith']))$s['shareWith']=$share['shareWith'];
                if($tool!=='forms_list_shares'&&($share['shareType']??null)===3){$s['token']=$share['shareWith']??'';$s+=array_intersect_key($share,array_flip(['url','embedUrl','embedHtml']));}
                $out[]=$s;
            }
            return self::bounded(['shares'=>$out]+self::paging(count($items),$offset,count($out)));
        }
        if($tool==='forms_read_response')return self::response($raw,$args);
        if($tool==='forms_read_own_responses') {
            $rows=$raw['submissions']??$raw;
            return self::bounded(['submissions'=>array_map(static fn(array $s):array=>self::response($s,[]),$rows)]+(isset($raw['_mcp_page'])?self::nativePage($raw,$args):['hasMore'=>count($rows)>=($args['limit']??50),'nextOffset'=>count($rows)>=($args['limit']??50)?($args['offset']??0)+count($rows):null]));
        }
        if($tool==='forms_read_admin_settings')return self::bounded(array_intersect_key($raw,array_flip(['allowPermitAll','allowPublicLink','allowCustomPublicShareTokens','allowShowToAll','creationAllowedGroups','restrictCreation','allowConfirmationEmail','confirmationEmailRateLimit','allowComments','isMailConfigured','canCreateForms'])));
        if($tool==='forms_list_comments') {
            $rows=$raw['comments']??$raw;$out=[];
            foreach($rows as $row)$out[]=array_intersect_key($row,array_flip(['id','message','actorId','actorType','actorDisplayName','creationDateTime','parentId','isUnread','canEdit','canDelete']));
            return self::bounded(['comments'=>$out,'hasMore'=>$raw['hasMore']??false,'nextOffset'=>$raw['nextOffset']??null]);
        }
        if($tool==='forms_list_response_files')return self::bounded(['files'=>array_map(static fn(array $r):array=>array_intersect_key($r,array_flip(['form_id','submission_id','file_id','question_id','name','size','mime'])), $raw['files']??[])]+(isset($raw['_mcp_page'])?self::nativePage($raw,$args):[]));
        throw new ToolFailure('Unsupported Forms read projection.');
    }
    public static function response(array $raw,array $args):array {
        $out=array_intersect_key($raw,array_flip(['id','formId','timestamp']));
        $anonymous=($raw['isAnonymous']??false)||str_starts_with($raw['userId']??'','anon-user-');
        if(!$anonymous&&($args['include_respondents']??false))$out+=array_intersect_key($raw,array_flip(['userId','userDisplayName']));
        $all=$raw['answers']??[];$offset=$args['offset']??0;$limit=$args['limit']??50;$answers=[];$files=false;
        if(isset($raw['_mcp_page'])&&count($all)>$limit)throw new ToolFailure('The native Forms answer page exceeds its bound.');
        foreach(isset($raw['_mcp_page'])?$all:array_slice($all,$offset,$limit) as $answer) {
            if(($answer['fileId']??0)>0){$files=true;continue;}
            $answers[]=array_intersect_key($answer,array_flip(['id','submissionId','questionId','text']));
        }
        return self::bounded($out+['answers'=>$answers,'fileAnswersOmitted'=>$files]+(isset($raw['_mcp_page'])?self::nativePage($raw,$args):self::paging(count($all),$offset,min($limit,max(0,count($all)-$offset)))));
    }
    public static function mutation(mixed $raw,string $tool=''):mixed {
        if(!is_array($raw))return null;
        if(isset($raw['bulk_id'])&&in_array($tool,[...\OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow::ACTIONS,'forms_continue_operation','forms_cancel_operation'],true)) {
            $out=array_intersect_key($raw,array_flip(['bulk_id','state','phase','version','complete','continuation','next']));
            $out['progress']=array_intersect_key($raw['progress']??[],array_flip(['done','total','captured','capture_total']));
            $out['retained_files']=array_intersect_key($raw['retained_files']??[],array_flip(['recovery','copies','evidence_id','backup_sealed']));
            return self::bounded($out);
        }
        if($tool==='forms_upload_response_files')return self::bounded(['files'=>array_map(static fn(array $f):array=>array_intersect_key($f,array_flip(['handle','name','size','expires_at'])),$raw['files']??[])]);
        if(in_array($tool,['forms_summarize_results','forms_download_results','forms_export_results','forms_link_results_file','forms_continue_operation','forms_cancel_operation'],true))return self::bounded(array_intersect_key($raw,array_flip(['job_id','state','done','total','source_revision','complete','bytes','format','continuation','step_budget_exhausted','cache_expires_at','next','file','download_url','expires_at','single_use'])));
        if($tool==='forms_generate_link_token')return self::bounded(array_intersect_key($raw,array_flip(['token'])));
        if(in_array($tool,['forms_share','forms_update_share'],true)) {
            $share=array_intersect_key($raw,array_flip(['id','formId','shareType','permissions','displayName']));
            if(($raw['shareType']??null)===3)$share['token']=$raw['shareWith']??'';
            elseif(isset($raw['shareWith']))$share['shareWith']=$raw['shareWith'];
            return self::bounded($share);
        }
        if($tool==='forms_save_response_file')return self::bounded(array_intersect_key($raw,array_flip(['fileId','file_id','path','size','bytes','sha256'])));
        if(array_is_list($raw))return array_map(static fn(mixed $v):mixed=>self::mutation($v,$tool),$raw);
        return self::bounded(array_intersect_key($raw,array_flip(['id','formId','questionId','title','description','text','type','isRequired','state','order','optionType'])));
    }
    public static function bounded(array $data):array {
        if(strlen(json_encode($data,JSON_THROW_ON_ERROR))>1048576)throw new ToolFailure('Forms output exceeds the page byte budget; request a smaller page.');
        return $data;
    }
    /** A qualified native page is already sliced. Never apply its offset a second time. */
    private static function nativePage(array $raw,array $args):array {
        $page=$raw['_mcp_page']??null;$offset=$args['offset']??0;$limit=$args['limit']??50;
        if(!is_array($page)||($page['offset']??null)!==$offset||!is_bool($page['hasMore']??null)
            ||!array_key_exists('nextOffset',$page)
            ||($page['hasMore']?(!is_int($page['nextOffset'])||$page['nextOffset']<=$offset||$page['nextOffset']>$offset+$limit):$page['nextOffset']!==null))
            throw new ToolFailure('The native Forms page does not match the requested continuation.');
        return ['hasMore'=>$page['hasMore'],'nextOffset'=>$page['nextOffset']];
    }
    private static function submissionForm(array $raw,array $args):array {
        $form=array_intersect_key($raw['form']??[],array_flip(['id','title','description','isAnonymous','submitMultiple','allowEditSubmissions','maxSubmissions','showExpiration','expires','submissionMessage','state']));
        $out=['form'=>$form,'revision'=>$raw['revision']??null]+self::nativePage($raw,$args);
        $question=static fn(array $row):array=>array_intersect_key($row,array_flip(self::QUESTION_FIELDS));
        if(isset($args['question_id'])) {
            $row=$raw['question']??[];$options=$row['options']??[];
            if(($row['id']??null)!==$args['question_id']||count($options)>($args['limit']??50))throw new ToolFailure('The native public question page is unavailable.');
            $out['question']=$question($row)+['options'=>array_map(static fn(array $option):array=>array_intersect_key($option,array_flip(['id','questionId','text','order','optionType'])),$options)];
        } else {
            $rows=$raw['questions']??[];
            if(count($rows)>($args['limit']??50))throw new ToolFailure('The native public question page exceeds its bound.');
            $out['questions']=array_map($question,$rows);
        }
        return self::bounded($out);
    }
    private static function paging(int $total,int $offset,int $count):array {
        $more=$offset+$count<$total;
        return ['hasMore'=>$more,'nextOffset'=>$more?$offset+$count:null];
    }
}
