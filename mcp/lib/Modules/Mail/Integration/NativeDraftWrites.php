<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
/** Closed local-write scope: acquires native coordination before staging; no client is passed to MCP. */
class NativeDraftWrites {
    public function __construct(private MailServices $mail,private NativeDraftLinks $links){}
    public function run(string $uid,\Closure $effect):array {
        if(!$this->links->available())return $effect();
        $entered=false;
        try{
            $guard=$this->mail->draftsService($uid)->draftCoordinator();
            return $guard->runOwner($uid,static function(object $scope)use($effect,&$entered):array {$entered=true;return $effect();});
        }catch(\Throwable $error){
            if(!$entered&&$this->links->available()&&get_class($error)==='OCA\\Mail\\Exception\\DraftAdmissionFailure')throw new BeforeEffectFailure('Native draft work is busy or changed before write admission.');
            throw $error;
        }
    }
    public function cleanup(string $uid,int $id,object $native):void {
        if(!$this->links->available())throw new BeforeEffectFailure('Automatic attachment cleanup requires the qualified atomic native boundary.');
        $native->deleteUnassociatedAttachment($uid,$id);
    }
}
