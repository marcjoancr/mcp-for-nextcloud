<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCP\{IDBConnection,IConfig};

/** Admits only a single, configured TCP endpoint that is the PostgreSQL server itself. */
final class PgDirectConnection {
    private static function configuredEndpoint(string $configured):?array {
        if($configured===''||trim($configured)!==$configured||preg_match('/[\s,\/;@]/',$configured)===1)return null;
        $host=$configured;$port=5432;
        if(str_starts_with($configured,'[')){
            if(preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/D',$configured,$parts)!==1)return null;
            $host=$parts[1];if(isset($parts[2])&&$parts[2]!=='')$port=(int)$parts[2];
        } elseif(substr_count($configured,':')>1){
            if(@inet_pton($configured)===false)return null;
        } elseif(preg_match('/^([^:]+)(?::([0-9]+))?$/D',$configured,$parts)===1){
            $host=$parts[1];if(isset($parts[2])&&$parts[2]!=='')$port=(int)$parts[2];
        } else return null;
        if($port<1||$port>65535)return null;
        $packed=@inet_pton($host);
        if($packed!==false)return ['address'=>$packed,'port'=>$port];
        $records=@dns_get_record($host,DNS_A|DNS_AAAA);if(!is_array($records))return null;
        $addresses=[];
        foreach($records as $record){
            $ip=match($record['type']??null){'A'=>$record['ip']??null,'AAAA'=>$record['ipv6']??null,default=>null};
            if(!is_string($ip)||($binary=@inet_pton($ip))===false)continue;
            $addresses[bin2hex($binary)]=$binary;
        }
        if(count($addresses)!==1)return null;
        return ['address'=>array_values($addresses)[0],'port'=>$port];
    }
    public static function verified(IDBConnection $db,IConfig $config):bool {
        if($db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES)return false;
        try {
            $configured=self::configuredEndpoint($config->getSystemValueString('dbhost',''));
            if($configured===null)return false;
            $result=$db->executeQuery('SELECT host(inet_server_addr()) AS address, inet_server_port() AS port');
            try {$row=$result->fetch();} finally {$result->closeCursor();}
            if(!is_array($row)||!is_string($row['address']??null)||!is_numeric($row['port']??null))return false;
            $actual=@inet_pton($row['address']);
            return $actual!==false&&hash_equals($configured['address'],$actual)&&$configured['port']===(int)$row['port'];
        } catch(\Throwable){return false;}
    }
}
