<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
use OCA\Mail\Account;
use OCA\Mail\Db\TagProfile;
use OCA\Mail\Drafts\{DraftCoordinator,DraftScope};
use OCA\Mail\Exception\DraftAdmissionFailure;
/** Keep the native sync transactions inside the existing physical owner lease. */
trait OwnedMailboxSync {
    private function mcpMailboxSync(Account $account,\Closure $sync):void {
        $guard=DraftCoordinator::forDatabase($this->dbConnection);
        // Even an existing owner scope cannot admit an unrelated caller's transaction.
        $guard->assertNoTransaction();
        $uid=$account->getUserId();$id=$account->getId();
        $revision=$account->getMailAccount()->getMcpAuthRevision();
        $profile=TagProfile::account($account->getMailAccount());
        $guard->runOwner($uid,function(DraftScope $scope)use($account,$uid,$id,$revision,$profile,$sync):void {
            $current=function(bool $newClient=false)use($scope,$account,$uid,$id,&$revision,$profile):void {
                $scope->assertOwned();
                if($account->getUserId()!==$uid||$account->getId()!==$id)throw new DraftAdmissionFailure('Mailbox sync account ownership changed.');
                $fresh=$this->mailAccountMapper->find($uid,$id);
                if($fresh->getMcpAuthRevision()!==$account->getMailAccount()->getMcpAuthRevision()||(!$newClient&&$fresh->getMcpAuthRevision()!==$revision)||!hash_equals($profile,TagProfile::account($fresh))||!hash_equals($profile,TagProfile::account($account->getMailAccount())))throw new DraftAdmissionFailure('Mailbox sync account changed; reload its current connection.');
                // Native OAuth refresh can persist this Account during client creation, before IMAP reads.
                // Only that fixed call site may finalize the revision; owner, profile and lease never change.
                if($newClient){DraftCoordinator::forDatabase($this->dbConnection)->assertNoTransaction();$revision=$fresh->getMcpAuthRevision();}
            };
            $current();$sync($scope,$current);$current();
        });
    }
    private function mcpMailboxAtomic(DraftScope $scope,\Closure $current,\Closure $work):mixed {
        DraftCoordinator::forDatabase($this->dbConnection)->assertNoTransaction();
        $current();
        // TTransactional retains native rollback and exception behavior. Its callback ends before COMMIT.
        return $this->atomic(function()use($scope,$current,$work):mixed {
            $current();$scope->markWrite();$result=$work();$current();$scope->assertOwned();return $result;
        },$this->dbConnection);
    }
}
