<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCP\{IURLGenerator,IConfig};
/** Caller supplies an owned draft; constructing a link neither opens Mail nor changes its hold. */
class NativeDraftLinks {
    public function __construct(private MailNativeBoundary $boundary,private IURLGenerator $urls,private IConfig $config){}
    public function available():bool {
        return $this->boundary->runtimeMatches($this->config->getSystemValueString('version',''))&&$this->boundary->draftsVerified();
    }
    public function forDraft(int $id,object $draft):?string {
        if($id<1||!$this->available())return null;
        if(!$draft->getMcpDraftOnly()||$draft->getSmimeSign()||$draft->getSmimeEncrypt()||$draft->isPgpMime()||$draft->getSendAt()!==null)return null;
        $base=$this->urls->linkToRouteAbsolute('mail.page.index');$parts=parse_url($base);
        if(!is_array($parts)||!in_array($parts['scheme']??null,['http','https'],true)||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))return null;
        return $base.'?'.http_build_query(['mcpLocalDraft'=>$id]);
    }
}
