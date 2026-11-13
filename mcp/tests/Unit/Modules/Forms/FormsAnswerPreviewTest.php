<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Integration\FormsAnswerPreview;
use OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect;
use PHPUnit\Framework\TestCase;
final class FormsAnswerPreviewTest extends TestCase {
    public function testNativeValidationReceivesConvertedAnswersOriginalAuthorityAndExactRevision():void {
        $guard=new class {
            public array $calls=[];
            public function preflightAnswers(string $uid,string $tool,array $input,string $revision):void {$this->calls[]=[$uid,$tool,$input,$revision];}
        };
        $input=['id'=>4,'answers'=>[['question_id'=>9,'file_handles'=>['opaque']]],'share_hash'=>'link'];
        $native=FormsAnswerPreview::validate($guard,'alice','forms_submit_response',$input,[['id'=>9,'type'=>'file']],['signed'=>'original'],'revision');
        self::assertSame([9=>[['mcpHandle'=>'opaque']]],$native);
        self::assertSame([['alice','forms_submit_response',['id'=>4,'answers'=>$native,'share_hash'=>'link','_mcp_origin'=>['signed'=>'original']],'revision']],$guard->calls);
        self::assertSame([['question_id'=>9,'file_handles'=>['opaque']]],$input['answers']);
    }
    public function testNativeSemanticFailureCannotProduceAnApproval():void {
        $guard=new class {
            public function preflightAnswers(string $uid,string $tool,array $input,string $revision):void {throw new \RuntimeException('private native validator detail');}
        };
        try {FormsAnswerPreview::validate($guard,'alice','forms_update_response',['id'=>4,'submission_id'=>3,'answers'=>[['question_id'=>1,'values'=>['impossible date']]]],[['id'=>1,'type'=>'date']],[],'revision');self::fail('Invalid native value admitted');}
        catch(FormsBeforeEffect $error) {self::assertStringNotContainsString('private native',$error->getMessage());}
    }
    public function testInvalidStructureNeverReachesNativeService():void {
        $guard=new class {
            public bool $called=false;
            public function preflightAnswers(string $uid,string $tool,array $input,string $revision):void {$this->called=true;}
        };
        try {FormsAnswerPreview::validate($guard,'alice','forms_submit_response',['id'=>4,'answers'=>[['question_id'=>999,'values'=>['a']]]],[['id'=>1,'type'=>'short']],[],'revision');self::fail('Unknown question admitted');}
        catch(FormsBeforeEffect) {self::assertFalse($guard->called);}
    }
}
