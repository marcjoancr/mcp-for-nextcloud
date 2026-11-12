<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
/** Non-secret inbound identity. Password/token refreshes do not change message provenance. */
final class TagProfile {
    public const COLUMNS=['inbound_host','inbound_port','inbound_ssl_mode','inbound_user','auth_method','protocol','email'];
    public static function row(array $row):string {
        $auth=$row['auth_method']??'password';$identity=[(string)$row['inbound_host'],(int)$row['inbound_port'],(string)$row['inbound_ssl_mode'],(string)$row['inbound_user'],$auth,$row['protocol']??'imap'];
        // The pinned native factory encodes account.email in its XOAUTH2 principal.
        if($auth==='xoauth2')$identity[]=(string)$row['email'];return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
    }
    public static function account(object $a):string {return self::row(['inbound_host'=>$a->getInboundHost(),'inbound_port'=>$a->getInboundPort(),'inbound_ssl_mode'=>$a->getInboundSslMode(),'inbound_user'=>$a->getInboundUser(),'auth_method'=>$a->getAuthMethod(),'protocol'=>$a->getProtocol(),'email'=>$a->getEmail()]);}
}
