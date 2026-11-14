<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Comments;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
/** A read-only archive locator. Native source, recovery and comment permissions are still required on every page. */
final class FormsRecoveredCommentsCursor {
    public function __construct(private PlanState $proof,private ActorIdentity $actors,private ConnectionContext $connection) {}
    public function create(string $uid,string $operation,string $archive,int $after):string {
        $this->target($operation,$archive,$after);
        $data=['v'=>1,'actor'=>$this->actors->capture($uid),'connection'=>$this->connection->current(),
            'operation'=>$operation,'archive'=>$archive,'after'=>$after];
        $body=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $token=$body.'.'.$this->proof->of('forms_recovered_comments_cursor_v1',$uid,[$body]);
        if(strlen($token)>1024)$this->invalid();
        return $token;
    }
    public function verify(string $uid,string $token,string $operation,string $archive):int {
        if(strlen($token)>1024)$this->invalid();
        $parts=explode('.',$token);
        if(count($parts)!==2||preg_match('/^[A-Za-z0-9_-]+$/D',$parts[0])!==1||preg_match('/^[a-f0-9]{64}$/D',$parts[1])!==1
            ||!hash_equals($this->proof->of('forms_recovered_comments_cursor_v1',$uid,[$parts[0]]),$parts[1]))$this->invalid();
        $bytes=base64_decode(strtr($parts[0],'-_','+/'),true);if($bytes===false)$this->invalid();
        try {$data=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->invalid();}
        if(!is_array($data)||array_keys($data)!==['v','actor','connection','operation','archive','after']||$data['v']!==1
            ||!is_string($data['actor'])||$data['connection']!==$this->connection->current()
            ||$data['operation']!==$operation||$data['archive']!==$archive||!is_int($data['after']))$this->invalid();
        $this->target($operation,$archive,$data['after']);$this->actors->assertCurrent($uid,$data['actor']);
        return $data['after'];
    }
    private function target(string $operation,string $archive,int $after):void {
        if($operation===''||strlen($operation)>128||preg_match('/^[a-f0-9]{64}$/D',$archive)!==1||$after<0)$this->invalid();
    }
    private function invalid():never {throw new ToolFailure('The recovered Forms comments cursor is invalid or unavailable.');}
}
