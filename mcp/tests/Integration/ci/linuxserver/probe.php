<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require '/fixture-probes/ProbeCompletion.php';
$stage = 'bootstrap';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
try {
$mode = $argv[1] ?? '';
if ($mode === 'incomplete') exit(0);
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
if ($mode === 'missing-env') {
    putenv('MCP_S3_SECRET=');
    require '/config/www/nextcloud/config/fixture.config.php';
    throw new RuntimeException('Missing environment was accepted');
}
if (str_starts_with($mode, 's3-')) {
    require '/app/www/public/3rdparty/autoload.php';
    $client = new \Aws\S3\S3Client([
        'version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'http://s3:8333',
        'use_path_style_endpoint' => true, 'retries' => 0, 'http' => ['connect_timeout' => 3, 'timeout' => 10],
        'credentials' => ['key' => getenv('MCP_S3_KEY'), 'secret' => getenv('MCP_S3_SECRET')],
    ]);
    $bucket = 'mcp-l1';
    if ($mode === 's3-create') {
        $client->createBucket(['Bucket' => $bucket]);
        check(!isset($client->getBucketVersioning(['Bucket' => $bucket])['Status']), 'Bucket must be unversioned');
        $client->putObject(['Bucket' => $bucket, 'Key' => 'l1-health', 'Body' => 'L1 persistence']);
        check(count($client->listObjectsV2(['Bucket' => $bucket])['Contents']) === 1, 'S3 listing mismatch');
    } else {
        check((string)$client->getObject(['Bucket' => $bucket, 'Key' => 'l1-health'])['Body'] === 'L1 persistence', 'S3 persistence failed');
        if ($mode === 's3-delete') {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => 'l1-health']);
            check(!$client->doesObjectExistV2($bucket, 'l1-health'), 'S3 deletion failed');
        }
    }
} else {
    define('OC_CONSOLE', true);
    require '/app/www/public/lib/base.php';
    check(posix_geteuid() === 1000, 'Probe must execute as abc');
    $config = \OCP\Server::get(\OCP\IConfig::class);
    $db = \OCP\Server::get(\OCP\IDBConnection::class);
    $sqlVersion = $db->executeQuery('SHOW server_version')->fetchOne();
    check(str_starts_with((string)$sqlVersion, '16.15'), 'PostgreSQL version mismatch');
    $appPath = $mode === 'wrong-path' ? '/wrong/apps/mcp' : '/app/www/public/apps/mcp';
    check(realpath($appPath) === '/config/www/nextcloud/apps/mcp', 'Persistent app path mismatch');
    check(fileowner($appPath) === 1000 && (fileperms($appPath) & 0002) === 0, 'Unsafe app ownership/mode');
    check(implode('.', array_slice(\OCP\Util::getVersion(), 0, 3)) === '35.0.2', 'Nextcloud version mismatch');
    check($config->getSystemValue('appstoreenabled', true) === false, 'App store must be disabled');
    check($config->getSystemValue('objectstore')['class'] === '\\OC\\Files\\ObjectStore\\S3', 'Primary S3 is not configured');
    $apps = json_decode(file_get_contents('/fixture-probes/apps.json'), true, 512, JSON_THROW_ON_ERROR);
    $manager = \OCP\Server::get(\OCP\App\IAppManager::class);
    foreach ($apps as $id => $version) {
        check($manager->isEnabledForUser($id), 'Pinned app is not enabled: ' . $id);
        check($config->getAppValue($id, 'installed_version') === $version, 'Pinned app version mismatch: ' . $id);
    }
    foreach (['curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_pgsql', 'zip'] as $extension) {
        check(extension_loaded($extension), 'CLI extension missing: ' . $extension);
    }
    $folder = \OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l1-admin');
    if ($mode === 'context') $folder->newFile('l1-storage-health.txt', 'L1 primary S3');
    $stage = 'primary-s3-read';
    check($folder->get('l1-storage-health.txt')->getContent() === 'L1 primary S3', 'Nextcloud primary S3 read failed');
    if ($mode === 'context') file_put_contents('/data/l1-local-health', 'L1 local data');
    check(file_get_contents('/data/l1-local-health') === 'L1 local data', 'Local data persistence failed');
    echo json_encode(['phpCli' => PHP_VERSION, 'postgres' => $sqlVersion, 'mcp' => $config->getAppValue('mcp', 'installed_version'), 'path' => realpath($appPath)], JSON_THROW_ON_ERROR) . "\n";
}
$completion->complete();
echo 'MCP_L1_COMPLETE=' . json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR) . "\n";

} catch (\Throwable $error) {
    $expected = match ($mode) {
        'missing-env' => $error->getMessage() === 'Missing fixture object-store environment',
        'wrong-path' => $error->getMessage() === 'Persistent app path mismatch',
        'unavailable-db' => $error instanceof \Doctrine\DBAL\Exception && str_contains($error->getMessage(), 'connect'),
        'unavailable-s3' => $stage === 'primary-s3-read' && $error instanceof \OCP\Files\GenericFileException,
        default => false,
    };
    if ($expected) echo 'MCP_L1_REJECTED=' . json_encode(['reason' => $mode], JSON_THROW_ON_ERROR) . "\n";
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
