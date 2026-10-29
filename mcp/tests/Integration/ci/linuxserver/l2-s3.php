<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use OCA\Mcp\Tests\Integration\LinuxServer\S3Inventory;
require '/fixture-probes/s3-inventory.php';
require '/fixture-probes/ProbeCompletion.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
require '/app/www/public/3rdparty/autoload.php';
$s3 = new \Aws\S3\S3Client(['version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'http://s3:8333',
    'use_path_style_endpoint' => true, 'retries' => 0, 'http' => ['connect_timeout' => 3, 'timeout' => 60],
    'credentials' => ['key' => getenv('MCP_S3_KEY'), 'secret' => getenv('MCP_S3_SECRET')]]);
$bucket = 'mcp-l1';
if (($argv[1] ?? '') === 'seed') {
    $s3->putObject(['Bucket' => $bucket, 'Key' => 'L2/empty-日本語', 'Body' => '', 'ContentType' => 'text/plain', 'Metadata' => ['fixture' => 'empty']]);
    $body = str_repeat('multipart-content', 700000);
    $stream = fopen('php://temp', 'w+'); fwrite($stream, $body); rewind($stream);
    $upload = new \Aws\S3\MultipartUploader($s3, $stream, ['bucket' => $bucket, 'key' => 'L2/multipart', 'part_size' => 5 * 1024 * 1024]);
    $upload->upload();
    $object = $s3->getObject(['Bucket' => $bucket, 'Key' => 'L2/multipart']);
    if ((string)$object['Body'] !== $body || !str_contains($object['ETag'], '-')) throw new RuntimeException('Multipart fixture failed');
} elseif (($argv[1] ?? '') === 'export') {
    if (isset($s3->getBucketVersioning(['Bucket' => $bucket])['Status'])) throw new RuntimeException('Unexpected S3 versioning');
    $directory = '/tmp/l2-export';
    if (!mkdir($directory, 0700) || !mkdir($directory . '/objects', 0700)) throw new RuntimeException('Export directory exists');
    $objects = [];
    foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
        foreach ($page['Contents'] ?? [] as $entry) {
            $key = $entry['Key'];
            $name = hash('sha256', $key);
            $path = $directory . '/objects/' . $name;
            $response = $s3->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $path]);
            if (filesize($path) !== S3Inventory::size($entry['Size'])) throw new RuntimeException('Incomplete S3 payload');
            $metadata = [];
            foreach (['ContentType', 'ContentEncoding', 'ContentLanguage', 'CacheControl', 'ContentDisposition', 'Metadata', 'Expires'] as $field) {
                if (isset($response[$field])) $metadata[$field] = $response[$field] instanceof \DateTimeInterface ? $response[$field]->format(DATE_ATOM) : $response[$field];
            }
            $tags = $s3->getObjectTagging(['Bucket' => $bucket, 'Key' => $key])['TagSet'] ?? [];
            $objects[$key] = ['payload' => 'objects/' . $name, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'etag' => $entry['ETag'], 'metadata' => $metadata, 'tags' => $tags];
        }
    }
    $after = [];
    foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) foreach ($page['Contents'] ?? [] as $entry) $after[$entry['Key']] = [S3Inventory::size($entry['Size']), $entry['ETag']];
    $before = array_map(static fn ($row) => [$row['bytes'], $row['etag']], $objects);
    ksort($after); ksort($before); ksort($objects);
    if ($before !== $after) {
        $differences = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) $differences[] = ['keyHash' => hash('sha256', $key), 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null, 'sizeTypes' => [get_debug_type($before[$key][0] ?? null), get_debug_type($after[$key][0] ?? null)]];
        }
        fwrite(STDERR, json_encode(['s3InventoryDifference' => array_slice($differences, 0, 5), 'counts' => [count($before), count($after)]], JSON_THROW_ON_ERROR) . "\n");
        throw new RuntimeException('S3 changed during capture');
    }
    file_put_contents($directory . '/s3.json', json_encode(['bucket' => $bucket, 'versioning' => 'disabled', 'objects' => $objects], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else throw new RuntimeException('Unknown S3 mode');
$completion->complete();
echo "MCP_L2_S3_COMPLETE\n";
