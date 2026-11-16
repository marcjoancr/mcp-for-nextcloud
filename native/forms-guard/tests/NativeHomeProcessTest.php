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
}
