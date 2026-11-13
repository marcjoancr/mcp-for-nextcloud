<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Five-minute single-use credentials preserve original actor, connection and current native authority. */
final class FormsDownloadAdmission {
    /** @param \Closure(string,string):array $job @param \Closure(string,string):string $content
     * @param \Closure(array):void $native @param \Closure():int $time @param \Closure():?string $session @param \Closure(string):string $url */
    public function __construct(private FormsDownloadStore $store,private \Closure $job,private \Closure $content,
        private \Closure $native,private \Closure $time,private \Closure $session,private \Closure $url) {}
    public function issue(string $uid,string $jobId):array {
        $job=($this->job)($uid,$jobId);
        if($job['actor_uid']!==$uid||!in_array($job['state'],['ready','issuing'],true))throw new ToolFailure('The complete Forms download is unavailable.');
        ($this->native)($job);$now=($this->time)();$expires=min($now+300,(int)($job['payload']['cache_expires_at']??($now+300)));
        if($expires<=$now)throw new ToolFailure('The temporary Forms result expired. Approve a new snapshot.');
        $token=bin2hex(random_bytes(32));
        if(!$this->store->create(['token_hash'=>hash('sha256',$token),'job_id'=>$jobId,'actor_uid'=>$uid,'expires_at'=>$expires,'used'=>false]))throw new ToolFailure('The Forms download could not be admitted.');
        return ['download_url'=>($this->url)($token),'expires_at'=>$expires,'single_use'=>true];
    }
    public function redeem(string $token):array {
        if(preg_match('/^[a-f0-9]{64}$/D',$token)!==1)throw new ToolFailure(ToolFailure::NOT_FOUND);
        $row=$this->store->get(hash('sha256',$token));$session=($this->session)();
        if($row===null||$row['used']||$row['expires_at']<=($this->time)()||($session!==null&&$session!==$row['actor_uid']))throw new ToolFailure(ToolFailure::NOT_FOUND);
        $job=($this->job)($row['actor_uid'],$row['job_id']);($this->native)($job);
        $content=($this->content)($row['actor_uid'],$row['job_id']);
        if(!$this->store->consume($row,($this->time)()))throw new ToolFailure(ToolFailure::NOT_FOUND);
        ($this->native)($job);
        $format=$job['payload']['arguments']['format']??'csv';
        return ['content'=>$content,'name'=>'forms-results.'.$format,'mime'=>match($format){'ods'=>'application/vnd.oasis.opendocument.spreadsheet','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',default=>'text/csv; charset=utf-8'}];
    }
}
