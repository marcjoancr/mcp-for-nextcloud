<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
final class FormsAuthoringImpactCursorTest extends TestCase {
    private ConnectionContext $connection;
    private int $decryptions=0;
    private function cursors():FormsBulkImpactCursor {
        $this->connection=new ConnectionContext();$this->connection->set(12);$encrypted=[];$counter=0;
        $crypto=$this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(static function(string $plain)use(&$encrypted,&$counter):string {
            $cipher='sealed-'.(++$counter).'-'.str_repeat('x',(int)ceil(strlen($plain)*2.01));$encrypted[$cipher]=$plain;return $cipher;
        });
        $crypto->method('decrypt')->willReturnCallback(function(string $cipher)use(&$encrypted):string {++$this->decryptions;return $encrypted[$cipher]??throw new \RuntimeException('Unknown ciphertext.');});
        return new FormsBulkImpactCursor(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$this->connection,$crypto);
    }
    public function testAuthoringCursorSealsPrivateInputAndRetainsExactIntent():void {
        $cursors=$this->cursors();$input=['id'=>4,'share_id'=>7,'permissions'=>['submit','embed'],'token'=>'private-capability'];
        $token=$cursors->create('alice','forms_update_share',$input,'b1:42:'.str_repeat('a',64),'managed_shares',0);
        $body=json_decode(base64_decode(strtr(explode('.',$token)[0],'-_','+/')),true,16,JSON_THROW_ON_ERROR);
        self::assertSame(2,$body['v']);self::assertArrayNotHasKey('input',$body);
        self::assertStringNotContainsString('private-capability',json_encode($body));
        self::assertSame($input,$cursors->verify('alice',$token)['input']);
        self::assertSame(1,$this->decryptions);
    }
    public function testMaximumUnicodeSettingsFitTheNewBoundedLocatorWithoutTruncation():void {
        $cursors=$this->cursors();foreach(['😀',"\u{2028}","\u{0000}"] as $char) {$input=['id'=>4,'fields'=>['title'=>str_repeat($char,256),'description'=>str_repeat($char,8192),
            'confirmationEmailBody'=>str_repeat($char,8192),'confirmationEmailSubject'=>str_repeat($char,255),'submissionMessage'=>str_repeat($char,2048)]];
        $token=$cursors->create('alice','forms_update',$input,'b1:42:'.str_repeat('a',64),'files',0);
        self::assertGreaterThan(4096,strlen($token));self::assertLessThanOrEqual(524288,strlen($token));
        self::assertSame($input,$cursors->verify('alice',$token)['input']);
        self::assertSame($token,ArgumentValidator::validate(FormsCommands::definitions()['forms_read_operation_impact']['inputSchema'],['cursor'=>$token])['cursor']);
        }
    }
    public function testAnotherConnectionCannotDecryptAuthoringInput():void {
        $cursors=$this->cursors();$token=$cursors->create('alice','forms_delete_question',['id'=>4,'question_id'=>7],'b1:42:'.str_repeat('a',64),'files',0);
        $this->connection->set(13);try{$cursors->verify('alice',$token);self::fail('Foreign connection admitted.');}
        catch(ToolFailure){self::assertSame(0,$this->decryptions);}
    }
    public function testTamperedCiphertextCannotReachDecryption():void {
        $cursors=$this->cursors();$token=$cursors->create('alice','forms_update',['id'=>4,'fields'=>['title'=>'Title']],'b1:42:'.str_repeat('a',64),'files',0);
        [$body,$signature]=explode('.',$token);$data=json_decode(base64_decode(strtr($body,'-_','+/')),true,16,JSON_THROW_ON_ERROR);$data['sealed_input']='changed';
        $body=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        try{$cursors->verify('alice',$body.'.'.$signature);self::fail('Modified ciphertext admitted.');}catch(ToolFailure){self::assertSame(0,$this->decryptions);}
    }
    public function testNewActionsCannotCarryUnknownFieldsOrUseUnrelatedUpdateVariants():void {
        $cursors=$this->cursors();foreach([['id'=>4,'question_id'=>7,'native_origin'=>'secret'],['id'=>4,'fields'=>['description'=>'No title']]] as $i=>$input){
            try{$cursors->create('alice',$i===0?'forms_delete_question':'forms_update',$input,'b1:42:'.str_repeat('a',64),'files',0);self::fail('Unrelated private input admitted.');}catch(ToolFailure){self::assertSame(0,$this->decryptions);}
        }
    }
    public function testOversizedReadLocatorIsRejectedByPublicSchema():void {
        $this->expectException(ArgumentValidationException::class);ArgumentValidator::validate(FormsCommands::definitions()['forms_read_operation_impact']['inputSchema'],['cursor'=>str_repeat('a',524289)]);
    }
}
