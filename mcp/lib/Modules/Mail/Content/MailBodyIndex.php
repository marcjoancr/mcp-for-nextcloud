<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Content;
use OCA\Mcp\Modules\Mail\Integration\MailContentFeatures;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
final class MailBodyIndex {
    public function __construct(private MailContentFeatures $native,private PlanState $states){}
    public function preview(array $a,string $uid):array {$shown=$this->native->indexPreview($uid,$a['message_ids']);return $shown+['action'=>'index_selected_messages',PlanState::ARGUMENT=>$this->states->of('mail_index_messages',$uid,$shown)];}
    public function index(array $a,string $uid):array {PlanState::require($a);$plan=$this->preview($a,$uid);if(!PlanState::matches($plan[PlanState::ARGUMENT],$a))throw new PlanChanged($plan);unset($plan[PlanState::ARGUMENT],$plan['action']);return $this->native->index($uid,$plan);}
}
