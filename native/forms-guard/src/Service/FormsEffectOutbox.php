<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{IDBConnection,Server};
use OCA\Forms\Db\{FormMapper,ShareMapper,SubmissionMapper};
use OCA\Forms\Service\FormsService;
/** Durable one-shot claims; MCP dispatch and actual mail send recheck signed origin and immutable native data. */
final class FormsEffectOutbox {
    private bool $delivering=false;
    private ?string $operation=null;
    private array $pending=[];
    private static ?array $dispatch=null;
    public function __construct(private IDBConnection $db,private FormsRevisionFence $fence) {}
    public function operation(?string $key): void {$this->operation=$key;}
    public static function capture(string $kind,int $formId,int $entityId): bool {
        $self=Server::get(self::class);
        if ($self->delivering || !$self->fence->active()) {return false;}
        $key=hash('sha256',($self->operation??bin2hex(random_bytes(32))).':'.$kind.':'.$entityId);$ref='legacy';
        if ($self->operation!==null) {
            $context=OriginContext::current();$ref=$key;
            EffectEnvelope::put($key,['origin'=>$context['origin'],'action'=>$context['action'],'input'=>$context['input'],
                'kind'=>$kind,'form_id'=>$formId,'entity_id'=>$entityId,'owner_id'=>Server::get(FormMapper::class)->findById($formId)->getOwnerId(),
                'digest'=>EffectEnvelope::digest($formId,$kind,$entityId,new ScopedServices($context['verified']['uid'],true)),'operation_key'=>$self->operation]);
        }
        $self->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_effects (effect_key,operation_key,kind,form_id,entity_id,phase,payload_ref) VALUES (?,?,?,?,?,?,?)',
            [$key,$self->operation??'',$kind,$formId,$entityId,'pending',$ref]);$self->pending[]=$key;return true;
    }
    public function phases(string $operation): array {
        return $this->db->executeQuery('SELECT kind,phase FROM *PREFIX*forms_mcp_effects WHERE operation_key = ? ORDER BY effect_key',[$operation])->fetchAll();
    }
    private function claim(string $key): ?array {
        $row=$this->db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_effects WHERE effect_key = ? AND phase = ?',[$key,'pending'])->fetch();
        if (!$row) {return null;}
        // This statement commits BEFORE invoking anything external. A thrown/lost acknowledgement never permits replay.
        if ($this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ? AND phase = ?',['uncertain',$key,'pending'])!==1) {return null;}
        return $row;
    }
    private function checked(array $envelope,callable $work): mixed {
        $uid=$envelope['origin']['uid']??'';
        return OriginContext::within($uid,$envelope['origin'],'effect',$envelope['input'],function()use($uid,$envelope,$work) {
            if (!Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['qualified']) {throw new \RuntimeException('Forms runtime changed');}
            $scope=new ScopedServices($uid,true);$forms=$scope->forms();$form=Server::get(FormMapper::class)->findById((int)$envelope['form_id']);
            if ($form->getOwnerId()!==$envelope['owner_id']) {throw new \RuntimeException('Forms ownership changed');}
            $action=$envelope['action'];
            if ($envelope['kind']==='share') {$forms->getFormIfAllowed($form->getId(),'all');}
            elseif ($action==='forms_submit_response') {$forms->loadFormForSubmission($form->getId(),$envelope['input']['share_hash']??'');}
            elseif ($action==='forms_update_own_response') {
                $submission=Server::get(SubmissionMapper::class)->findById((int)$envelope['entity_id']);
                if ($submission->getUserId()!==$uid || !$forms->canDeleteResults($form)) {throw new \RuntimeException('Original response authority was revoked');}
            } elseif ($action==='forms_update_response') {
                if (!$forms->canDeleteResults($form) || !in_array('results_delete',$forms->getPermissions($form),true)) {throw new \RuntimeException('Original response authority was revoked');}
            } else {throw new \RuntimeException('Unknown native effect action');}
            if (!hash_equals($envelope['digest'],EffectEnvelope::digest($form->getId(),$envelope['kind'],(int)$envelope['entity_id'],$scope))) {throw new \RuntimeException('Native event dependencies changed');}
            $old=self::$dispatch;self::$dispatch=$envelope;
            try {return $work($scope,$form);} finally {self::$dispatch=$old;}
        });
    }
    public function drain(): void {
        if ($this->delivering) {return;}$this->delivering=true;
        try {
            $keys=$this->pending;$this->pending=[];
            foreach ($keys as $key) {
                $row=$this->claim($key);if(!$row){continue;}
                try {
                    $deliver=function($scope,$form)use($row):void {
                        $service=$scope===null?Server::get(FormsService::class):$scope->forms();
                        if($row['kind']==='submission'){$service->notifyNewSubmission($form,Server::get(SubmissionMapper::class)->findById((int)$row['entity_id']));}
                        elseif($row['kind']==='share'){$service->notifyNewShares($form,Server::get(ShareMapper::class)->findById((int)$row['entity_id']));}
                        else{throw new \RuntimeException('Unknown effect kind');}
                    };
                    if($row['payload_ref']!=='legacy') {
                        $envelope=EffectEnvelope::get($row['payload_ref']);
                        $this->fence->transaction(fn()=> $this->checked($envelope,$deliver));
                    } else {$deliver(null,Server::get(FormMapper::class)->findById((int)$row['form_id']));}
                    $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ?',['attempted_unverified',$key]);
                } catch (\Throwable) { /* Origin/data refusal remains uncertain; never dispatch this claimed effect again. */ }
            }
        } finally {$this->delivering=false;}
    }
    public static function queueMail(array $mail): bool {
        $context=self::$dispatch;if($context===null){return false;}
        $key=hash('sha256',$context['operation_key'].':confirmation-mail:'.$context['entity_id']);$self=Server::get(self::class);
        EffectEnvelope::put($key,$context+['mail'=>$mail]);
        $self->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_effects (effect_key,operation_key,kind,form_id,entity_id,phase,payload_ref) VALUES (?,?,?,?,?,?,?)',
            [$key,$context['operation_key'],'confirmation_mail',$context['form_id'],$context['entity_id'],'pending',$key]);
        Server::get(\OCP\BackgroundJob\IJobList::class)->add(\OCA\Forms\BackgroundJob\SendConfirmationMailJob::class,['_mcp_mail'=>$key]);
        return true;
    }
    public static function sendMail(string $key,callable $send): void {
        $self=Server::get(self::class);$row=$self->claim($key);if(!$row){return;}
        if($row['kind']!=='confirmation_mail' || $row['payload_ref']!==$key){return;}
        try {
            $envelope=EffectEnvelope::get($key);
            $self->fence->transaction(fn()=> $self->checked($envelope,static function()use($send,$envelope):void {$send($envelope['mail']);}));
            $self->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ?',['attempted_unverified',$key]);
        } catch(\Throwable) { /* Revocation, changed content and ambiguous sends are not retries. */ }
    }
}
