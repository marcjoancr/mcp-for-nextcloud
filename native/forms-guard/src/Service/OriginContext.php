<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Request-local verified provenance; no UID-only fallback exists for MCP artifacts. */
final class OriginContext {
    private static ?array $current=null;
    public static function within(string $uid,array $origin,string $action,array $input,callable $work): mixed {
        try {$verified=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assert($origin);}
        catch(\Throwable $e){throw new \OCA\Forms\Mcp\BeforeEffect('Signed Forms authority is unavailable',0,$e);}
        if ($verified['uid']!==$uid || (isset($input['id'],$verified['references']['form_id']) && (int)$input['id']!==(int)$verified['references']['form_id'])) {throw new \OCA\Forms\Mcp\BeforeEffect('Original Forms authority does not match');}
        if ($action!==$verified['tool'] && !in_array($action,['forms_results_metadata','forms_results_page','lookup','effect'],true)) {throw new \OCA\Forms\Mcp\BeforeEffect('Original Forms action does not match');}
        $old=self::$current;unset($input['_mcp_origin']);
        self::$current=['origin'=>$origin,'verified'=>$verified,'action'=>$action,'input'=>$input];
        try {return $work();} finally {self::$current=$old;}
    }
    /** Refresh after waits and before file effects. Callers may type a rejection only while no effect has begun. */
    public static function assertFresh(): void {
        $context=self::current();
        try {Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assert($context['origin']);}
        catch(\Throwable $e){throw new \RuntimeException('Original Forms authority changed',0,$e);}
    }
    public static function assertFiles(string $action,array $manifest): void {
        $context=self::current();
        try {Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertFiles($context['origin'],$action,$manifest);}
        catch(\Throwable $e){throw new \RuntimeException('Original Forms file authority changed',0,$e);}
    }
    public static function active(): bool {return self::$current!==null;}
    public static function current(): array {return self::$current??throw new \OCA\Forms\Mcp\BeforeEffect('Signed Forms origin is required');}
    public static function identity(string $uid): string {
        $v=self::current()['verified'];
        if ($v['uid']!==$uid) {throw new \RuntimeException('Original Forms actor does not match');}
        return hash_hmac('sha256',json_encode([$uid,$v['actor'],$v['connection']],JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
    }
}
