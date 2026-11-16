<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use OCA\Forms\Mcp\Service\NativeHomeJournal;
use OCA\Forms\Mcp\Service\NativeHomeProcess;
use PHPUnit\Framework\TestCase;

final class NativeHomeProcessTest extends TestCase {
    private string $root;
    private string $script;

    protected function setUp(): void {
        require_once __DIR__ . '/../src/Service/NativeHomeJournal.php';
        require_once __DIR__ . '/../src/Service/NativeHomeProcess.php';
        $this->root = sys_get_temp_dir() . '/native-home-process-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/lib', 0700);
        file_put_contents($this->root . '/lib/base.php', "<?php\n");
        $journalSource = var_export(__DIR__ . '/../src/Service/NativeHomeJournal.php', true);
        $this->script = $this->root . '/worker.php';
        file_put_contents($this->script, "<?php\ndeclare(strict_types=1);\nrequire_once {$journalSource};\n$" . "input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);\n$" . "stream=fopen('php://fd/3','r+');\n$" . "journal=\\OCA\\Forms\\Mcp\\Service\\NativeHomeJournal::open($" . "stream,$" . "input);\n$" . "token=$" . "journal->intent('mkdir','files');\n$" . "journal->complete($" . "token,str_repeat('a',64));\n$" . "journal->seal('committed');\necho json_encode(['protocol'=>1,'ok'=>true,'job_id'=>$" . "input['job_id'],'phase'=>$" . "input['home_phase']['phase'],'target_uid'=>$" . "input['target_uid'],'dto'=>['state'=>'pending']]);\n");
    }

    protected function tearDown(): void {
        @unlink($this->script);
        @unlink($this->root . '/failure-worker.php');
        @unlink($this->root . '/worker.php');
        @unlink($this->root . '/Service/NativeHomeProcess.php');
        @rmdir($this->root . '/Service');
        @unlink($this->root . '/lib/base.php');
        @rmdir($this->root . '/lib');
        @rmdir($this->root);
    }

    public function testFreshChildUsesInheritedJournalAndReturnsBoundReceipt(): void {
        $journalPath = $this->root . '/receipt.jsonl';
        $journal = fopen($journalPath, 'x+b');
        chmod($journalPath, 0600);
        $envelope = ['protocol'=>1,'job_id'=>str_repeat('1',64),'target_uid'=>'recipient','files_path'=>'/recipient/files',
            'home_phase'=>['nonce'=>str_repeat('2',64),'phase'=>'copy_files','target_uid'=>'recipient']];

        try {
            $result = NativeHomeProcess::run($envelope, $this->script, $this->root, $journal);
            self::assertSame('committed', $result['journal']['state']);
            self::assertCount(1, $result['journal']['completed']);
            self::assertSame('mkdir', $result['journal']['completed'][0]['operation']);
            self::assertSame('files', $result['journal']['completed'][0]['path']);
            self::assertSame(str_repeat('a',64), $result['journal']['completed'][0]['after_digest']);
        } finally {
            fclose($journal);
            @unlink($journalPath);
        }
    }

    public function testChildFailureReceiptIsBoundAndContainsNoMessageOrPath(): void {
        $failureScript = $this->root . '/failure-worker.php';
        $processSource = var_export(__DIR__ . '/../src/Service/NativeHomeProcess.php', true);
        file_put_contents($failureScript, "<?php\ndeclare(strict_types=1);\nrequire_once {$processSource};\n$" . "input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);\n"
            . "$" . "error=new ErrorException('DIAGNOSTIC_SECRET /root/private/credential.txt',0,E_USER_WARNING,'/root/private/credential.php',321);\n"
            . "echo json_encode(\\OCA\\Forms\\Mcp\\Service\\NativeHomeProcess::failureReceipt($" . "error,$" . "input),JSON_THROW_ON_ERROR);\nexit(1);\n");
        $journalPath = $this->root . '/failure-receipt.jsonl';
        $journal = fopen($journalPath, 'x+b');
        chmod($journalPath, 0600);
        $envelope = ['protocol'=>1,'job_id'=>str_repeat('3',64),'target_uid'=>'recipient','files_path'=>'/recipient/files',
            'home_phase'=>['nonce'=>str_repeat('4',64),'phase'=>'copy_files','target_uid'=>'recipient']];

        try {
            try {
                NativeHomeProcess::run($envelope, $failureScript, $this->root, $journal);
                self::fail('A failed child must not return as success.');
            } catch (RuntimeException $error) {
                self::assertSame('Native Home helper failed', $error->getMessage());
            }
            $diagnostic = NativeHomeProcess::takeFailure($envelope);
            self::assertSame(['protocol'=>1,'ok'=>false,
                'scope'=>['job_id'=>str_repeat('3',64),'nonce'=>str_repeat('4',64),'phase'=>'copy_files'],
                'failure'=>['class'=>'ErrorException','file'=>'credential.php','line'=>321,'stderr_bytes'=>0]], $diagnostic);
            $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('DIAGNOSTIC_SECRET', $encoded);
            self::assertStringNotContainsString('/root/private', $encoded);

            $receipt = NativeHomeProcess::failureReceipt(
                new ErrorException('ANOTHER_SECRET /home/dev/secret.php', 0, E_USER_WARNING, '/home/dev/secret.php', 55),
                $envelope,
            );
            self::assertNotNull($receipt);
            self::assertNull(NativeHomeProcess::validateFailureReceipt($receipt, $envelope, 16385));
            $receipt['failure']['unexpected'] = 'SECRET';
            self::assertNull(NativeHomeProcess::validateFailureReceipt($receipt, $envelope, 0));
            unset($receipt['failure']['unexpected']);
            $wrongScope = $envelope;
            $wrongScope['home_phase']['nonce'] = str_repeat('5',64);
            self::assertNull(NativeHomeProcess::validateFailureReceipt($receipt, $wrongScope, 0));
        } finally {
            fclose($journal);
            @unlink($journalPath);
        }
    }

    public function testWorkerReportsSanitizedCoreBootstrapFailure(): void {
        $worker = $this->root . '/worker.php';
        $serviceDir = $this->root . '/Service';
        mkdir($serviceDir, 0700);
        copy(__DIR__ . '/../src/native-home-worker.php', $worker);
        copy(__DIR__ . '/../src/Service/NativeHomeProcess.php', $serviceDir . '/NativeHomeProcess.php');
        file_put_contents($this->root . '/lib/base.php', "<?php\nif (!defined('OC_CONSOLE') || OC_CONSOLE !== true) { throw new LogicException('CONSOLE_SECRET'); }\nthrow new ErrorException('BOOT_SECRET /root/private/config.php',0,E_USER_WARNING,'/root/private/Core.php',777);\n");
        $journalPath = $this->root . '/bootstrap-failure.jsonl';
        $journal = fopen($journalPath, 'x+b');
        chmod($journalPath, 0600);
        $envelope = ['protocol'=>1,'job_id'=>str_repeat('5',64),'target_uid'=>'recipient','files_path'=>'/recipient/files',
            'home_phase'=>['nonce'=>str_repeat('6',64),'phase'=>'copy_files','target_uid'=>'recipient']];

        try {
            try {
                NativeHomeProcess::run($envelope, $worker, $this->root, $journal);
                self::fail('Core bootstrap failure must remain a failed, uncertain child result.');
            } catch (RuntimeException $error) {
                self::assertSame('Native Home helper failed', $error->getMessage());
            }
            $diagnostic = NativeHomeProcess::takeFailure($envelope);
            self::assertSame('ErrorException', $diagnostic['failure']['class']);
            self::assertSame('Core.php', $diagnostic['failure']['file']);
            self::assertSame(777, $diagnostic['failure']['line']);
            $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('BOOT_SECRET', $encoded);
            self::assertStringNotContainsString('/root/private', $encoded);
            self::assertSame(0, fstat($journal)['size']);
        } finally {
            fclose($journal);
            @unlink($journalPath);
        }
    }
}
