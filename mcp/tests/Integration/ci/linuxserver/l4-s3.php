<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require '/fixture-probes/ProbeCompletion.php';
$completion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
require '/app/www/src/3rdparty/autoload.php';
$s3 = new \Aws\S3\S3Client(['version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'http://s3:8333',
    'use_path_style_endpoint' => true, 'retries' => 0, 'http' => ['connect_timeout' => 3, 'timeout' => 20],
    'credentials' => ['key' => getenv('MCP_S3_KEY'), 'secret' => getenv('MCP_S3_SECRET')]]);
$index = json_decode(file_get_contents('/tmp/restore-objects/s3.json'), true, 512, JSON_THROW_ON_ERROR);
$bucket = $index['bucket'];
if (count($s3->listBuckets()['Buckets'] ?? []) !== 0) throw new RuntimeException('Restore store is not empty');
$s3->createBucket(['Bucket' => $bucket]);
if (isset($s3->getBucketVersioning(['Bucket' => $bucket])['Status'])) throw new RuntimeException('Unexpected S3 versioning');
foreach ($index['objects'] as $key => $row) {
    $file = '/tmp/restore-objects/' . $row['payload'];
    if (!is_file($file) || filesize($file) !== $row['bytes'] || hash_file('sha256', $file) !== $row['sha256']) throw new RuntimeException('Missing or corrupt restore object');
    $args = ['Bucket' => $bucket, 'Key' => $key] + $row['metadata'];
    if (str_contains($row['etag'], '-')) {
        $upload = new \Aws\S3\MultipartUploader($s3, $file, ['bucket' => $bucket, 'key' => $key, 'part_size' => 5 * 1024 * 1024,
            'before_initiate' => static function (\Aws\CommandInterface $command) use ($args): void { foreach ($args as $name => $value) $command[$name] = $value; }]);
        $upload->upload();
    } else $s3->putObject($args + ['SourceFile' => $file]);
    if ($row['tags']) $s3->putObjectTagging(['Bucket' => $bucket, 'Key' => $key, 'Tagging' => ['TagSet' => $row['tags']]]);
}
$keys = []; $etagChanges = 0; $bytes = 0;
foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) foreach ($page['Contents'] ?? [] as $entry) $keys[] = $entry['Key'];
$expected = array_keys($index['objects']); sort($expected); sort($keys);
if ($expected !== $keys) throw new RuntimeException('Restored S3 inventory differs');
foreach ($index['objects'] as $key => $row) {
    $response = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]); $body = (string)$response['Body'];
    if (strlen($body) !== $row['bytes'] || hash('sha256', $body) !== $row['sha256']) throw new RuntimeException('Restored object bytes differ');
    $metadata = [];
    foreach (['ContentType', 'ContentEncoding', 'ContentLanguage', 'CacheControl', 'ContentDisposition', 'Metadata', 'Expires'] as $field) {
        if (isset($response[$field])) $metadata[$field] = $response[$field] instanceof \DateTimeInterface ? $response[$field]->format(DATE_ATOM) : $response[$field];
    }
    if ($metadata !== $row['metadata'] || ($s3->getObjectTagging(['Bucket' => $bucket, 'Key' => $key])['TagSet'] ?? []) !== $row['tags']) throw new RuntimeException('Restored object metadata differs');
    $etagChanges += $response['ETag'] !== $row['etag'] ? 1 : 0; $bytes += strlen($body);
}
$completion->complete();
echo 'MCP_L4_S3=' . json_encode(['objects' => count($keys), 'bytes' => $bytes, 'etagChanges' => $etagChanges, 'metadataAndTags' => 'matched'], JSON_THROW_ON_ERROR) . "\n";
