<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mailboxes;
use OCA\Mcp\Modules\Mail\Integration\{MailSettingsGateway,MailServices};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
class MailboxRoles {
    public function __construct(private MailSettingsGateway $settings,private MailServices $native,private PlanState $states){}
    public function preview(array $args,string $uid):array {
        $changes=$args['roles']??null;
        if(!is_array($changes)||$changes===[]||array_diff(array_keys($changes),array_keys(MailSettingsGateway::MAILBOX_ROLES))!==[])throw new \InvalidArgumentException('Choose supported special-folder roles.');
        foreach($changes as $value)if(!is_int($value)||$value<1)throw new \InvalidArgumentException('Choose positive folder IDs; clearing a role is not supported.');
        $id=(int)$args['account_id'];$a=$this->settings->account($uid,$id);$before=$this->settings->mailboxRoles($uid,$id);
        $boxes=$this->native->mailboxMapper($uid)->findAll($a);$selected=[];
        foreach($changes as $key=>$value){
            $found=null;foreach($boxes as $b)if((int)$b->getId()===$value&&(int)$b->getAccountId()===$id&&$b->getSelectable()===true&&$b->isShared()===false){$found=$b;break;}
            if($found===null)throw new BeforeEffectFailure('Special folder unavailable in this owned account.');
            $selected[$key]=['id'=>$value,'name'=>$found->getName()];
        }
        $p=['action'=>'configure_mailboxes','account_id'=>$id,'before'=>$before,'after'=>array_replace($before,$changes),'folders'=>$selected];
        return $p+['plan_state'=>$this->states->of('mail_configure_mailboxes',$uid,$p+['profile'=>$this->settings->readConnection($uid,$id)['revision']])];
    }
    public function write(array $args,string $uid):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $actual=$this->settings->writeMailboxRoles($uid,(int)$args['account_id'],$args['roles']);
        if($actual!==$p['after'])throw new \RuntimeException('Special-folder readback changed. Inspect the operation.');
        return ['account_id'=>$p['account_id'],'roles'=>$actual];
    }
}
