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
        ['id'=>1,'type'=>'short'],['id'=>2,'type'=>'ranking'],['id'=>3,'type'=>'grid','extraSettings'=>['questionType'=>'radio']],
        ['id'=>4,'type'=>'grid','extraSettings'=>['questionType'=>'number']],['id'=>5,'type'=>'file'],
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
    public function testUnknownQuestionDoesNotGetInvented():void {
        $this->expectException(\InvalidArgumentException::class);
        FormsAnswers::native([['question_id'=>99,'values'=>['a']]],$this->questions);
    }
}
