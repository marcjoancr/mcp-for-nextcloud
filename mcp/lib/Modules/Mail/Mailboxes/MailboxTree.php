<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mailboxes;
/** Folder-name scope, independent of native clients and message content. */
final class MailboxTree {
    public static function prepare(array $m,array $a):array {
        if(count($m['folders'])>1000)throw new \InvalidArgumentException('Folder inventory exceeds the supported limit.');
        $action=$a['action']??'';if(!in_array($action,['create_mailbox','rename_mailbox','subscribe_mailbox'],true))throw new \InvalidArgumentException('Unsupported folder action.');
        $id=(int)($action==='create_mailbox'?($a['parent_mailbox_id']??0):($a['mailbox_id']??0));$source=null;
        foreach($m['folders'] as $b)if($b['id']===$id)$source=$b;
        if(($id!==0||$action!=='create_mailbox')&&($source===null||$source['shared']))throw new \InvalidArgumentException('Owned personal folder unavailable.');
        $delimiters=array_values(array_unique(array_column($m['folders'],'delimiter')));
        $delimiter=$source['delimiter']??(count($delimiters)===1?$delimiters[0]:null);
        if(!is_string($delimiter)||strlen($delimiter)!==1)throw new \InvalidArgumentException('Native hierarchy delimiter is not verified.');
        if($source!==null&&preg_match('/[\x00-\x1f\x7f*%]/u',$source['name']))throw new \InvalidArgumentException('Native folder name cannot be addressed literally.');
        $name=$source['name']??'';$new=$name;$tree=$source===null?[]:[$source];$subscribed=null;
        if($action!=='subscribe_mailbox'){
            $leaf=$a['name']??null;
            if(!is_string($leaf)||$leaf===''||strlen($leaf)>255||trim($leaf)!==$leaf||preg_match('/[\x00-\x1f\x7f*%#]/u',$leaf)||str_contains($leaf,$delimiter)||in_array(strtolower($leaf),['inbox','dovecot.sieve'],true))throw new \InvalidArgumentException('Choose a literal folder leaf name without controls or hierarchy wildcards.');
            if($action==='create_mailbox')$prefix=$source!==null?$name.$delimiter:($m['namespace']===null?'':rtrim($m['namespace'],$delimiter).$delimiter);
            else {$end=strrpos($name,$delimiter);$prefix=$end===false?'':substr($name,0,$end+1);}
            $new=$prefix.$leaf;if(strlen($new)>255||$new===$name)throw new \InvalidArgumentException('Choose a distinct bounded folder name.');
            foreach($m['folders'] as $b)if($b['name']===$new||str_starts_with($b['name'],$new.$delimiter))throw new \InvalidArgumentException('Destination folder already exists.');
        }else{
            if(!is_bool($a['subscribed']??null))throw new \InvalidArgumentException('Choose an explicit subscription boolean.');$subscribed=$a['subscribed'];
        }
        if($action==='rename_mailbox'){
            $tree=array_values(array_filter($m['folders'],static fn(array $b):bool=>$b['id']===$id||str_starts_with($b['name'],$name.$delimiter)));
            if(count($tree)>101)throw new \InvalidArgumentException('Rename is limited to 100 descendants.');
            foreach($tree as $b)if($b['shared']||$b['special']!==[]||in_array($b['id'],$m['roles'],true)||strcasecmp($b['name'],'INBOX')===0||str_ends_with(strtolower($b['name']),'dovecot.sieve'))throw new \InvalidArgumentException('Special, shared or reserved folders cannot be renamed.');
            foreach($tree as $b){$target=$new.substr($b['name'],strlen($name));if(strlen($target)>255)throw new \InvalidArgumentException('Descendant target name is too long.');foreach($m['folders'] as $other)if($other['name']===$target)throw new \InvalidArgumentException('Descendant destination already exists.');}
        }
        foreach($tree as $b)if($b['generation']===null)throw new \InvalidArgumentException('Synchronize this folder in native Mail before modifying its identity.');
        return ['action'=>$action,'mailbox_id'=>$id,'name'=>$name,'new_name'=>$new,'delimiter'=>$delimiter,'subscribed'=>$subscribed,'tree'=>$tree];
    }
}
