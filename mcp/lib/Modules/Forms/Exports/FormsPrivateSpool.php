<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ICrypto;
/** Instance-encrypted content-addressed artifacts, never Files user data or public paths. */
final class FormsPrivateSpool implements FormsSpool {
    public function __construct(private IAppData $data,private ICrypto $crypto) {}
    private function folder(string $job,bool $create):ISimpleFolder {
        if(preg_match('/^[a-f0-9]{64}$/D',$job)!==1)throw new \InvalidArgumentException('Invalid Forms artifact identity.');
        $name='forms-results-'.$job;
        try{return $this->data->getFolder($name);}catch(NotFoundException $e){if(!$create)throw $e;}
        try{return $this->data->newFolder($name);}catch(\Throwable){return $this->data->getFolder($name);}
    }
    public function put(string $job,string $content):array {
        if(strlen($content)>26214400)throw new \RuntimeException('Forms artifact exceeds its byte quota.');
        $part=['key'=>hash('sha256',$content),'bytes'=>strlen($content)];$folder=$this->folder($job,true);
        if(!$folder->fileExists($part['key']))$folder->newFile($part['key'],$this->crypto->encrypt($content));
        if($this->get($job,$part)!==$content)throw new \RuntimeException('Forms artifact verification failed.');
        return $part;
    }
    public function get(string $job,array $part):string {
        if(preg_match('/^[a-f0-9]{64}$/D',$part['key']??'')!==1)throw new \RuntimeException('Forms artifact is unavailable.');
        $file=$this->folder($job,false)->getFile($part['key']);
        if($file->getSize()>52428800)throw new \RuntimeException('Forms artifact exceeds its byte quota.');
        $content=$this->crypto->decrypt($file->getContent());
        if(strlen($content)!==$part['bytes']||!hash_equals($part['key'],hash('sha256',$content)))throw new \RuntimeException('Forms artifact verification failed.');
        return $content;
    }
}
