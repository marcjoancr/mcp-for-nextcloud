<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCP\IURLGenerator;
use OCP\Security\IRemoteHostValidator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
class NativeAccountSetupLinks {
    public function __construct(private IURLGenerator $urls,private IRemoteHostValidator $hosts){}
    public function url(bool $create,?string $context=null): string {
        $base=$this->urls->linkToRouteAbsolute('mail.page.index');
        $url=$create?$this->urls->linkToRouteAbsolute('mail.page.setup'):$base;
        $a=parse_url($base);$b=parse_url($url);
        if(!is_array($a)||!is_array($b)||!in_array($b['scheme']??'', ['http','https'],true)
            || ($a['scheme']??null)!==($b['scheme']??null)||($a['host']??null)!==($b['host']??null)||($a['port']??null)!==($b['port']??null)
            ||isset($b['user'])||isset($b['pass'])||isset($b['query']))throw new ToolFailure('Native Mail settings link is unavailable.');
        if($context!==null){if(preg_match('/^[a-f0-9]{64}$/D',$context)!==1)throw new ToolFailure('Native Mail settings link is unavailable.');$url.='?mcpSetup='.$context;}
        return $url;
    }
    public function validate(array $profile,bool $complete=false): void {
        foreach(['imap','smtp'] as $protocol){
            $p=$profile[$protocol]??[];
            if(isset($p['host']) && !$this->hosts->isValid($p['host']))throw new ToolFailure('This mail server is not allowed by the native host policy.');
            if(($complete||isset($p['tls'])) && !in_array($p['tls']??null,['ssl','tls'],true))throw new ToolFailure('Choose a supported TLS connection in native Mail.');
            if(($complete||isset($p['port'])) && (!is_int($p['port']??null)||$p['port']<1||$p['port']>65535))throw new \InvalidArgumentException('Invalid mail server port.');
        }
        if(isset($profile['auth_method']) && !in_array($profile['auth_method'],['password','xoauth2'],true))throw new \InvalidArgumentException('Unsupported authentication method.');
    }
}
