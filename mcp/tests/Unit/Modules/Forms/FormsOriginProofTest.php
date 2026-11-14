<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Access\FormsOriginProof;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsOriginProofTest extends TestCase {
    public function testOriginalActorConnectionAndOperationsAreSigned():void {
        $actors=new OperationActors();$proof=new FormsOriginProof(new PlanState((new InMemoryConfig())->mock($this)),$actors);
        $origin=$proof->create('alice',12,'forms_submit_response',['submit'],['form_id'=>4,'owner_id'=>'owner']);
        self::assertSame('alice',$proof->verify($origin)['uid']);
        $origin['connection']=99;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$proof->verify($origin);
    }
    public function testExistingCommentTargetIsSignedAndCannotBeSubstituted():void {
        $proof=new FormsOriginProof(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors());
        $origin=$proof->create('alice',12,'forms_update_comment',['edit_comments'],['form_id'=>4,'comment_id'=>'77']);
        self::assertSame('77',$proof->verify($origin)['references']['comment_id']);
        $origin['references']['comment_id']='78';
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $proof->verify($origin);
    }

    public function testOriginalBulkAuthorityCannotBeWeakenedInsideASignedContinuation():void {
        $proof=new FormsOriginProof(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors());
        $authority=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit','share']];
        $origin=$proof->create('alice',12,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$authority]);
        self::assertSame($authority,$proof->verify($origin)['references']['bulk_authority']);
        $origin['references']['bulk_authority']['files_operations']=[];
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $proof->verify($origin);
    }

    public function testRecreatedUidCannotAuthorizeDeferredEffects():void {
        $actors=new OperationActors();$proof=new FormsOriginProof(new PlanState((new InMemoryConfig())->mock($this)),$actors);
        $origin=$proof->create('alice',12,'forms_submit_response',['submit'],['form_id'=>4]);
        $actors->generation='two';
        $this->expectException(\OCA\Mcp\Mcp\Operations\ActorRetired::class);$proof->verify($origin);
    }
    public function testOriginCannotCarryAnswersOrBearerCapabilities():void {
        $proof=new FormsOriginProof(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors());
        $this->expectException(\InvalidArgumentException::class);
        $proof->create('alice',12,'forms_submit_response',['submit'],['form_id'=>4,'answers'=>['private']]);
    }
}
