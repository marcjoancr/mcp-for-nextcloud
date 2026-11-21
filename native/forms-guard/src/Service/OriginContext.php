<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Request-local verified provenance; no UID-only fallback exists for MCP artifacts. */
final class OriginContext {
    private static ?array $current=null;
    private static ?object $businessFrame=null;
    private static ?array $nextBulkFrame=null;
    private static ?array $frameBulkControl=null;
    private static bool $bulkControlActive=false;
    private static ?\Closure $fileBoundary=null;
    /** Bind persisted job input to this verified frame; unwrap only its identical signed transport. */
    public static function assertJobInput(array $job):void {
        $context=self::current();$origin=$context['origin'];$input=$job['input']??null;
        if(!is_array($origin)||($job['origin']??null)!==$origin||!is_array($input)
            ||($job['uid']??null)!==($context['verified']['uid']??null)
            ||($job['action']??null)!==($context['verified']['tool']??null))throw new \RuntimeException('Stored Forms job authority changed');
        if(array_key_exists('_mcp_origin',$input)){if($input['_mcp_origin']!==$origin)throw new \RuntimeException('Stored Forms transport authority changed');unset($input['_mcp_origin']);}
        if($input!==$context['input'])throw new \RuntimeException('Stored Forms job input changed');
    }
    /** One-shot child input carries signed originals; the child must verify them again. */
    public static function nativeHomeEnvelope(array $job,array $claim):array {
        self::assertJobInput($job);self::assertFresh();$context=self::current();$phase=$claim['home_phase']??null;
        $expected=['nonce','phase','uid','action','target_uid','step_key','version'];
        $phases=match($job['action']??null){'forms_transfer'=>['copy_files','stage_shares','stage_recipients','apply'],'forms_restore'=>['restore','restore_files'],default=>[]};
        if(self::$businessFrame===null||Server::get(FormsRevisionFence::class)->active()
            ||(($job['action']??null)==='forms_transfer'&&($job['sealed']??false)!==true)||!in_array($job['phase']??null,$phases,true)
            ||!is_string($job['id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$job['id'])
            ||!is_int($job['version']??null)||$job['version']<1||!is_array($phase)
            ||count($phase)!==count($expected)||array_diff($expected,array_keys($phase))!==[])
            throw new \RuntimeException('Native Home claim is unavailable');
        $target=match($job['action']){'forms_transfer'=>$context['input']['owner_id']??null,'forms_restore'=>$job['uid'],default=>null};
        if(!is_string($target)||$target===''||str_contains($target,'/')||str_contains($target,'\\')||str_contains($target,"\0")||in_array($target,['.','..'],true)
            ||!is_string($phase['nonce'])||!preg_match('/^[a-f0-9]{64}$/D',$phase['nonce'])
            ||!is_string($phase['step_key'])||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$phase['step_key'])
            ||$phase['phase']!==$job['phase']||$phase['uid']!==$job['uid']||$phase['action']!==$job['action']
            ||$phase['target_uid']!==$target||$phase['version']!==$job['version']
            ||!is_int($job['form']['id']??null)||$job['form']['id']<1)
            throw new \RuntimeException('Native Home claim binding changed');
        $backupId=null;$backupSeal=null;
        if($job['action']==='forms_restore'){
            $backupId=$context['input']['backup_id']??null;
            if(!is_string($backupId)||!preg_match('/^[a-f0-9]{64}$/D',$backupId))throw new \RuntimeException('Native Home restore backup is unavailable');
            $backup=Server::get(BulkStore::class)->load($backupId);
            if(($backup['id']??null)!==$backupId||($backup['sealed']??false)!==true||($backup['uid']??null)!==$job['uid']
                ||($backup['actor']??null)!==($job['actor']??null)||!is_string($backup['seal']??null)||!preg_match('/^[a-f0-9]{64}$/D',$backup['seal'])
                ||($backup['form']??null)!==$job['form']||($backup['counts']??null)!==($job['counts']??null)
                ||($backup['backup_format']??1)!==($job['backup_format']??1))throw new \RuntimeException('Native Home restore backup changed');
            $backupSeal=$backup['seal'];
        }
        $origin=$context['origin'];$control=self::$frameBulkControl;
        $verified=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertNativeHome($origin,$control,$target);
        foreach(['read','create']as$operation)if(!in_array($operation,$verified['references']['files_operations']??[],true))
            throw new \RuntimeException('Original native Home Files grant is unavailable');
        return ['protocol'=>1,'job_id'=>$job['id'],'form_id'=>$job['form']['id'],'uid'=>$job['uid'],'action'=>$job['action'],
            'target_uid'=>$target,'files_path'=>'/'.$target.'/files','home_phase'=>$phase,'origin'=>$origin,'control'=>$control,'backup_id'=>$backupId,'backup_seal'=>$backupSeal];
    }
    public static function withFileBoundary(?callable $callback,callable $work): mixed {
        $old=self::$fileBoundary;self::$fileBoundary=$callback===null?null:\Closure::fromCallable($callback);
        try{return $work();}finally{self::$fileBoundary=$old;}
    }
    public static function within(string $uid,array $origin,string $action,array $input,callable $work): mixed {
        try {$verified=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assert($origin);}
        catch(\Throwable $e){throw new \OCA\Forms\Mcp\BeforeEffect('Signed Forms authority is unavailable',0,$e);}
        if ($verified['uid']!==$uid || (isset($input['id'],$verified['references']['form_id']) && (int)$input['id']!==(int)$verified['references']['form_id'])) {throw new \OCA\Forms\Mcp\BeforeEffect('Original Forms authority does not match');}
        $fileReferences=$action==='forms_list_response_files'
            && in_array($verified['tool'],['forms_download_results','forms_export_results','forms_link_results_file'],true)
            && in_array('read_response_files',$verified['operations'],true)
            && in_array('read',$verified['references']['files_operations']??[],true);
        if ($action!==$verified['tool'] && !$fileReferences && !in_array($action,['forms_results_metadata','forms_results_page','lookup','effect'],true)) {throw new \OCA\Forms\Mcp\BeforeEffect('Original Forms action does not match');}
        $bulk=self::$nextBulkFrame;
        if($bulk!==null && $bulk['original']!==$origin){throw new \OCA\Forms\Mcp\BeforeEffect('Bulk business frame does not match');}
        $old=self::$current;$oldControl=self::$frameBulkControl;$oldFrame=self::$businessFrame;self::$businessFrame=new \stdClass();unset($input['_mcp_origin']);
        self::$current=['origin'=>$origin,'verified'=>$verified,'action'=>$action,'input'=>$input];
        self::$frameBulkControl=$bulk['control']??null;
        if($bulk!==null){self::$nextBulkFrame=null;}
        try {return $work();} finally {self::$current=$old;self::$frameBulkControl=$oldControl;self::$businessFrame=$oldFrame;}
    }
    /** Nonserializable request-local authority for one immediate authoring effect, not another origin frame. */
    public static function authoringStepAuthority(): array {
        $context=self::current();
        if(!AuthoringScale::metadata($context['action'])||($context['file_impact']['authoring_effects']??false)!==true||self::$businessFrame===null){throw new \LogicException('Authoring business frame is unavailable');}
        self::assertFresh();$frame=self::$businessFrame;$original=$context['origin'];$control=self::$frameBulkControl;$boundary=self::$fileBoundary;
        return ['matches'=>static fn():bool=>self::$businessFrame===$frame,'assert'=>static function(string $action,array $manifest)use($original,$control,$boundary):void{
            BulkDeadline::check();$authority=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);if($control===null){$authority->assert($original);}else{$authority->assertPair($original,$control);}if($boundary!==null){$boundary($action,$manifest);}BulkDeadline::check();
        }];
    }
    /** Nonserializable current/original transfer capability for final validation in this exact transaction frame. */
    public static function transferFilesAuthority(): \Closure {
        $context=self::current();$frame=self::$businessFrame;
        $input=$context['input'];$target=$input['owner_id']??null;$formId=$input['id']??null;
        if($frame===null||$context['action']!=='forms_transfer'||$context['verified']['tool']!=='forms_transfer'||!is_string($target)||$target===''||!is_int($formId)||$formId<1||$formId!==($context['verified']['references']['form_id']??null))throw new \RuntimeException('Transfer business frame is unavailable');
        self::assertFresh();$original=$context['origin'];$control=self::$frameBulkControl;$boundary=self::$fileBoundary;
        return static function(array $manifest,array $copies,array $parents,\Closure $resolve)use($frame,$original,$control,$boundary,$input,$target):void {
            if(self::$businessFrame!==$frame||self::$current===null||self::$current['origin']!==$original||self::$frameBulkControl!==$control||self::$current['input']!==$input||!Server::get(FormsRevisionFence::class)->active())throw new \RuntimeException('Transfer business frame changed');
            BulkDeadline::check();
            foreach([...$copies,...$parents] as $node)if(!$node instanceof \OCP\Files\Node||$node->getOwner()?->getUID()!==$target)throw new \RuntimeException('Transfer destination owner changed');
            if($boundary!==null)foreach(array_chunk($manifest['files'],100) as $page)$boundary('forms_transfer',['files'=>$page]);
            Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertTransferFiles($original,$control,$manifest,$copies,$parents,$resolve);
            BulkDeadline::check();
        };
    }
    /** Private original/current authority for native transfer reads; never reconstructed from a UID. */
    public static function transferSharingAuthority():array {
        $context=self::current();$business=self::$businessFrame;$input=$context['input'];$uid=$context['verified']['uid']??null;
        $fence=Server::get(FormsRevisionFence::class);
        if ($business===null || !$fence->active() || $context['verified']['tool']!=='forms_transfer' || !is_string($uid) || $uid==='' || !is_string($input['owner_id']??null) || $input['owner_id']==='' || !is_int($input['id']??null) || $input['id']<1 || $input['id']!==($context['verified']['references']['form_id']??null) || !in_array('share',$context['verified']['references']['files_operations']??[],true)) {throw new \RuntimeException('Transfer sharing business frame is unavailable');}
        self::assertFresh();$origin=$context['origin'];$control=self::$frameBulkControl;$system=ScopedTransferTimeZone::systemFingerprint();$closed=false;
        $fence->afterTransaction(static function(bool $committed)use(&$closed):void {$closed=true;});
        $frame=static function()use($business,$origin,$control,$input,$fence,&$closed):void {
            if ($closed || self::$businessFrame!==$business || self::$current===null || self::$current['origin']!==$origin || self::$current['input']!==$input || self::$frameBulkControl!==$control || !$fence->active()) {throw new \RuntimeException('Transfer sharing business frame changed');}
            BulkDeadline::check();
        };
        $boundary=self::$fileBoundary;$target=$input['owner_id'];
        $joint=static function(\Closure $collect,\Closure $resolve)use($frame,$origin,$control,$boundary,$target):void {
            $frame();
            $bounded=static function()use($collect,$frame,$boundary,$target):array {
                $projection=\OCA\Forms\Mcp\SourceIdentity::withinVerifiedReadFrame($frame,static function()use($collect,$frame,$target):array {
                $frame();$projection=$collect();
                if(!is_array($projection)||!array_is_list($projection)||count($projection)!==3)throw new \RuntimeException('Joint transfer projection is invalid');
                foreach([...$projection[1],...$projection[2]]as$node)if(!$node instanceof \OCP\Files\Node||$node->getOwner()?->getUID()!==$target)throw new \RuntimeException('Joint transfer destination owner changed');
                $frame();return$projection;
                });
                if($boundary!==null)foreach(array_chunk($projection[0]['files'],100)as$page)$boundary('forms_transfer',['files'=>$page]);
                $frame();return$projection;
            };
            Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertTransferWithShares($origin,$control,$bounded,$resolve);
            $frame();
        };
        return ['uid'=>$uid,'frame'=>$frame,'joint'=>$joint,'fresh'=>static function()use($frame,$system):void {
            $frame();self::assertFresh();
            if (!hash_equals($system,ScopedTransferTimeZone::systemFingerprint())) {throw new \RuntimeException('Transfer system configuration changed');}
            $frame();
        }];
    }
    /** Read-only Circle dependency authority for this exact verified business frame, including current bulk control. */
    public static function circleReadAuthority(string $uid): \Closure {
        $context=self::current();$frame=self::$businessFrame;
        if($frame===null||$context['verified']['uid']!==$uid){throw new \RuntimeException('Circle original actor frame is unavailable');}
        $origin=$context['origin'];
        return static function()use($uid,$frame,$origin):void {
            if(self::$businessFrame!==$frame||self::$current===null||self::$current['verified']['uid']!==$uid||self::$current['origin']!==$origin){throw new \RuntimeException('Circle original actor frame changed');}
            self::assertFresh();
        };
    }
    /** Attach additional signed authority to the next exact bulk business frame only. */
    public static function withBulkControl(string $tool,array $original,callable $work): mixed {
        $control=self::current();
        try {$stored=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assert($original);}
        catch(\Throwable $e){throw new \OCA\Forms\Mcp\BeforeEffect('Stored bulk authority is unavailable',0,$e);}
        $current=$control['verified'];
        foreach(['uid','actor','connection'] as $key) {
            if($current[$key]!==$stored[$key]){throw new \OCA\Forms\Mcp\BeforeEffect('Bulk control identity does not match');}
        }
        if(isset($current['references']['form_id']) && ($current['references']['form_id']!==($stored['references']['form_id']??null))) {
            throw new \OCA\Forms\Mcp\BeforeEffect('Bulk control form does not match');
        }
        if($current['tool']===$stored['tool']) {
            // Native fixture/integration callers may still supply the original signed action.
            if($current!==$stored){throw new \OCA\Forms\Mcp\BeforeEffect('Legacy bulk authority does not match');}
            return $work();
        }
        $authority=$current['references']['bulk_authority']??null;
        if($current['tool']!==$tool || !is_array($authority)
            || $authority['tool']!==$stored['tool']
            || $authority['required_operations']!==$stored['operations']
            || $authority['files_operations']!==($stored['references']['files_operations']??[])) {
            throw new \OCA\Forms\Mcp\BeforeEffect('Current bulk control authority does not match');
        }
        if(self::$bulkControlActive){throw new \OCA\Forms\Mcp\BeforeEffect('Nested bulk control is unavailable');}
        self::$bulkControlActive=true;
        self::$nextBulkFrame=['original'=>$original,'control'=>$control['origin']];
        try{return $work();}finally{self::$nextBulkFrame=null;self::$bulkControlActive=false;}
    }
    /** Refresh after waits and before file effects. Callers may type a rejection only while no effect has begun. */
    public static function assertFresh(): void {
        $context=self::current();
        try {
            $authorization=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);
            if(self::$frameBulkControl===null){$authorization->assert($context['origin']);}
            else{$authorization->assertPair($context['origin'],self::$frameBulkControl);}
        }
        catch(\Throwable $e){throw new \RuntimeException('Original Forms authority changed',0,$e);}
    }
    public static function assertFiles(string $action,array $manifest): void {
        BulkDeadline::check();
        $context=self::current();
        if(self::$fileBoundary!==null){(self::$fileBoundary)($action,$manifest);}
        BulkDeadline::check();
        try {Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertFiles($context['origin'],$action,$manifest);}
        catch(\Throwable $e){throw new \RuntimeException('Original Forms file authority changed',0,$e);}
        BulkDeadline::check();
    }
    /** Keep trusted file impact private to this operation, including streamed upload sources. */
    public static function withFileImpact(string $action, array $manifest, callable $work): mixed {
        $old = self::current();
        self::$current['file_action'] = $action;
        self::$current['file_impact'] = $manifest;
        self::$current['verified_deleted_ids'] = [];
        try {
            return $work();
        } finally {
            self::$current = $old;
        }
    }
    /** Called at the storage or share sink after its lock has been acquired. */
    public static function assertFileEffect(array $currentFolderIds=[]): void {
        self::assertFresh();
        $context = self::current();
        if (isset($context['file_action'], $context['file_impact'])) {
            $remaining = $context['file_impact'];
            $completed = $context['verified_deleted_ids'] ?? [];
            $remaining['files'] = array_values(array_filter($remaining['files'] ?? [],
                static fn (array $file): bool => !in_array((int)$file['file_id'], $completed, true)));
            if ($currentFolderIds!==[]) {
                $remaining['share_impact']['folder_ids']=array_values(array_unique(array_merge($remaining['share_impact']['folder_ids']??[],$currentFolderIds)));
            }
            self::assertFiles($context['file_action'], $remaining);
        }
    }
    /** Called only after the approved deletion returned and storage absence was verified. */
    public static function verifiedDeletion(int $fileId): void {
        $context = self::current();
        if (!in_array($fileId, $context['file_impact']['delete_file_ids'] ?? [], true)) {
            throw new \LogicException('Completed file deletion was not approved');
        }
        self::$current['verified_deleted_ids'][] = $fileId;
    }
    public static function active(): bool {return self::$current!==null;}
    public static function current(): array {return self::$current??throw new \OCA\Forms\Mcp\BeforeEffect('Signed Forms origin is required');}
    public static function identity(string $uid): string {
        $v=self::current()['verified'];
        if ($v['uid']!==$uid) {throw new \RuntimeException('Original Forms actor does not match');}
        return hash_hmac('sha256',json_encode([$uid,$v['actor'],$v['connection']],JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
    }
}
