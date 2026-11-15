<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
/** Read-only locator. Consumers must still admit the original action and compare the live native revision. */
final class FormsBulkImpactCursor {
    public function __construct(private PlanState $proof,private ActorIdentity $actors,private ConnectionContext $connection,private ?\OCP\Security\ICrypto $crypto=null) {}
    public function create(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor):string {
        if(in_array($tool,self::AUTHORING,true))return $this->authoringCreate($uid,$tool,$input,$revision,$kind,$cursor);
        $this->target($tool,$input,$revision,$kind,$cursor);
        $data=['v'=>1,'actor'=>$this->actors->capture($uid),'connection'=>$this->connection->current(),
            'tool'=>$tool,'input'=>FormsOperationJournal::canonical($input),'revision'=>$revision,'kind'=>$kind,'cursor'=>$cursor];
        $body=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $token=$body.'.'.$this->proof->of('forms_bulk_impact_cursor_v1',$uid,[$body]);
        if(strlen($token)>4096)$this->invalid();
        return $token;
    }
    private const AUTHORING=['forms_update','forms_update_share','forms_unshare','forms_delete_question'];
    public function verify(string $uid,string $token):array {
        if(strlen($token)>524288)$this->invalid();
        $parts=explode('.',$token);
        if(count($parts)!==2||!preg_match('/^[A-Za-z0-9_-]+$/D',$parts[0])||!preg_match('/^[a-f0-9]{64}$/D',$parts[1])
            )$this->invalid();
        if(hash_equals($this->proof->of('forms_bulk_impact_cursor_v2',$uid,[$parts[0]]),$parts[1]))return $this->authoringVerify($uid,$parts[0]);
        if(strlen($token)>4096||!hash_equals($this->proof->of('forms_bulk_impact_cursor_v1',$uid,[$parts[0]]),$parts[1]))$this->invalid();
        $bytes=base64_decode(strtr($parts[0],'-_','+/'),true);
        if($bytes===false)$this->invalid();
        try {$data=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->invalid();}
        if(!is_array($data)||array_keys($data)!==['v','actor','connection','tool','input','revision','kind','cursor']
            ||$data['v']!==1||!is_string($data['actor'])||$data['connection']!==$this->connection->current()
            ||!is_string($data['tool'])||!is_array($data['input'])||!is_string($data['revision'])
            ||!is_string($data['kind'])||!is_int($data['cursor']))$this->invalid();
        $this->target($data['tool'],$data['input'],$data['revision'],$data['kind'],$data['cursor']);
        $this->actors->assertCurrent($uid,$data['actor']);
        return array_intersect_key($data,array_flip(['tool','input','revision','kind','cursor']));
    }
    /** Larger authored settings and private link capabilities never enter a public plaintext locator. */
    private function authoringCreate(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor):string {
        $this->authoringTarget($tool,$input,$revision,$kind,$cursor);
        if($this->crypto===null)$this->invalid();
        $actor=$this->actors->capture($uid);
        try {$sealed=$this->crypto->encrypt(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
        catch(\Throwable){$this->invalid();}
        $data=['v'=>2,'actor'=>$actor,'connection'=>$this->connection->current(),'tool'=>$tool,'sealed_input'=>$sealed,
            'revision'=>$revision,'kind'=>$kind,'cursor'=>$cursor];
        $body=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $token=$body.'.'.$this->proof->of('forms_bulk_impact_cursor_v2',$uid,[$body]);
        if(strlen($token)>524288)$this->invalid();return $token;
    }
    /** Called only after purpose-specific signature admission; actor and connection precede decryption. */
    private function authoringVerify(string $uid,string $body):array {
        $bytes=base64_decode(strtr($body,'-_','+/'),true);if($bytes===false)$this->invalid();
        try {$data=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->invalid();}
        if(!is_array($data)||array_keys($data)!==['v','actor','connection','tool','sealed_input','revision','kind','cursor']
            ||$data['v']!==2||!is_string($data['actor'])||$data['connection']!==$this->connection->current()
            ||!is_string($data['tool'])||!is_string($data['sealed_input'])||!is_string($data['revision'])
            ||!is_string($data['kind'])||!is_int($data['cursor'])||$this->crypto===null)$this->invalid();
        $this->actors->assertCurrent($uid,$data['actor']);
        try {$plain=$this->crypto->decrypt($data['sealed_input']);if(strlen($plain)>262144)$this->invalid();$input=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}
        catch(\Throwable){$this->invalid();}
        if(!is_array($input))$this->invalid();
        $this->authoringTarget($data['tool'],$input,$data['revision'],$data['kind'],$data['cursor']);
        return ['tool'=>$data['tool'],'input'=>$input,'revision'=>$data['revision'],'kind'=>$data['kind'],'cursor'=>$data['cursor']];
    }
    private function authoringTarget(string $tool,array $input,string $revision,string $kind,int $cursor):void {
        if(!in_array($tool,self::AUTHORING,true)||!preg_match('/^b1:[0-9]{1,20}:[a-f0-9]{64}$/D',$revision)
            ||!in_array($kind,['files','managed_shares'],true)||$cursor<0||($tool==='forms_update'&&!isset($input['fields']['title'])))$this->invalid();
        try {\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate(FormsCommands::definitions()[$tool]['inputSchema'],$input);}
        catch(\Throwable){$this->invalid();}
    }
    private function target(string $tool,array $input,string $revision,string $kind,int $cursor):void {
        $keys=match($tool) {
            'forms_delete_form','forms_delete_responses'=>['id'],
            'forms_transfer'=>['id','owner_id'],
            'forms_restore'=>['backup_id'],
            default=>[],
        };
        if($keys===[]||array_diff(array_keys($input),$keys)!==[]||array_diff($keys,array_keys($input))!==[]
            ||!preg_match('/^b1:[0-9]{1,20}:[a-f0-9]{64}$/D',$revision)
            ||!in_array($kind,['files','managed_shares'],true)||$cursor<0)$this->invalid();
        if($tool==='forms_restore') {
            if(!is_string($input['backup_id'])||$input['backup_id']===''||strlen($input['backup_id'])>128)$this->invalid();
        } else {
            if(!is_int($input['id'])||$input['id']<1)$this->invalid();
            if($tool==='forms_transfer'&&(!is_string($input['owner_id'])||$input['owner_id']===''||mb_strlen($input['owner_id'])>64))$this->invalid();
        }
    }
    private function invalid():never {throw new ToolFailure('The Forms impact cursor is invalid or no longer available.');}
}
