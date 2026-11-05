<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Descriptions;

use OCP\Files\File;

/** Reads the bounded EXIF IFD0 ImageDescription field from JPEG data. */
final class EmbeddedDescriptionReader {
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function read(File $file): ?string {
        try {
            $stream = $file->fopen('rb');
            if (!is_resource($stream)) return null;
            $bytes = fread($stream, self::MAX_BYTES);
            fclose($stream);
            if (!is_string($bytes)) return null;
            return $this->fromJpeg($bytes);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromJpeg(string $bytes): ?string {
        if (!str_starts_with($bytes, "\xFF\xD8")) return null;
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 4 <= $length && ord($bytes[$offset]) === 0xFF) {
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) $offset++;
            if ($offset >= $length) return null;
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xDA || $marker === 0xD9) return null;
            if ($offset + 2 > $length) return null;
            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            if ($segmentLength < 2 || $offset + $segmentLength > $length) return null;
            $segment = substr($bytes, $offset + 2, $segmentLength - 2);
            if ($marker === 0xE1 && str_starts_with($segment, "Exif\0\0")) {
                return $this->fromTiff(substr($segment, 6));
            }
            $offset += $segmentLength;
        }
        return null;
    }

    private function fromTiff(string $tiff): ?string {
        if (strlen($tiff) < 8) return null;
        $little = substr($tiff, 0, 2) === 'II';
        if (!$little && substr($tiff, 0, 2) !== 'MM') return null;
        $u16 = static fn (int $p): ?int => $p + 2 <= strlen($tiff) ? unpack($little ? 'v' : 'n', substr($tiff, $p, 2))[1] : null;
        $u32 = static fn (int $p): ?int => $p + 4 <= strlen($tiff) ? unpack($little ? 'V' : 'N', substr($tiff, $p, 4))[1] : null;
        if ($u16(2) !== 42) return null;
        $ifd = $u32(4);
        if ($ifd === null || $ifd + 2 > strlen($tiff)) return null;
        $count = min($u16($ifd) ?? 0, 256);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if ($entry + 12 > strlen($tiff)) return null;
            if ($u16($entry) !== 0x010E) continue;
            $type = $u16($entry + 2);
            $items = $u32($entry + 4);
            $dataOffset = $items !== null && $items <= 4 ? $entry + 8 : $u32($entry + 8);
            if ($type !== 2 || $items === null || $items < 1 || $items > 4096 || $dataOffset === null || $dataOffset + $items > strlen($tiff)) return null;
            $value = rtrim(substr($tiff, $dataOffset, $items), "\0 \t\r\n");
            return mb_check_encoding($value, 'UTF-8') ? $value : null;
        }
        return null;
    }
}
