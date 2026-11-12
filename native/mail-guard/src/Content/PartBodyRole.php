<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
/** One bounded MIME role map for display, indexing, draft projection and attachment discovery. */
final class PartBodyRole {
    public static function classify(array $parts):array {
        if($parts===[]||count($parts)>100)throw new \RuntimeException('Content MIME roles exceed bound.');
        $indexes=[];$children=[];$roots=[];
        foreach($parts as $index=>$part){
            $id=$part['part_id'];if(!is_string($id)||isset($indexes[$id])||!is_bool($part['leaf']))throw new \RuntimeException('Ambiguous MIME part identity.');
            $indexes[$id]=$index;$children[$id]=[];
            if($part['parent_id']===null)$roots[]=$id;
            $parts[$index]['role']=$part['leaf']?'attachment':'container';$parts[$index]['related_root_id']=null;
        }
        if(count($roots)!==1)throw new \RuntimeException('Ambiguous MIME root.');
        foreach($parts as $part)if($part['parent_id']!==null){if(!isset($indexes[$part['parent_id']]))throw new \RuntimeException('Missing MIME parent.');$children[$part['parent_id']][]=$part['part_id'];}
        $visited=0;
        $walk=function(string $id,bool $eligible,int $depth)use(&$walk,&$parts,$indexes,$children,&$visited):void {
            if($depth>16||++$visited>100)throw new \RuntimeException('Content MIME roles exceed bound.');
            $index=$indexes[$id];$part=$parts[$index];$eligible=$eligible&&$part['disposition']!=='attachment'&&$part['filename']===null;
            if($part['leaf']){
                if($children[$id]!==[])throw new \RuntimeException('Ambiguous MIME leaf.');
                if($eligible&&in_array($part['mime_type'],['text/plain','text/html'],true))$parts[$index]['role']='body';return;
            }
            if($children[$id]===[]||!in_array($part['mime_type'],['multipart/mixed','multipart/alternative','multipart/related'],true))throw new \RuntimeException('Unsupported MIME body container.');
            $selected=null;
            if($part['mime_type']==='multipart/related'){
                $selected=$children[$id][0];$start=$part['related_start'];
                if($start!==null){
                    if(!is_string($start)||preg_match('/^<([^<>\s]{1,996})>$/D',$start,$match)!==1)throw new \RuntimeException('Unsupported related root identity.');
                    $matches=array_values(array_filter($children[$id],static fn(string $child):bool=>$parts[$indexes[$child]]['content_id']===$match[1]));
                    if(count($matches)!==1)throw new \RuntimeException('Ambiguous related root identity.');$selected=$matches[0];
                }
                $type=$part['related_type'];if($type!==null&&(!is_string($type)||$type!==$parts[$indexes[$selected]]['mime_type']))throw new \RuntimeException('Related root type contradicts its selected part.');
                $parts[$index]['related_root_id']=$selected;
            }
            foreach($children[$id] as $child)$walk($child,$eligible&&($selected===null||$child===$selected),$depth+1);
        };
        $walk($roots[0],true,1);if($visited!==count($parts))throw new \RuntimeException('Disconnected MIME structure.');return $parts;
    }
}
