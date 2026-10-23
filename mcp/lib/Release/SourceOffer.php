<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Release;

use OCP\App\IAppManager;
use OCP\IConfig;

/** Matches operator-controlled publication metadata to the packaged source identity; never fetches a URL. */
final class SourceOffer {
    public function __construct(private IConfig $config, private IAppManager $apps) {}

    /** @return array{version:string, sourceCommit:string, manifestSha256:string, files:array<string,string>}|null */
    public function get(): ?array {
        try {
            $offer = $this->config->getSystemValue('mcp_source_offer', null);
            if (!is_array($offer)) { return null; }
            foreach (['version', 'sourceCommit', 'manifestSha256', 'baseUrl'] as $key) {
                if (!isset($offer[$key]) || !is_string($offer[$key])) { return null; }
            }
            if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $offer['version'])
                || !preg_match('/\A[a-f0-9]{40}\z/', $offer['sourceCommit'])
                || !preg_match('/\A[a-f0-9]{64}\z/', $offer['manifestSha256'])
                || !$this->validDestination($offer['baseUrl'])
                || $offer['version'] !== $this->apps->getAppVersion('mcp')) { return null; }
            $file = $this->apps->getAppPath('mcp') . '/appinfo/source.json';
            if (!is_file($file) || is_link($file) || !is_readable($file)) { return null; }
            $bytes = file_get_contents($file, false, null, 0, 4097);
            if ($bytes === false || strlen($bytes) > 4096) { return null; }
            $identity = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($identity) || ($identity['schemaVersion'] ?? null) !== 1
                || ($identity['version'] ?? null) !== $offer['version']
                || ($identity['sourceCommit'] ?? null) !== $offer['sourceCommit']) { return null; }
            $files = [];
            foreach (['manifest.json', 'source.bundle', 'runtime-dependencies.tar.gz', 'mcp-' . $offer['version'] . '.tar.gz', 'BUILDING.md'] as $name) {
                $files[$name] = rtrim($offer['baseUrl'], '/') . '/' . $name;
            }
            return ['version' => $offer['version'], 'sourceCommit' => $offer['sourceCommit'],
                'manifestSha256' => $offer['manifestSha256'], 'files' => $files];
        } catch (\Throwable) {
            // Source publication failure must not change authentication or advertise an unverified identity.
            return null;
        }
    }

    private function validDestination(string $url): bool {
        if (strlen($url) > 2048 || !preg_match('#\Ahttps?://[A-Za-z0-9.\[\]:-]+(?:/[A-Za-z0-9_~.\-/]*)?\z#D', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !isset($parts['host'], $parts['scheme']) || isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) { return false; }
        if ($parts['scheme'] !== 'https' && !in_array($parts['host'], ['127.0.0.1', '[::1]'], true)) { return false; }
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if ($segment === '.' || $segment === '..') { return false; }
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
