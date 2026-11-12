<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Modules\Mail\Mutations\NativeMutationRunner;
use OCA\Mcp\Db\MailProbeSlotsMapper;
use OCP\AppFramework\Utility\ITimeFactory;
/** Closed actions only. Credentials, raw clients and generic mutation callbacks never cross this facade. */
class MailMutationSession {
    public function __construct(private MailMutationTarget $target,private OperationJournal $journal,private NativeMutationRunner $runner,private MailProbeSlotsMapper $slots,private ITimeFactory $clock){}
    public function refresh(string $uid,array $request):array {return $this->admit($uid,$request);}
    public function tag(string $uid,array $request):array {return $this->admit($uid,$request);}
    public function setFlag(string $uid,array $request):array {return $this->admit($uid,$request);}
    private function admit(string $uid,array $request):array {
        if($this->target->snapshot($uid,$request)['request']!==$request)throw new BeforeEffectFailure('The approved native target changed. Preview again.');
        $id=$this->journal->activeId($uid);
        if(!$this->slots->reserve(hash('sha256',"mutation\0".$uid."\0".$request['account_id']),$id,$this->clock->getTime()))throw new BeforeEffectFailure('Mail mutation rate limit reached.');
        return $this->runner->run($uid,$this->journal->delegateNative($uid,$request),$request);
    }
    public function mailbox(string $uid,array $request):array {return $this->admit($uid,$request);}
    public function move(string $uid,array $request):array {return $this->admit($uid,$request);}
}
