<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Attachments;
use OCP\Files\File;
use OCP\Lock\ILockingProvider;
/** Private preimage binding covers native ETag reuse within one storage timestamp interval. */
final class FormsFilePreimage {
    public static function capture(File $file):array {
        $file->lock(ILockingProvider::LOCK_SHARED);
        try {
            $etag=(string)$file->getEtag();$size=(int)$file->getSize();
            if($size<0||$size>FormsAnswerUploads::MAX_BYTES)throw new \RuntimeException('Forms overwrite preimage exceeds its byte quota.');
            $actual=self::digest($file->fopen('rb'));
            if($actual['size']!==$size||(string)$file->getEtag()!==$etag)throw new \RuntimeException('Forms overwrite destination changed during preview.');
            return $actual;
        } finally {$file->unlock(ILockingProvider::LOCK_SHARED);}
    }
    public static function assert(File $file,array $approved):void {
        if(!is_int($approved['size']??null)||$approved['size']<0||$approved['size']>FormsAnswerUploads::MAX_BYTES||preg_match('/^[a-f0-9]{64}$/D',$approved['sha256']??'')!==1)throw new \RuntimeException('Invalid Forms overwrite preimage.');
        // The qualified wrapper already holds View's exclusive lock. Reentering View here would change its lock state.
        $actual=self::digest($file->getStorage()->fopen($file->getInternalPath(),'rb'));
        if($actual['size']!==$approved['size']||!hash_equals($approved['sha256'],$actual['sha256']))throw new \RuntimeException('Forms destination content changed after approval.');
    }
    private static function digest(mixed $stream):array {
        if(!is_resource($stream))throw new \RuntimeException('Forms overwrite preimage is unavailable.');
        try {
            $hash=hash_init('sha256');$size=hash_update_stream($hash,$stream,FormsAnswerUploads::MAX_BYTES+1);$sha=hash_final($hash);
            if($size>FormsAnswerUploads::MAX_BYTES)throw new \RuntimeException('Forms overwrite preimage exceeds its byte quota.');
            return ['size'=>$size,'sha256'=>$sha];
        } finally {fclose($stream);}
    }
}
