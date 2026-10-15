<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property;
use Throwable;

/**
 * The single output rule for structured references to Files nodes that other modules carry: the attachmentId of a
 * Talk room share and the ATTACH property of a task. Before such a reference is exposed in a read, a plan, a
 * before/after, a confirmed result or an error, it is resolved in the user's own tree and passes, in this order, the
 * Files scope, the exclusion tags and the native access. A reference that fails any of them, or that cannot be
 * resolved, is omitted. Free text is never redacted.
 *
 * Nothing here fetches a URL: a local URL is mapped to a file id or a user path by its shape alone, and an external
 * one is kept as it is. With no exclusion configured and no Files scope, every reference passes untouched, as before.
 */
class OutputReferenceFilter {
    public function __construct(
        private IRootFolder $rootFolder,
        private IShareManager $shareManager,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private VisibilityGuard $guard,
        private FileReferenceScope $scope,
    ) {}

    /**
     * @param string $userId authenticated user
     * @return bool whether any gate can refuse a reference for this user; an unreadable exclusion configuration counts
     */
    public function active(string $userId): bool {
        return !$this->guard->isConfigurationValid()
            || $this->guard->getHiddenTagIds() !== []
            || $this->scope->restricts($userId);
    }

    /**
     * The attachment id a message may show. The message text is never touched.
     *
     * @param string $userId authenticated user
     * @param string $conversationToken conversation the message was read from
     * @param int|null $attachmentId bare room share id carried by the Talk envelope
     * @return int|null the same id, or null when its file is refused or the share cannot be resolved to it
     */
    public function attachmentId(string $userId, string $conversationToken, ?int $attachmentId): ?int {
        if ($attachmentId === null || !$this->active($userId)) {
            return $attachmentId;
        }
        try {
            $share = $this->shareManager->getShareById(AttachmentAccess::fullShareId($attachmentId), $userId);
            if ($share->getShareType() !== IShare::TYPE_ROOM || (string)$share->getSharedWith() !== $conversationToken) {
                return null;
            }
            return $this->allowsFileId($userId, (int)$share->getNodeId()) ? $attachmentId : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The representation of a task calendar a tool may show. The original object is never changed: it stays the one
     * written back to DAV, so an edit of another field cannot drop a persisted ATTACH, and a plan that sanitizes both
     * sides the same way shows no removal as a change.
     *
     * @param string $userId authenticated user
     * @param VCalendar $calendar calendar as read from or prepared for DAV
     * @return VCalendar the calendar itself when nothing can be refused, otherwise a copy without the refused ATTACH
     */
    public function outputCalendar(string $userId, VCalendar $calendar): VCalendar {
        if (!$this->active($userId)) {
            return $calendar;
        }
        $copy = clone $calendar;
        $this->sanitizeComponent($userId, $copy);
        return $copy;
    }

    /** Removes the refused ATTACH of a component and of every component nested in it, alarms included. */
    private function sanitizeComponent(string $userId, Component $component): void {
        foreach ($component->select('ATTACH') as $attach) {
            if ($attach instanceof Property && !$this->keepsAttach($userId, $attach)) {
                $component->remove($attach);
            }
        }
        foreach ($component->getComponents() as $child) {
            $this->sanitizeComponent($userId, $child);
        }
    }

    /**
     * @return bool whether an ATTACH may stay in the output: inline content and external URLs stay; a local URL stays
     *              only when every node it names is allowed
     */
    private function keepsAttach(string $userId, Property $attach): bool {
        // Inline content is the data itself, not a reference to a node.
        if (strtoupper((string)($attach['VALUE'] ?? '')) === 'BINARY' || strtoupper((string)($attach['ENCODING'] ?? '')) === 'BASE64') {
            return true;
        }
        $references = $this->localReferences(trim((string)$attach->getValue()));
        if ($references === null) {
            return true;
        }
        if ($references === []) {
            return false; // local, but no supported shape: it cannot be checked, so it is not shown
        }
        foreach ($references as $reference) {
            $allowed = isset($reference['id'])
                ? $this->allowsFileId($userId, $reference['id'])
                : $this->allowsPath($userId, $reference['path'], $reference['uid'] ?? null);
            if (!$allowed) {
                return false;
            }
        }
        return true;
    }

    /**
     * Maps a URL to the nodes it names on this server, by its shape only.
     *
     * Supported local shapes: /f/{fileid}, /apps/files/files/{fileid}, /apps/files/?fileid=, /s/{token},
     * /remote.php/dav/files/{uid}/… and /remote.php/webdav/…, with or without /index.php and the web root, relative
     * or on a trusted domain.
     *
     * @return list<array{id:int}|array{path:string, uid?:string}>|null null for a URL that is not on this server; an empty list
     *         for a local URL of an unsupported or unresolvable shape
     */
    private function localReferences(string $url): ?array {
        $parts = parse_url($url);
        if ($parts === false) {
            return [];
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        if (isset($parts['host'])) {
            if (!$this->isLocalHost($parts['host'], $parts['port'] ?? null)) {
                return null;
            }
            if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
                return [];
            }
        } elseif ($scheme !== null) {
            return null; // cid:, mailto:, urn: and the like do not name a node of this server
        }

        $path = $parts['path'] ?? '';
        $webroot = rtrim($this->urlGenerator->getWebroot(), '/');
        if ($webroot !== '' && ($path === $webroot || str_starts_with($path, $webroot . '/'))) {
            $path = substr($path, strlen($webroot));
        }
        if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }
        parse_str($parts['query'] ?? '', $query);

        $references = [];
        if (preg_match('#^/(?:f|apps/files/files)/(\d+)/?$#', $path, $match) === 1) {
            $references[] = ['id' => (int)$match[1]];
        } elseif (preg_match('#^/s/([^/]+)(?:/download)?/?$#', $path, $match) === 1) {
            // A path or file list inside a shared folder names a descendant the share root does not stand for.
            $id = isset($query['path']) || isset($query['files']) ? null : $this->shareTokenNodeId(rawurldecode($match[1]));
            if ($id === null) {
                return [];
            }
            $references[] = ['id' => $id];
        } elseif (preg_match('#^/remote\.php/dav/files/([^/]+)(/.*)?$#', $path, $match) === 1) {
            $references[] = ['uid' => rawurldecode($match[1]), 'path' => rawurldecode($match[2] ?? '/')];
        } elseif (preg_match('#^/remote\.php/webdav(/.*)?$#', $path, $match) === 1) {
            $references[] = ['path' => rawurldecode($match[1] ?? '/')];
        } elseif (preg_match('#^/apps/files(?:/.*)?$#', $path) !== 1) {
            return [];
        }
        // A fileid in the query names a node too, whatever the path says; every node named has to pass.
        if (isset($query['fileid'])) {
            if (!is_string($query['fileid']) || !ctype_digit($query['fileid'])) {
                return [];
            }
            $references[] = ['id' => (int)$query['fileid']];
        }
        return $references;
    }

    /** @return int|null the node id behind a public share token, without following the link */
    private function shareTokenNodeId(string $token): ?int {
        try {
            return (int)$this->shareManager->getShareByToken($token)->getNodeId();
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether a host is this server: one of trusted_domains (with their wildcards), overwrite.cli.url or the URL generator's own. */
    private function isLocalHost(string $host, ?int $port): bool {
        // A trailing dot or an escaped letter still reaches this server, so neither makes a URL external.
        $host = rtrim(strtolower(trim(rawurldecode($host), '[]')), '.');
        $candidates = [$host];
        if ($port !== null) {
            $candidates[] = $host . ':' . $port;
        }
        $patterns = $this->config->getSystemValue('trusted_domains', []);
        $patterns = is_array($patterns) ? $patterns : [];
        foreach ([(string)$this->config->getSystemValue('overwrite.cli.url', ''), $this->urlGenerator->getAbsoluteURL('/')] as $own) {
            $ownHost = parse_url($own, PHP_URL_HOST);
            if (is_string($ownHost) && $ownHost !== '') {
                $ownPort = parse_url($own, PHP_URL_PORT);
                $patterns[] = is_int($ownPort) ? $ownHost . ':' . $ownPort : $ownHost;
            }
        }
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            $regex = '/^' . str_replace('\*', '[-.a-z0-9]*', preg_quote(strtolower(trim($pattern)), '/')) . '$/';
            foreach ($candidates as $candidate) {
                if (preg_match($regex, $candidate) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Every alias of the id in the user's tree must pass the scope and the exclusion, and one must be readable.
     */
    private function allowsFileId(string $userId, int $id): bool {
        if ($id <= 0) {
            return false;
        }
        try {
            $folder = $this->rootFolder->getUserFolder($userId);
            return $this->allowsNodes($userId, (int)$folder->getId() === $id ? [$folder] : $folder->getById($id), $id);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The node at a path of the user's own tree; a path of another user's DAV tree is never resolved.
     *
     * @param string|null $uid owner the DAV URL names, when it names one
     */
    private function allowsPath(string $userId, string $path, ?string $uid = null): bool {
        if ($uid !== null && $uid !== $userId) {
            return false; // another user's DAV tree is not resolvable in this user's context
        }
        try {
            $normalized = PathGuard::normalize($path);
            $folder = $this->rootFolder->getUserFolder($userId);
            $node = $normalized === '/' ? $folder : $folder->get(ltrim($normalized, '/'));
            return $this->allowsNodes($userId, [$node], null);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $nodes aliases of one node in the user's tree
     * @param int|null $id the id they must carry, when they were resolved by id
     */
    private function allowsNodes(string $userId, array $nodes, ?int $id): bool {
        if ($nodes === []) {
            return false;
        }
        $readable = false;
        foreach ($nodes as $node) {
            if (!$node instanceof Node || ($id !== null && (int)$node->getId() !== $id)) {
                return false;
            }
            if (!$this->scope->allows($userId, $node) || !$this->guard->isVisible($node)) {
                return false;
            }
            $readable = $readable || $node->isReadable();
        }
        return $readable;
    }
}
