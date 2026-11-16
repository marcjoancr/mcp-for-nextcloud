<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

final class NativeHomePath {
    public static function prospectiveFilesPath(string $uid): string {
        self::assertUid($uid);
        return '/' . $uid . '/files';
    }

    /** Core node paths are virtual user paths; storage configuration paths are physical data paths. */
    public static function matchesSelectedFilesPath(string $nodePath, string $expectedPath): bool {
        return $expectedPath !== '' && $nodePath === $expectedPath;
    }

    /** Select only an already registered Home mount; this never asks the manager to resolve a path. */
    public static function selectedHomeMount(string $uid, array $mounts): ?object {
        self::assertUid($uid);
        $expected = '/' . $uid;
        $found = null;
        foreach ($mounts as $mount) {
            $normalized = self::mountPoint($mount);
            if ($normalized === $expected) {
                if ($found !== null) { throw new \RuntimeException('Forms Home mount metadata is unavailable'); }
                $found = $mount;
            }
        }
        return $found;
    }

    /** Return the most specific already materialized mount covering a path. */
    public static function mountForPath(string $path, array $mounts): ?object {
        if (!self::absolutePath($path)) { throw new \RuntimeException('Forms Home mount metadata is unavailable'); }
        $selected = null;
        $selectedLength = -1;
        foreach ($mounts as $mount) {
            $point = self::mountPoint($mount);
            if ($point === '/' || ($path !== $point && !str_starts_with($path, $point . '/'))) { continue; }
            if (strlen($point) > $selectedLength) { $selected = $mount; $selectedLength = strlen($point); }
        }
        return $selected;
    }

    /** Qualify only the pinned Database user under the ordinary local data directory. */
    public static function assertDefaultLocalConfig(string $uid, string $home, string $dataDirectory, mixed $objectStore, mixed $multiBucket): string {
        try { self::assertUid($uid); }
        catch (\Throwable $error) { throw new \RuntimeException('Forms local Home profile is unavailable', 0, $error); }
        if ($objectStore !== false || $multiBucket !== false || !self::absolutePath($dataDirectory)
            || !self::absolutePath($home) || $home !== rtrim($dataDirectory, '/') . '/' . $uid) {
            throw new \RuntimeException('Forms local Home profile is unavailable');
        }
        return $home;
    }

    /** Bounded metadata-only collision check for a path under the pinned Local Home root. */
    public static function assertNoPathCollision(string $home, string $relative): bool {
        $parts = explode('/', $relative);
        if (!self::absolutePath($home) || $relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $relative) || count($parts) > 64
            || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new \RuntimeException('Forms destination metadata is unavailable');
        }
        $current = rtrim($home, '/');
        foreach ([$current, ...$parts] as $index => $part) {
            if ($index > 0) { $current .= '/' . $part; }
            $stat = @lstat($current);
            if ($stat === false) {
                $parent = dirname($current);
                if (is_dir($parent) && is_executable($parent)) { return true; }
                if ($index === 0 && is_dir(dirname($current)) && is_executable(dirname($current))) { return true; }
                throw new \RuntimeException('Forms destination metadata is unavailable');
            }
            if (is_link($current)) { throw new \RuntimeException('Forms destination metadata is unavailable'); }
            if ($index === count($parts)) { throw new \RuntimeException('Forms destination already exists'); }
            if (($stat['mode'] & 0170000) !== 0040000) { throw new \RuntimeException('Forms destination metadata is unavailable'); }
        }
        return true;
    }

    /** Stable cleanup identity: directory timestamps are excluded because child creation changes them. */
    public static function identityDigest(string $path, bool $includeContent = false): string {
        if (!self::absolutePath($path)) { throw new \RuntimeException('Forms destination metadata is unavailable'); }
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !in_array($stat['mode'] & 0170000, [0040000, 0100000], true)) {
            throw new \RuntimeException('Forms destination metadata is unavailable');
        }
        $identity = ['dev'=>(int)$stat['dev'], 'ino'=>(int)$stat['ino'], 'mode'=>(int)$stat['mode'], 'uid'=>(int)$stat['uid']];
        if ($includeContent) {
            if (($stat['mode'] & 0170000) !== 0100000) { throw new \RuntimeException('Forms destination metadata is unavailable'); }
            $digest = @hash_file('sha256', $path);
            if (!is_string($digest)) { throw new \RuntimeException('Forms destination metadata is unavailable'); }
            $identity['content'] = $digest;
        }
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public static function scanWithinBound(bool $recursive, int $entries): bool {
        return $entries >= 0 && ($recursive ? $entries === 0 : $entries <= 100);
    }

    private static function absolutePath(string $path): bool {
        return $path !== '' && $path[0] === '/' && !str_contains($path, "\0")
            && !str_contains($path, '\\') && !str_contains($path, '//')
            && !in_array('..', explode('/', $path), true) && !in_array('.', explode('/', $path), true);
    }

    private static function mountPoint(mixed $mount): string {
        if (!is_object($mount) || !method_exists($mount, 'getMountPoint')) {
            throw new \RuntimeException('Forms Home mount metadata is unavailable');
        }
        try { $path = $mount->getMountPoint(); }
        catch (\Throwable $error) { throw new \RuntimeException('Forms Home mount metadata is unavailable', 0, $error); }
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '//')
            || str_contains($path, '\\') || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            throw new \RuntimeException('Forms Home mount metadata is unavailable');
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function assertUid(string $uid): void {
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,255}$/D', $uid)) {
            throw new \RuntimeException('Forms destination identity is unavailable');
        }
    }
}
