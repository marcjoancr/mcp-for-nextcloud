<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Comments;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
/** Native admission owns source/restored comment ACLs. Historical text is never reposted as a live comment. */
final class FormsRecoveredCommentsReader {
    public function __construct(private FormsRecoveredCommentsCursor $cursors) {}
    /** The origin factory is trusted server code and must sign current read_comments plus original recovery authority. */
    public function snapshot(string $uid,string $operation,array $refs,object $guard,array $statusOrigin,\Closure $commentOrigin):array {
        $authority=FormsBulkAuthority::checked($refs['bulk_authority']??null);$archive=$refs['bulk_id']??null;
        if($authority['tool']!=='forms_restore'||($refs['bulk_action']??null)!=='forms_restore'
            ||!is_string($archive)||preg_match('/^[a-f0-9]{64}$/D',$archive)!==1)
            throw new ToolFailure('A completed original Forms restoration is required.');
        $dto=$guard->bulkStatus($uid,$archive,$statusOrigin);
        $evidence=FormsBulkWorkflow::outcome($dto,'forms_restore');$restored=$evidence['references']['restored_form_id']??null;
        if(($dto['bulk_id']??null)!==$archive||($dto['state']??null)!=='completed'||!is_int($restored)||$restored<1)
            throw new ToolFailure('Historical comments are available only after complete restoration.');
        $origin=$commentOrigin($restored,$authority);
        $probe=$guard->bulkCommentsPage($uid,$archive,$origin,0,1);
        $this->page($probe,$archive,$restored,0,1,null);
        return FormsProjection::bounded(['revision'=>'recovered-comments:'.hash('sha256',json_encode([$archive,$restored,$dto['version'],$probe['total']],JSON_THROW_ON_ERROR)),
            'archive_id'=>$archive,'restored_form_id'=>$restored,'total'=>$probe['total'],'bulk_authority'=>$authority,
            'comments_origin'=>$origin,'form'=>['id'=>$restored,'ownerId'=>$uid]]);
    }
    public function read(string $uid,string $operation,array $snapshot,?string $cursor,int $limit,object $guard):array {
        if($limit<1||$limit>100)throw new ToolFailure('The historical comments page size is invalid.');
        $archive=$snapshot['archive_id'];$restored=$snapshot['restored_form_id'];
        $after=$cursor===null?0:$this->cursors->verify($uid,$cursor,$operation,$archive);
        $page=$guard->bulkCommentsPage($uid,$archive,$snapshot['comments_origin'],$after,$limit);
        $this->page($page,$archive,$restored,$after,$limit,$snapshot['total']);$comments=[];
        foreach($page['items'] as $row) {
            $author=array_intersect_key($row['author'],array_flip(['type','id','identity_status']));
            $comments[]=array_intersect_key($row,array_flip(['id','parent_id','root_id','message','verb','created_at','latest_child_at','expire_at','historical']))+['author'=>$author];
        }
        return FormsProjection::bounded(['comments'=>$comments,'total'=>$page['total'],'restored_form_id'=>$restored,'read_only'=>true,
            'hasMore'=>!$page['complete'],'nextCursor'=>$page['complete']?null:$this->cursors->create($uid,$operation,$archive,$page['next_cursor'])]);
    }
    private function page(array $page,string $archive,int $restored,int $after,int $limit,?int $total):void {
        FormsProjection::bounded($page);
        if(($page['archive_id']??null)!==$archive||($page['restored_form_id']??null)!==$restored
            ||!is_int($page['total']??null)||$page['total']<0||$page['total']>100000||($total!==null&&$page['total']!==$total)
            ||!is_array($page['items']??null)||!array_is_list($page['items'])||count($page['items'])>$limit||count($page['items'])>$page['total']
            ||!is_bool($page['complete']??null)||!array_key_exists('next_cursor',$page)
            ||($page['complete']?$page['next_cursor']!==null:($page['items']===[]||!is_int($page['next_cursor'])||$page['next_cursor']<=$after)))
            throw new ToolFailure('Historical Forms comment evidence is incomplete or belongs to another archive.');
        $prefix='archive:'.$archive.':';$last=$after;
        foreach($page['items'] as $row) {
            $id=$row['source_id']??null;$author=$row['author']??null;
            if(!is_int($id)||$id<=$last||($row['id']??null)!==$prefix.$id||($row['historical']??null)!==true
                ||!is_string($row['message']??null)||!is_array($author)||!is_string($author['type']??null)||!is_string($author['id']??null)
                ||!in_array($author['identity_status']??null,['verified_historical','unverified_historical'],true))
                throw new ToolFailure('The historical Forms comment identity is invalid.');
            foreach(['parent_id','root_id'] as $key) {
                $value=$row[$key]??null;
                if($key==='parent_id'&&$value===null)continue;
                if(!is_string($value)||!str_starts_with($value,$prefix)||preg_match('/^[1-9][0-9]*$/D',substr($value,strlen($prefix)))!==1)
                    throw new ToolFailure('The historical Forms comment thread belongs to another archive.');
            }
            $last=$id;
        }
        if(!$page['complete']&&$page['next_cursor']!==$last)throw new ToolFailure('The historical Forms comment continuation skipped a row.');
    }
}
