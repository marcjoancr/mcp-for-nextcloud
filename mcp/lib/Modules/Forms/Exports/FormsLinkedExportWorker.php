<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCP\IUserSession;
/** One bounded step; the step service is lazy so native claim initializes the first original Files root. */
final class FormsLinkedExportWorker {
    public function __construct(private IUserSession $session,private \Closure $step,private string $sapi=PHP_SAPI) {}
    public function run(array $input):array {
        if($this->sapi!=='cli'||$this->session->getUser()!==null||array_keys($input)!==['association_id']
            ||!is_string($input['association_id'])||preg_match('/^[a-f0-9]{64}$/D',$input['association_id'])!==1)
            return ['status'=>'refused'];
        try {
            $result=($this->step)($input['association_id']);$state=$result['state']??null;
            if($this->session->getUser()!==null)return ['status'=>'unknown'];
            if(in_array($state,['idle','pending','collecting','assembling','ready','delivered'],true))return ['status'=>'completed'];
            if(in_array($state,['paused','refused'],true))return ['status'=>'refused'];
        } catch(\Throwable) {}
        return ['status'=>'unknown'];
    }
}
