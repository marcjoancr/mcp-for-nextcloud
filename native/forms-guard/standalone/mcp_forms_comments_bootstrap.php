<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** This class lives outside app trees and has only core declaration dependencies. */
final class CommentsFactory implements \OCP\Comments\ICommentsManagerFactory {
    public function __construct(private \Psr\Container\ContainerInterface $serverContainer) {}
    public function getManager() {
        try {
            $runtime='OCA\\Forms\\Mcp\\Comments\\CommentsRuntime';
            $manager='OCA\\Forms\\Mcp\\Comments\\GuardedCommentsManager';
            if (class_exists($runtime) && class_exists($manager) && $runtime::available()) {
                return $manager::createManager($this->serverContainer);
            }
        } catch (\Throwable) { /* Optional app qualification precedes every mutation. */ }
        self::invalidateActivation();
        return (new \OC\Comments\ManagerFactory($this->serverContainer))->getManager();
    }
    private static function invalidateActivation(): void {
        $path=rtrim(\OC::$configDir,'/').'/mcp_forms_comments_install.json';
        try {
            if (!is_file($path) || is_link($path)) {return;}
            $metadata=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            if (($metadata['active']??false)!==true) {return;}
            $metadata['active']=false;
            $temporary=$path.'.gap-'.bin2hex(random_bytes(6));
            $json=json_encode($metadata,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)) {return;}
            chmod($temporary,0640);
            if (!rename($temporary,$path)) {unlink($temporary);}
        } catch (\Throwable) { /* Read-only activation metadata also closes native capability publication. */ }
    }

}
