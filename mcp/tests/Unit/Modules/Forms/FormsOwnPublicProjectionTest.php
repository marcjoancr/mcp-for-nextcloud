<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Modules\Forms\Integration\FormsPublication;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

final class FormsOwnPublicProjectionTest extends TestCase {
    public function testOwnAnswerContinuationProjectsAlreadyPagedNativeRowsExactlyOnce():void {
        $answers=[];for($i=1;$i<=61;$i++)$answers[]=['id'=>$i,'questionId'=>$i,'text'=>'Answer '.$i,'secret'=>'excluded'];
        $base=['id'=>9,'formId'=>3,'timestamp'=>1000,'userId'=>'alice','userDisplayName'=>'Alice'];
        $first=FormsProjection::read('forms_read_own_response',$base+['answers'=>array_slice($answers,0,50),'_mcp_page'=>['offset'=>0,'hasMore'=>true,'nextOffset'=>50]],['offset'=>0,'limit'=>50]);
        $second=FormsProjection::read('forms_read_own_response',$base+['answers'=>array_slice($answers,50),'_mcp_page'=>['offset'=>50,'hasMore'=>false,'nextOffset'=>null]],['offset'=>50,'limit'=>50]);
        self::assertSame(range(1,61),array_column([...$first['answers'],...$second['answers']],'id'));
        self::assertSame(50,$first['nextOffset']);self::assertFalse($second['hasMore']);
        self::assertArrayNotHasKey('userId',$first);self::assertArrayNotHasKey('secret',$second['answers'][0]);
    }
    public function testPublicStructureProjectsQuestionOptionsAndDropsPrivateFormFields():void {
        $form=['id'=>3,'title'=>'Public','ownerId'=>'secret owner','access'=>['permitAll'=>true],'shares'=>[['shareWith'=>'token']],'fileId'=>44,'confirmationEmailBody'=>'private'];
        $raw=['form'=>$form,'question'=>['id'=>6,'formId'=>3,'type'=>'multiple','text'=>'Pick','options'=>[['id'=>62,'text'=>'Next','secret'=>'hidden']]],'revision'=>'17','_mcp_page'=>['offset'=>50,'hasMore'=>false,'nextOffset'=>null]];
        $out=FormsProjection::read('forms_read_submission_form',$raw,['question_id'=>6,'offset'=>50,'limit'=>50]);
        self::assertSame(['id'=>3,'title'=>'Public'],$out['form']);
        self::assertSame([['id'=>62,'text'=>'Next']],$out['question']['options']);
        self::assertSame('17',$out['revision']);self::assertFalse($out['hasMore']);
        self::assertArrayNotHasKey('_mcp_page',$out);
    }
    public function testNativeOwnListAndInvisibleFilePagesKeepAccurateContinuation():void {
        $page=['offset'=>50,'hasMore'=>true,'nextOffset'=>100];
        $own=FormsProjection::read('forms_read_own_responses',['submissions'=>[],'_mcp_page'=>$page],['offset'=>50,'limit'=>50]);
        $files=FormsProjection::read('forms_list_response_files',['files'=>[],'_mcp_page'=>$page],['offset'=>50,'limit'=>50]);
        self::assertSame(100,$own['nextOffset']);self::assertSame(100,$files['nextOffset']);
    }
    public function testMismatchedPageCannotBePresentedAsTheRequestedContinuation():void {
        $this->expectException(ToolFailure::class);
        FormsProjection::read('forms_read_own_response',['id'=>9,'answers'=>[],'_mcp_page'=>['offset'=>0,'hasMore'=>false,'nextOffset'=>null]],['offset'=>50,'limit'=>50]);
    }
    public function testNewReadsNeedTheirSpecificNativeQualification():void {
        $native=['qualified'=>true,'protocol'=>1,'actions'=>['forms_read_own_response','forms_read_submission_form']];
        foreach(['forms_read_own_response'=>'own_response_pages','forms_read_submission_form'=>'public_submission_form'] as $tool=>$capability) {
            self::assertArrayHasKey($tool,FormsCommands::commands());
            self::assertFalse(FormsPublication::supported($tool,$native));
            self::assertTrue(FormsPublication::supported($tool,$native+[$capability=>true]));
        }
        self::assertSame('read_own_responses',FormsCommands::commands()['forms_read_own_response']['operation']);
        self::assertSame('read',FormsCommands::commands()['forms_read_submission_form']['operation']);
    }
}
