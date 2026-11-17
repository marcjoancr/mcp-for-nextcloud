<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\Server;

/** Runs the one-shot Home initializer in a clean PHP request lifetime. */
final class NativeHomeProcess {
    private const OUTPUT_LIMIT = 16384;
    private const TIME_LIMIT = 12.0;
    private const FAILURE_CAUSE_LIMIT = 3;
    private const FAILURE_CLASSES = ['BeforeEffect','RuntimeException','LogicException','InvalidArgumentException',
        'TypeError','ErrorException','Error','Exception','Throwable'];
    private static ?array $lastFailure = null;

    /** Child diagnostics contain only bounded, allowlisted metadata, never exception text or arguments. */
    public static function failureReceipt(?\Throwable $error, ?array $envelope): ?array {
        $scope = self::failureScope($envelope);
        if ($error === null || $scope === null) { return null; }
        $failure = self::failureLocation($error);
        $causes = [];
        for ($cause = $error->getPrevious(), $index = 0;
            $cause !== null && $index < self::FAILURE_CAUSE_LIMIT;
            $cause = $cause->getPrevious(), $index++) {
            $causes[] = self::failureLocation($cause);
        }
        $failure['stderr_bytes'] = 0;
        $failure['causes'] = $causes;
        return ['protocol'=>1,'ok'=>false,'scope'=>$scope,
            'failure'=>$failure];
    }

    /** @return array{class:string,file:string,line:int} */
    private static function failureLocation(\Throwable $error): array {
        $class = (new \ReflectionClass($error))->getShortName();
        if (!in_array($class, self::FAILURE_CLASSES, true)) { $class = 'Throwable'; }
        $file = basename($error->getFile());
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $file)) { $file = 'unknown'; }
        $line = $error->getLine();
        if ($line < 0 || $line > 10000000) { $line = 0; }
        return ['class'=>$class,'file'=>$file,'line'=>$line];
    }

    /** Consume only a child diagnostic already matched to this exact one-shot job claim. */
    public static function takeFailure(array $envelope): ?array {
        $failure = self::$lastFailure;
        self::$lastFailure = null;
        if (!is_array($failure) || ($failure['scope'] ?? null) !== self::failureScope($envelope)) { return null; }
        return $failure;
    }

    /** Validate the closed failure receipt and bind it to the parent-held job nonce. */
    public static function validateFailureReceipt(mixed $receipt, array $envelope, int $stderrBytes): ?array {
        if (!is_array($receipt) || array_keys($receipt) !== ['protocol','ok','scope','failure']
            || ($receipt['protocol'] ?? null) !== 1 || ($receipt['ok'] ?? null) !== false
            || ($receipt['scope'] ?? null) !== self::failureScope($envelope)
            || !is_array($receipt['failure'])
            || array_keys($receipt['failure']) !== ['class','file','line','stderr_bytes','causes']) { return null; }
        $failure = $receipt['failure'];
        if (!in_array($failure['class'] ?? null, self::FAILURE_CLASSES, true)
            || !is_string($failure['file'] ?? null) || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $failure['file'])
            || !is_int($failure['line'] ?? null) || $failure['line'] < 0 || $failure['line'] > 10000000
            || !is_int($failure['stderr_bytes'] ?? null) || $failure['stderr_bytes'] !== 0
            || !is_array($failure['causes']) || !array_is_list($failure['causes'])
            || count($failure['causes']) > self::FAILURE_CAUSE_LIMIT
            || $stderrBytes < 0 || $stderrBytes > self::OUTPUT_LIMIT) { return null; }
        foreach ($failure['causes'] as $cause) {
            if (!is_array($cause) || array_keys($cause) !== ['class','file','line']
                || !in_array($cause['class'] ?? null, self::FAILURE_CLASSES, true)
                || !is_string($cause['file'] ?? null) || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $cause['file'])
                || !is_int($cause['line'] ?? null) || $cause['line'] < 0 || $cause['line'] > 10000000) { return null; }
        }
        $failure['stderr_bytes'] = $stderrBytes;
        return ['protocol'=>1,'ok'=>false,'scope'=>$receipt['scope'],'failure'=>$failure];
    }

    private static function failureScope(?array $envelope): ?array {
        $phase = $envelope['home_phase'] ?? null;
        $jobId = $envelope['job_id'] ?? null;
        $nonce = is_array($phase) ? ($phase['nonce'] ?? null) : null;
        $name = is_array($phase) ? ($phase['phase'] ?? null) : null;
        if (!is_string($jobId) || !preg_match('/^[a-f0-9]{64}$/D', $jobId)
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/D', $nonce)
            || !in_array($name, ['copy_files','stage_shares','stage_recipients','apply','restore','restore_files'], true)) {
            return null;
        }
        return ['job_id'=>$jobId,'nonce'=>$nonce,'phase'=>$name];
    }

    /** Factory wrapper for the owned isolated Home setup process. */
    public static function wrapperClosure(string $targetUid, NativeHomeJournal $journal, \Closure $authority): \Closure {
        return static function (string $mountPoint, IStorage $storage, IMountPoint $mount) use ($targetUid, $journal, $authority): IStorage {
            try { return new NativeHomeStorage($storage, $mountPoint, $targetUid, $journal, $authority); }
            catch (\Throwable $error) { NativeHomeStorage::latchFailure($error); throw $error; }
        };
    }

    /** Create the durable, private receipt file in the already-established Forms recovery directory. */
    public static function createJournalFile(): array {
        $config = Server::get(IConfig::class);
        $data = $config->getSystemValue('datadirectory', '');
        $instance = $config->getSystemValueString('instanceid');
        if (!is_string($data) || $data === '' || $data[0] !== '/' || str_contains($data, "\0")
            || !preg_match('/^[A-Za-z0-9]+$/D', $instance)) {
            throw new \RuntimeException('Native Home recovery directory is unavailable');
        }
        $directory = rtrim($data, '/') . '/appdata_' . $instance . '/forms_mcp_bulk_recovery';
        $real = realpath($directory);
        $stat = @lstat($directory);
        if (!is_string($real) || $real !== $directory || !is_array($stat) || is_link($directory)
            || ($stat['mode'] & 0170000) !== 0040000 || !is_writable($directory)
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new \RuntimeException('Native Home recovery directory is unavailable');
        }
        $path = tempnam($directory, '.native-home-');
        if (!is_string($path) || dirname($path) !== $directory || is_link($path) || !chmod($path, 0600)) {
            if (is_string($path) && is_file($path) && !is_link($path)) { @unlink($path); }
            throw new \RuntimeException('Native Home recovery stream is unavailable');
        }
        $stream = fopen($path, 'r+b');
        $file = @lstat($path);
        if (!is_resource($stream) || !is_array($file) || is_link($path) || ($file['mode'] & 0170000) !== 0100000
            || ($file['mode'] & 0777) !== 0600 || $file['nlink'] !== 1
            || (function_exists('posix_geteuid') && $file['uid'] !== posix_geteuid())) {
            if (is_resource($stream)) { fclose($stream); }
            @unlink($path);
            throw new \RuntimeException('Native Home recovery stream is unavailable');
        }
        return ['path' => $path, 'stream' => $stream];
    }

    /**
     * @param resource $journal Empty, owner-only, single-link stream inherited as FD 3.
     * @return array{protocol:int,job_id:string,phase:string,target_uid:string,initialized:bool,journal:array}
     */
    public static function run(array $envelope, string $script, string $serverRoot, mixed $journal): array {
        self::$lastFailure = null;
        if (!is_resource($journal) || !is_file($script) || is_link($script)
            || !str_starts_with($script, rtrim($serverRoot, '/') . '/')
            || !is_dir($serverRoot) || is_link($serverRoot)) {
            throw new \RuntimeException('Native Home helper is unavailable');
        }
        $stat = fstat($journal);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || $stat['nlink'] !== 1 || $stat['size'] !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new \RuntimeException('Native Home journal stream is unavailable');
        }

        $command = [PHP_BINARY, $script, $serverRoot];
        $environment = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'PHP_MEMORY_LIMIT' => '512M',
            'PHP_UPLOAD_LIMIT' => '512M', 'PHP_OPCACHE_MEMORY_CONSUMPTION' => '128'];
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => $journal], $pipes, $serverRoot, $environment, ['bypass_shell' => true]);
        if (!is_resource($process) || count($pipes) !== 3) {
            throw new \RuntimeException('Native Home helper could not start');
        }

        $stdout = '';
        $stderrBytes = 0;
        $deadline = microtime(true) + self::TIME_LIMIT;
        $exit = null;
        try {
            foreach ([0, 1, 2] as $index) { stream_set_blocking($pipes[$index], false); }
            $input = json_encode($envelope, JSON_THROW_ON_ERROR);
            $offset = 0;
            while ($offset < strlen($input)) {
                $written = @fwrite($pipes[0], substr($input, $offset));
                if ($written === false || $written === 0) {
                    if (microtime(true) >= $deadline) { throw new \RuntimeException('Native Home helper timed out'); }
                    usleep(1000);
                    continue;
                }
                $offset += $written;
            }
            fclose($pipes[0]);
            while (true) {
                $read = [];
                foreach ([1, 2] as $index) { if (!feof($pipes[$index])) { $read[] = $pipes[$index]; } }
                if ($read !== []) {
                    $write = $except = [];
                    @stream_select($read, $write, $except, 0, 100000);
                    foreach ($read as $pipe) {
                        $chunk = fread($pipe, 4096);
                        if ($chunk === false) { throw new \RuntimeException('Native Home helper output is unavailable'); }
                        if ($pipe === $pipes[1]) { $stdout .= $chunk; }
                        else { $stderrBytes += strlen($chunk); }
                        if (strlen($stdout) > self::OUTPUT_LIMIT || $stderrBytes > self::OUTPUT_LIMIT) {
                            throw new \RuntimeException('Native Home helper output exceeded budget');
                        }
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Native Home helper timed out'); }
            }
        } catch (\Throwable $error) {
            @proc_terminate($process, 9);
            throw $error;
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $closed = proc_close($process);
            if ($exit === null) { $exit = $closed; }
        }

        try { $receipt = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw new \RuntimeException('Native Home helper receipt is unavailable', 0, $error); }
        if ($exit !== 0) {
            self::$lastFailure = self::validateFailureReceipt($receipt, $envelope, $stderrBytes);
            throw new \RuntimeException('Native Home helper failed');
        }
        if (!is_array($receipt) || array_keys($receipt) !== ['protocol','ok','job_id','phase','target_uid','initialized','journal']
            || ($receipt['protocol'] ?? null) !== 1 || ($receipt['ok'] ?? null) !== true
            || ($receipt['job_id'] ?? null) !== ($envelope['job_id'] ?? null)
            || ($receipt['phase'] ?? null) !== ($envelope['home_phase']['phase'] ?? null)
            || ($receipt['target_uid'] ?? null) !== ($envelope['target_uid'] ?? null)
            || ($receipt['initialized'] ?? null) !== true || !is_array($receipt['journal'] ?? null)
            || array_keys($receipt['journal']) !== ['state','effects','chain','bytes']) {
            throw new \RuntimeException('Native Home helper receipt binding differs');
        }
        $journalReceipt = NativeHomeJournal::read($journal, $envelope);
        if (($journalReceipt['state'] ?? null) !== 'committed' || !$journalReceipt['sealed'] || $journalReceipt['pending'] !== [] || $journalReceipt['failed']) {
            throw new \RuntimeException('Native Home journal requires recovery');
        }
        foreach (['state','effects','chain','bytes'] as $field) {
            if (($receipt['journal'][$field] ?? null) !== ($journalReceipt[$field] ?? null)) {
                throw new \RuntimeException('Native Home helper journal receipt differs');
            }
        }
        return ['protocol' => 1, 'job_id' => $envelope['job_id'], 'phase' => $envelope['home_phase']['phase'],
            'target_uid' => $envelope['target_uid'], 'initialized'=>true, 'journal' => $journalReceipt];
    }
}
