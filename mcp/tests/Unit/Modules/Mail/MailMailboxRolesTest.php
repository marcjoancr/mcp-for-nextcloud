<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Mailboxes\MailboxRoles;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../Support/Mail/MailSettingsFixture.php';
final class MailMailboxRolesTest extends TestCase {
    use MailSettingsFixture;
    public function testRolesPatchPreservesOmittedFieldsSecretsAndRejectsChangedTarget():void {
        [$g,$e,$service,,$states,$native]=$this->fixture();$e->setDraftsMailboxId(1);$e->setSentMailboxId(2);$e->setTrashMailboxId(3);$e->setArchiveMailboxId(4);
        $boxes=[new class {public function getId():int{return 7;}public function getAccountId():int{return 4;}public function getName():string{return 'Reviewed Archive';}public function getSelectable():bool{return true;}public function isShared():bool{return false;} }];
        $map=new class($boxes){public function __construct(public array $boxes){}public function findAll(object $account):array{return $this->boxes;}};$native->method('mailboxMapper')->willReturn($map);
        $roles=new MailboxRoles($g,$native,$states);$args=['account_id'=>4,'roles'=>['archive_mailbox_id'=>7]];$p=$roles->preview($args,'alice');self::assertSame(4,$p['before']['archive_mailbox_id']);self::assertSame(7,$p['after']['archive_mailbox_id']);self::assertSame(0,$service->writes);
        $roles->write($args+['plan_state'=>$p['plan_state']],'alice');self::assertSame(7,$e->getArchiveMailboxId());self::assertSame(1,$e->getDraftsMailboxId());self::assertSame(2,$e->getSentMailboxId());self::assertSame(3,$e->getTrashMailboxId());self::assertSame('SECRET-IMAP',$e->getInboundPassword());self::assertTrue($e->getSmimeSign());
        $p=$roles->preview($args,'alice');$map->boxes=[];$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$roles->write($args+['plan_state'=>$p['plan_state']],'alice');
    }
}
