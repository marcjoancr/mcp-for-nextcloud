<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;
use OCA\Mcp\Modules\Mail\Composition\ReplyComposer;
use OCA\Mcp\Modules\Mail\Integration\{MailServices,MailGateway};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class MailDraftCompositionTest extends TestCase {
    public function testEditingBodyPreservesAttachmentAndReplyIdentity(): void {
        $draft=new CompositionDraft(['id'=>77,'accountId'=>4,'subject'=>'Before','bodyPlain'=>'Old','bodyHtml'=>'','html'=>false,
            'updatedAt'=>1,'inReplyToMessageId'=>'<parent@example.test>','aliasId'=>3,'failed'=>true,
            'attachments'=>[new CompositionAttachment(9)],'recipients'=>[]]);
        [$ops,$service]=$this->ops($draft);
        $args=['account_id'=>4,'draft_id'=>77,'subject'=>'After','body_plain'=>'Updated'];
        $p=$ops->previewWrite($args,'alice');
        $result=$ops->write($args+['plan_state'=>$p['plan_state']],'alice');
        self::assertSame([['type'=>'local','id'=>9]],$service->lastAttachments);
        self::assertSame('<parent@example.test>',$draft->getInReplyToMessageId());
        self::assertSame(3,$draft->getAliasId());
        self::assertSame('Updated',$result['draft']['body_plain']);
    }
    public function testReplyDraftKeepsParentAndIsHeldAgainstLossyNativeFlush(): void {
        $draft=new CompositionDraft(['id'=>77,'accountId'=>4,'subject'=>'','bodyPlain'=>'','bodyHtml'=>'','html'=>false,
            'updatedAt'=>1,'attachments'=>[],'recipients'=>[]]);
        [$ops]=$this->ops($draft);
        $args=['account_id'=>4,'subject'=>'Re: Request','body_plain'=>'Answer','reply_to_message_id'=>10,'reply_mode'=>'reply_all'];
        $p=$ops->previewWrite($args,'alice');
        $result=$ops->write($args+['plan_state'=>$p['plan_state']],'alice');
        self::assertSame('<parent@example.test>',$draft->getInReplyToMessageId());
        self::assertTrue($draft->isFailed());
        self::assertTrue($result['draft']['sync_held']);
        self::assertSame('<parent@example.test>',$result['draft']['in_reply_to']);
    }
    public function testFailedFilesStagingLeavesOriginalDraftAndAttachmentsUntouched(): void {
        $draft=new CompositionDraft(['id'=>77,'accountId'=>4,'subject'=>'Before','bodyPlain'=>'Original body','bodyHtml'=>'','html'=>false,
            'updatedAt'=>1,'attachments'=>[new CompositionAttachment(9)],'recipients'=>[]]);
        $files=$this->createMock(\OCA\Mcp\Modules\Mail\Files\MailFilesSource::class);
        $files->method('preview')->willReturn([['path'=>'/new.txt','file_id'=>50,'etag'=>'v1','name'=>'new.txt','mime_type'=>'text/plain','size'=>1,'sha256'=>hash('sha256','x')]]);
        $files->method('stage')->willThrowException(new \RuntimeException('Second attachment import failed'));
        [$ops,$service]=$this->ops($draft,$files);
        $args=['account_id'=>4,'draft_id'=>77,'subject'=>'After','body_plain'=>'Changed','add_files'=>['/new.txt']];
        $p=$ops->previewWrite($args,'alice');
        try {$ops->write($args+['plan_state'=>$p['plan_state']],'alice');self::fail('Staging should fail.');}
        catch(\RuntimeException) {
            self::assertSame('Before',$draft->getSubject());
            self::assertSame('Original body',$draft->getBodyPlain());
            self::assertSame(9,$draft->getAttachments()[0]->getId());
            self::assertSame([],$service->lastAttachments,'Native update must not run after incomplete staging.');
        }
    }
    public function testAttachmentToolExecutionWithoutConfirmationOnlyPreviews(): void {
        $draft=new CompositionDraft(['id'=>77,'accountId'=>4,'subject'=>'Before','bodyPlain'=>'Original body','bodyHtml'=>'','html'=>false,
            'updatedAt'=>1,'attachments'=>[new CompositionAttachment(9)],'recipients'=>[]]);
        [$ops,$service]=$this->ops($draft);
        $result=(new \OCA\Mcp\Modules\Mail\Tools\AttachFiles($ops))->execute(['account_id'=>4,'draft_id'=>77],new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertSame([],$service->lastAttachments);
        self::assertArrayHasKey('after',json_decode($result['content'][0]['text'],true));
    }
    public function testRepairPreviewCountsAlreadyAssociatedSnapshotsOnlyOnce(): void {
        $attachments=[];$manifest=[];$staged=[];$paths=[];
        for($id=1;$id<=10;$id++){
            $attachments[]=new CompositionAttachment($id);$paths[]='/file'.$id;
            $manifest[]=['path'=>'/file'.$id,'file_id'=>50+$id,'etag'=>'v1','name'=>'budget.pdf','mime_type'=>'application/pdf','size'=>5,'sha256'=>hash('sha256','bytes')];
            $staged[]=['attachment_id'=>$id,'file_id'=>50+$id,'sha256'=>hash('sha256','bytes')];
        }
        $draft=new CompositionDraft(['id'=>77,'accountId'=>4,'subject'=>'Before','bodyPlain'=>'Original','bodyHtml'=>'','html'=>false,
            'updatedAt'=>1,'attachments'=>$attachments,'recipients'=>[]]);
        $store=$this->createMock(\OCA\Mcp\Mcp\Operations\OperationStore::class);
        $store->method('get')->willReturn(['state'=>'partial','tool_name'=>'mail_write_draft','resource_refs'=>['draft_id'=>77,'staged'=>$staged]]);
        $journal=new \OCA\Mcp\Mcp\Operations\OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),new \OCA\Mcp\OAuth\ConnectionContext(),$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class));
        $files=$this->createMock(\OCA\Mcp\Modules\Mail\Files\MailFilesSource::class);$files->method('preview')->willReturn($manifest);
        [$ops]=$this->ops($draft,$files,$journal);
        $plan=$ops->previewWrite(['account_id'=>4,'draft_id'=>77,'subject'=>'Repaired','add_files'=>$paths,'repair_of_operation_id'=>'partial'],'alice');
        self::assertCount(10,$plan['after']['attachments']);
    }
    public function testForwardCreatesANewThreadWithReviewedBodyAndNoInheritedRecipients():void {
        $draft=new CompositionDraft(['id'=>77,'accountId'=>4,'subject'=>'','bodyPlain'=>'','bodyHtml'=>'','html'=>false,'attachments'=>[],'recipients'=>[]]);
        $forward=$this->createMock(\OCA\Mcp\Modules\Mail\Composition\ForwardComposer::class);$forward->method('prepare')->willReturn(['source'=>['message_id'=>7],'body_plain'=>'Reviewed forwarded quote','attachments'=>[]]);$forward->method('stage')->willReturn([]);
        [$ops]=$this->ops($draft,null,null,$forward);$a=['account_id'=>4,'subject'=>'Fwd: Source','forward_message_id'=>7];$p=$ops->previewWrite($a,'alice');self::assertSame('Reviewed forwarded quote',$p['after']['body_plain']);self::assertSame([],$p['after']['to']);self::assertSame([],$p['after']['bcc']);$r=$ops->write($a+['plan_state'=>$p['plan_state']],'alice');self::assertNull($r['draft']['in_reply_to']);
        $this->expectException(\InvalidArgumentException::class);$ops->previewWrite($a+['draft_id'=>77],'alice');
    }
    private function ops(CompositionDraft $draft, ?\OCA\Mcp\Modules\Mail\Files\MailFilesSource $files=null, ?\OCA\Mcp\Mcp\Operations\OperationJournal $journal=null, ?\OCA\Mcp\Modules\Mail\Composition\ForwardComposer $forward=null): array {
        $service=new class($draft) {
            public array $lastAttachments=[];
            public function __construct(private CompositionDraft $draft){}
            public function getMessage(int $id,string $uid): object{return $this->draft;}
            public function saveMessage(object $a,object $d,array $to,array $cc,array $bcc,array $attachments): object {$this->lastAttachments=$attachments;return $d;}
            public function updateMessage(object $a,object $d,array $to,array $cc,array $bcc,array $attachments): object {$this->lastAttachments=$attachments;return $d;}
        };
        $services=$this->createMock(MailServices::class);
        $services->method('draftsService')->willReturn($service);
        $services->method('newLocalDraft')->willReturn($draft);
        $services->method('localDraftType')->willReturn(1);
        $services->method('attachmentService')->willReturn(new class {
            public function getAttachment(string $uid,int $id): array{return [new CompositionAttachment($id),new class{
                public function getContent(): string{return 'bytes';}public function getSize(): int{return 5;}
            }];}
        });
        $accounts=new MailGateway((new FakeMailApp())->withAccount(4,'alice','Alice','alice@example.test'));
        $services->method('aliasesService')->willReturn(new class {public function findAll(int $id,string $uid): array{return [new class {
            public function getId(): int{return 3;}public function getAccountId(): int{return 4;}
            public function getAlias(): string{return 'alias@example.test';}public function getName(): string{return 'Alias';}
        }];}});
        $locator=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailMessageLocator::class);
        $locator->method('locate')->willReturn(['account'=>$accounts->ownedAccount('alice',4),'message'=>new class {public function getMessageId(): string{return '<parent@example.test>';}}]);
        $reader=$this->createMock(\OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler::class);
        $reader->method('readRow')->willReturn(['from'=>['count'=>1,'addresses'=>[['email'=>'person@example.test','name'=>'Person']]],
            'replyTo'=>['count'=>0,'addresses'=>[]],'to'=>['count'=>1,'addresses'=>[['email'=>'alice@example.test','name'=>'Alice']]],'cc'=>['count'=>0,'addresses'=>[]]]);
        $tools=$this->createMock(\OCA\Mcp\Mcp\Tools\ToolRegistry::class);$tools->method('allowsTool')->willReturn(true);
        $composer=new ReplyComposer($locator,static fn(string $uid)=>$reader,$services,$tools->allowsTool(...));
        $ops=new MailDraftOperations($services,$accounts,new PlanState((new InMemoryConfig())->mock($this)),$composer,$files,$journal,$forward);
        return [$ops,$service];
    }
}
final class CompositionDraft {
    public function __construct(private array $values){}
    public function __call(string $method,array $args): mixed {
        $key=lcfirst(preg_replace('/^(get|set|is)/','',$method));
        if(str_starts_with($method,'set')){$this->values[$key]=$args[0];return null;}
        return $this->values[$key]??null;
    }
}
final class CompositionAttachment {
    public function __construct(private int $id){}
    public function getId(): int{return $this->id;}
    public function getFileName(): string{return 'budget.pdf';}
    public function getMimeType(): string{return 'application/pdf';}
}
