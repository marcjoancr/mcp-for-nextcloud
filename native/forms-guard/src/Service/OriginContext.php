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
            BulkDeadline::check();$authority=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);$authority->assert($original);if($control!==null){$authority->assert($control);}if($boundary!==null){$boundary($action,$manifest);}BulkDeadline::check();
        }];
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
            $authorization->assert($context['origin']);
            if(self::$frameBulkControl!==null){$authorization->assert(self::$frameBulkControl);}
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
