<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;

require __DIR__ . '/../../../vendor/autoload.php';

$archive = $argv[1] ?? '';
$pin = json_decode(file_get_contents(__DIR__ . '/../../Integration/ci/official-apps-pins.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_file($archive) || is_link($archive) || hash_file('sha256', $archive) !== $pin['forms_sha256']) {
	throw new RuntimeException('Provide the pinned official Forms release archive.');
}

$controllers = [
	'ApiController' => [
		'getForm', 'getForms', 'getQuestion', 'getSubmission', 'newForm', 'updateForm', 'deleteForm',
		'newQuestion', 'updateQuestion', 'deleteQuestion', 'reorderQuestions',
		'newOption', 'updateOption', 'deleteOption', 'reorderOptions',
	],
	'ShareApiController' => ['newShare', 'deleteShare'],
];
$tree = sys_get_temp_dir() . '/mcp-forms-ocs-' . bin2hex(random_bytes(8));
$directory = $tree . '/apps/forms/lib/Controller';
mkdir($directory, 0700, true);
$parser = new SourceParser($tree);
$surfaces = [];

try {
	$release = new PharData($archive);
	foreach ($controllers as $name => $methods) {
		$class = 'OCA\\Forms\\Controller\\' . $name;
		$source = $directory . '/' . $name . '.php';
		$controller = $release['forms/lib/Controller/' . $name . '.php']->getContent();
		if (file_put_contents($source, $controller) !== strlen($controller)) {
			throw new RuntimeException('Could not stage the pinned Forms controller.');
		}
		$surface = $parser->surface($class) ?? throw new RuntimeException('The Forms OCS controller is absent.');
		$surface['methods'] = array_intersect_key($surface['methods'], array_flip($methods));
		$surface['types'] = array_intersect_key($surface['types'], array_flip($methods));
		if (count($surface['methods']) !== count($methods)) {
			throw new RuntimeException('An admitted Forms OCS action is absent.');
		}
		$surfaces[$class] = $surface;
	}

	$origin = ['version' => $pin['forms_version'], 'sha256' => $pin['forms_sha256']];
	$path = __DIR__ . '/forms-read.json';
	$forms = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$forms['classes'] = array_replace($forms['classes'], $surfaces);
	$forms['officialOcsController'] = $origin;
	$json = json_encode($forms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	$json = preg_replace_callback('/^ +/m', static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)), $json);
	file_put_contents($path, $json . "\n");

	foreach ([31, 32, 33, 34, 35] as $major) {
		$path = __DIR__ . '/' . $major . '.php';
		$fixture = require $path;
		$fixture['classes'] = array_replace($fixture['classes'], $surfaces);
		ksort($fixture['classes']);
		$fixture['formsSources']['forms']['officialOcsController'] = $origin;
		$header = explode('return [', file_get_contents($path), 2)[0];
		FixtureFile::write($path, $header, $fixture);
	}
	echo "Captured the Forms OCS action signatures from the pinned official release.\n";
} finally {
	foreach (array_keys($controllers) as $name) {
		$source = $directory . '/' . $name . '.php';
		if (is_file($source)) {
			unlink($source);
		}
	}
	foreach (['/apps/forms/lib/Controller', '/apps/forms/lib', '/apps/forms', '/apps', ''] as $part) {
		rmdir($tree . $part);
	}
}
