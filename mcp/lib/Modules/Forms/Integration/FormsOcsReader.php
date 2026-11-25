<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\FormsNativeReader;
use OCA\Mcp\Modules\Forms\FormsReader;
use OCA\Mcp\Modules\Forms\FormsResponseReader;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

/** Reads official Forms v3 over the current Basic credential when explicitly enabled. */
final class FormsOcsReader implements FormsReader, FormsResponseReader {
	private const CONFIG_KEY = 'app.mcp.official-apps.forms';
	private const MAX_RESPONSE_BYTES = 1_048_576;
	private const REQUEST_TIMEOUT_SECONDS = 5;

	public function __construct(
		private IConfig $config,
		private IRequest $request,
		private IURLGenerator $urls,
		private IClientService $clients,
		private IAppManager $apps,
		private IUserManager $users,
		private IUserSession $session,
		private FormsNativeReader $native,
	) {
	}

	public function list(string $userId): array {
		if (!$this->useOcs()) {
			return $this->native->list($userId);
		}
		$this->assertCurrentActor($userId);

		try {
			$this->assertOcsActor($userId);
			$forms = [];
			foreach (['owned', 'shared'] as $type) {
				$data = $this->data('apps/forms/api/v3/forms', ['type' => $type]);
				if (!is_array($data) || !array_is_list($data)) {
					throw new \RuntimeException('Invalid Forms list response.');
				}
				foreach ($data as $form) {
					if (!is_array($form) || !is_int($form['id'] ?? null) || $form['id'] < 1
						|| !is_string($form['title'] ?? null) || !is_array($form['permissions'] ?? null)) {
						throw new \RuntimeException('Invalid Forms summary.');
					}
					$forms[$form['id']] = [
						'id' => $form['id'],
						'title' => $form['title'],
						'permissions' => array_values(array_filter($form['permissions'], 'is_string')),
					];
				}
			}
			return array_values($forms);
		} catch (ToolFailure $failure) {
			throw $failure;
		} catch (\Throwable) {
			throw new ToolFailure('The Forms resource or capability is unavailable.');
		}
	}

	public function read(string $userId, int $formId): ?array {
		if (!$this->useOcs()) {
			return $this->native->read($userId, $formId);
		}
		$this->assertCurrentActor($userId);
		if ($formId < 1) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}

		try {
			$this->assertOcsActor($userId);
			$data = $this->data('apps/forms/api/v3/forms/' . $formId);
			if ($data === null) {
				return null;
			}
			if (!is_array($data) || !is_int($data['id'] ?? null) || $data['id'] !== $formId) {
				throw new \RuntimeException('Invalid Forms detail response.');
			}
			return $data;
		} catch (ToolFailure $failure) {
			throw $failure;
		} catch (\Throwable) {
			throw new ToolFailure('The Forms resource or capability is unavailable.');
		}
	}

	public function readResponses(string $userId, int $formId, int $offset, int $limit): ?array {
		if (!$this->useOcs()) {
			return $this->native->readResponses($userId, $formId, $offset, $limit);
		}
		$this->assertCurrentActor($userId);
		if ($formId < 1 || $offset < 0) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		$limit = min(max(1, $limit), 100);

		try {
			$this->assertOcsActor($userId);
			$form = $this->data('apps/forms/api/v3/forms/' . $formId);
			if ($form === null || !is_array($form) || !is_int($form['id'] ?? null) || $form['id'] !== $formId) {
				return null;
			}
			if (!$this->hasResultsPermission($form)) {
				return null;
			}
			if (!is_bool($form['isAnonymous'] ?? null)) {
				throw new \RuntimeException('Forms anonymity state is unavailable.');
			}

			$page = $this->data('apps/forms/api/v3/forms/' . $formId . '/submissions', ['limit' => $limit, 'offset' => $offset]);
			if (!is_array($page) || !is_array($page['submissions'] ?? null) || !array_is_list($page['submissions'])
				|| !is_array($page['questions'] ?? null) || !array_is_list($page['questions'])
				|| !is_int($page['filteredSubmissionsCount'] ?? null)
				|| $page['filteredSubmissionsCount'] < 0
				|| (count($page['submissions']) > 0 && $page['filteredSubmissionsCount'] < $offset + count($page['submissions']))) {
				throw new \RuntimeException('Invalid Forms response page.');
			}

			// Forms may retain answers to deleted questions. Drop any containing unclassified answers so a
			// historical file name or identity cannot cross the reader boundary by accident.
			$types = [];
			$questions = [];
			$fileQuestionIds = [];
			$questionsTruncated = false;
			foreach ($page['questions'] as $question) {
				if (!is_array($question) || !is_int($question['id'] ?? null) || !is_string($question['type'] ?? null)) {
					continue;
				}
				$types[$question['id']] = $question['type'];
				if ($question['type'] === 'file') {
					$fileQuestionIds[] = $question['id'];
				}
				if (count($questions) < 50) {
					$metadata = ['id' => $question['id'], 'type' => $question['type']];
					if (is_string($question['text'] ?? null)) {
						$metadata['text'] = $question['text'];
					}
					$questions[] = $metadata;
				} else {
					$questionsTruncated = true;
				}
			}

			$submissions = [];
			$unclassifiedOmitted = 0;
			foreach ($page['submissions'] as $submission) {
				if (!is_array($submission) || !is_int($submission['id'] ?? null)
					|| ($submission['formId'] ?? null) !== $formId || !is_int($submission['timestamp'] ?? null)
					|| !is_array($submission['answers'] ?? null)) {
					$unclassifiedOmitted++;
					continue;
				}
				$classified = true;
				foreach (array_slice($submission['answers'], 0, 200) as $answer) {
					if (!is_array($answer) || !is_int($answer['questionId'] ?? null) || !isset($types[$answer['questionId']])) {
						$classified = false;
						break;
					}
				}
				if ($classified) {
					$submissions[] = $submission;
				} else {
					$unclassifiedOmitted++;
				}
			}

			// Recheck native ACL after the page read; a mid-request revocation discards the entire page.
			$after = $this->data('apps/forms/api/v3/forms/' . $formId);
			if (!is_array($after) || ($after['id'] ?? null) !== $formId
				|| ($after['isAnonymous'] ?? null) !== $form['isAnonymous'] || !$this->hasResultsPermission($after)) {
				throw new ToolFailure('The Forms resource or capability is unavailable.');
			}

			$scanned = count($page['submissions']);
			return [
				'formId' => $formId,
				'isAnonymous' => $form['isAnonymous'],
				'fileQuestionIds' => $fileQuestionIds,
				'questions' => $questions,
				'questionsTruncated' => $questionsTruncated,
				'submissions' => $submissions,
				'_sourceScanned' => $scanned,
				'_sourceHasMore' => $page['filteredSubmissionsCount'] > $offset + $scanned,
				'_unclassifiedOmitted' => $unclassifiedOmitted,
			];
		} catch (ToolFailure $failure) {
			throw $failure;
		} catch (\Throwable) {
			throw new ToolFailure('The Forms resource or capability is unavailable.');
		}
	}

	private function useOcs(): bool {
		if (!$this->config->getSystemValueBool(self::CONFIG_KEY, false)) {
			return false;
		}
		return preg_match('/^Basic[ \\t]+/i', $this->request->getHeader('Authorization')) === 1;
	}

	private function assertCurrentActor(string $userId): void {
		$user = $this->users->get($userId);
		if ($user === null || !$user->isEnabled() || $this->session->getUser()?->getUID() !== $userId
			|| !$this->apps->isEnabledForUser('forms', $user)) {
			throw new ToolFailure(ToolFailure::FORBIDDEN);
		}
	}

	private function assertOcsActor(string $userId): void {
		$actor = $this->data('cloud/user');
		if (!is_array($actor) || !is_string($actor['id'] ?? null) || !hash_equals($userId, $actor['id'])) {
			throw new ToolFailure('The Forms resource or capability is unavailable.');
		}
	}

	private function hasResultsPermission(array $form): bool {
		return is_array($form['permissions'] ?? null)
			&& in_array('results', $form['permissions'], true);
	}

	/** @param array<string, scalar> $query @return array<mixed>|null */
	private function data(string $path, array $query = []): ?array {
		$headers = [
			'Authorization' => $this->request->getHeader('Authorization'),
			'OCS-APIRequest' => 'true',
			'Accept' => 'application/json',
		];
		$query['format'] = 'json';
		$url = $this->url('ocs/v2.php/' . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
		$client = $this->clients->newClient();
		$response = $client->get($url, [
			'headers' => $headers,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
			'connect_timeout' => 2,
			'verify' => true,
			'allow_redirects' => false,
			'http_errors' => false,
			'stream' => true,
		]);
		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('The OCS request did not succeed.');
		}
		$body = $response->getBody();
		if (is_resource($body)) {
			try {
				$raw = stream_get_contents($body, self::MAX_RESPONSE_BYTES + 1);
			} finally {
				if (is_resource($body)) {
					fclose($body);
				}
			}
		} else {
			$raw = is_string($body) ? $body : '';
		}
		if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_RESPONSE_BYTES) {
			throw new \RuntimeException('The OCS response is empty or too large.');
		}
		$decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		$meta = $decoded['ocs']['meta'] ?? null;
		if (!is_array($meta) || !is_int($meta['statuscode'] ?? null)) {
			throw new \RuntimeException('Invalid OCS response envelope.');
		}
		if ($meta['statuscode'] === 403 || $meta['statuscode'] === 404) {
			return null;
		}
		if (!in_array($meta['statuscode'], [100, 200], true) || ($meta['status'] ?? null) !== 'ok') {
			throw new \RuntimeException('OCS reported a failed request.');
		}
		$data = $decoded['ocs']['data'] ?? null;
		return is_array($data) ? $data : null;
	}

	private function url(string $path): string {
		$canonical = $this->config->getSystemValueString('overwrite.cli.url', '');
		$canonicalParts = parse_url($canonical);
		$url = $this->urls->getAbsoluteURL($path);
		$urlParts = parse_url($url);
		if (!is_array($canonicalParts) || !is_array($urlParts)
			|| isset($canonicalParts['user']) || isset($canonicalParts['pass']) || isset($canonicalParts['query']) || isset($canonicalParts['fragment'])
			|| isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['fragment'])
			|| strtolower($canonicalParts['scheme'] ?? '') !== strtolower($urlParts['scheme'] ?? '')
			|| strtolower($canonicalParts['host'] ?? '') !== strtolower($urlParts['host'] ?? '')
			|| $this->port($canonicalParts) !== $this->port($urlParts)
			|| !$this->matchingInstancePath($canonicalParts, $urlParts, $path)
			|| !$this->secureTransport($canonicalParts)) {
			throw new \RuntimeException('The configured Nextcloud origin is invalid.');
		}
		return $url;
	}

	private function port(array $parts): ?int {
		if (isset($parts['port'])) {
			return (int)$parts['port'];
		}
		return strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80;
	}

	private function matchingInstancePath(array $canonical, array $target, string $relative): bool {
		$relativeParts = parse_url($relative);
		if (!is_array($relativeParts) || !is_string($relativeParts['path'] ?? null)
			|| ($target['query'] ?? '') !== ($relativeParts['query'] ?? '')) {
			return false;
		}
		$base = rtrim((string)($canonical['path'] ?? ''), '/');
		if (str_ends_with($base, '/index.php')) {
			$base = substr($base, 0, -strlen('/index.php'));
		}
		$relativePath = $relativeParts['path'];
		$expected = rtrim($base, '/') . '/' . ltrim($relativePath, '/');
		$actual = rtrim((string)($target['path'] ?? ''), '/');
		return $actual === $expected || $actual === rtrim($base, '/') . '/index.php/' . ltrim($relativePath, '/');
	}

	private function secureTransport(array $parts): bool {
		$scheme = strtolower($parts['scheme'] ?? '');
		if ($scheme === 'https') {
			return true;
		}
		if ($scheme !== 'http' || !is_string($parts['host'] ?? null)) {
			return false;
		}
		$host = strtolower(trim($parts['host'], '[]'));
		if ($host === 'localhost') {
			return true;
		}
		$packed = inet_pton($host);
		return is_string($packed) && (($packed[0] ?? '') === "\x7f" || $packed === inet_pton('::1'));
	}
}
