<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Content;
use OCA\Mcp\Modules\Mail\Integration\MailContentFeatures;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\OperationJournal;
final class MailDraftImport {
    private const TOOL='mail_import_draft';
    public function __construct(private MailContentFeatures $native,private PlanState $states,private OperationJournal $journal){}
    public function preview(array $a,string $uid):array {$shown=$this->native->draftPreview($uid,(int)$a['message_id'],(int)$a['target_account_id'],isset($a['target_alias_id'])?(int)$a['target_alias_id']:null);return $shown+[PlanState::ARGUMENT=>$this->states->of(self::TOOL,$uid,$shown)];}
    public function copy(array $a,string $uid):array {
        PlanState::require($a);$key=$this->states->of('native_draft_copy',$uid,[$this->journal->scopeKey($uid),$this->journal->activeId($uid)]);
        $old=$this->native->copyOutcome($uid,$key);if($old!==[])return $old;
        $plan=$this->preview($a,$uid);if(!PlanState::matches($plan[PlanState::ARGUMENT],$a))throw new PlanChanged($plan);unset($plan[PlanState::ARGUMENT]);
        return $this->native->copy($uid,$plan,$key);
    }
}
