<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Responses\FormsAnswers;
use PHPUnit\Framework\TestCase;
final class FormsAnswersTest extends TestCase {
    private array $questions=[
        ['id'=>1,'type'=>'short'],['id'=>2,'type'=>'ranking','options'=>[['id'=>8],['id'=>7]]],['id'=>3,'type'=>'grid','extraSettings'=>['questionType'=>'radio'],'options'=>[['id'=>11,'optionType'=>'row'],['id'=>20,'optionType'=>'column']]],
        ['id'=>4,'type'=>'grid','extraSettings'=>['questionType'=>'number'],'options'=>[['id'=>12,'optionType'=>'row'],['id'=>21,'optionType'=>'column']]],['id'=>5,'type'=>'file'],
    ];
    public function testTypedAnswersBecomeNativeValuesWithoutGuessingQuestionIds():void {
        self::assertSame([1=>['hello'],2=>['8','7'],3=>[11=>'20'],4=>[12=>[21=>'3.5']]],
            FormsAnswers::native([['question_id'=>1,'values'=>['hello']],['question_id'=>2,'option_ids'=>[8,7]],
                ['question_id'=>3,'rows'=>[['row_id'=>11,'column_ids'=>[20]]]],
                ['question_id'=>4,'rows'=>[['row_id'=>12,'cells'=>[['column_id'=>21,'value'=>'3.5']]]]]],$this->questions));
    }
    public function testFileTokensAreResolvedThroughBoundActorHandle():void {
        $seen=[];
        $result=FormsAnswers::native([['question_id'=>5,'file_handles'=>['opaque']]],$this->questions,
            static function(int $question,string $handle)use(&$seen):array{$seen=[$question,$handle];return ['uploadedFileId'=>99,'uploadToken'=>'server-private'];});
        self::assertSame([5,'opaque'],$seen);
        self::assertSame([5=>[['uploadedFileId'=>99,'uploadToken'=>'server-private']]],$result);
    }
    public function testDuplicateQuestionIdsAreRefusedBeforeNativeValidation():void {
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>1,'values'=>['a']],['question_id'=>1,'values'=>['b']]],$this->questions);
    }
    public function testConflictingAnswerModesAreRefused():void {
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>1,'values'=>['a'],'option_ids'=>[3]]],$this->questions);
    }
    public function testEmptyScalarMustBeOmittedOrUseOneExplicitBlankValue():void {
        self::assertSame([1=>['']],FormsAnswers::native([['question_id'=>1,'values'=>['']]],$this->questions));
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>1,'values'=>[]]],$this->questions);
    }
    public function testScalarCannotSilentlyStoreMultipleAnswers():void {
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>1,'values'=>['first','unvalidated second']]],$this->questions);
    }
    public function testAllowOtherDoesNotMakeForeignOptionIdsValid():void {
        $q=[['id'=>6,'type'=>'multiple','extraSettings'=>['allowOtherAnswer'=>true],'options'=>[['id'=>60]]]];
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>6,'option_ids'=>[999]]],$q);
    }
    public function testGridForeignRowAndColumnReferencesAreRefused():void {
        foreach([['row_id'=>999,'column_ids'=>[20]],['row_id'=>11,'column_ids'=>[999]]] as $row) {
            try {FormsAnswers::native([['question_id'=>3,'rows'=>[$row]]],$this->questions);self::fail('Foreign grid reference admitted.');}
            catch(\InvalidArgumentException) {self::assertTrue(true);}
        }
    }
    public function testNativeDateAndTimeRangesKeepBothEndpoints():void {
        $q=[['id'=>6,'type'=>'date','extraSettings'=>['dateRange'=>true]],['id'=>7,'type'=>'time','extraSettings'=>['timeRange'=>true]]];
        self::assertSame([6=>['2026-11-13','2026-11-14'],7=>['09:30','16:45']],FormsAnswers::native([
            ['question_id'=>6,'values'=>['2026-11-13','2026-11-14']],['question_id'=>7,'values'=>['09:30','16:45']]],$q));
    }
    public function testNativeValid129DigitGridValueFitsTypedSchema():void {
        $value=str_repeat('1',129);$args=['id'=>4,'answers'=>[['question_id'=>4,'rows'=>[['row_id'=>12,'cells'=>[['column_id'=>21,'value'=>$value]]]]]]];
        $schema=\OCA\Mcp\Modules\Forms\Execution\FormsCommands::definitions()['forms_submit_response']['inputSchema'];
        $validated=\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator::validate($schema,$args);
        self::assertSame($value,FormsAnswers::native($validated['answers'],$this->questions)[4][12][21]);
    }
    public function testUnknownQuestionDoesNotGetInvented():void {
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>99,'values'=>['a']]],$this->questions);
    }
}
