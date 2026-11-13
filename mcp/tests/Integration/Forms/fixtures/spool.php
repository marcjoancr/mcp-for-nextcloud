<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
if(getenv('MCP_FORMS_SPOOL_BOUNDARY')!=='1'){fwrite(STDERR,"Disposable Forms spool boundary must be explicitly enabled.\n");exit(2);}
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $db=Server::get(\OCP\IDBConnection::class);$uid='guard-owner';$job=hash('sha256','spool-native-'.bin2hex(random_bytes(16)));
    $spool=Server::get(\OCA\Mcp\Modules\Forms\Exports\FormsSpool::class);
    $stage='quota admission';$spool->cleanup();$spool->reserve($job,$uid);$spool->assertActive($job,$uid);
    $before=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*mcp_operations')->fetchOne();
    $stage='actual crypto25MiB';$bytes=str_repeat('x',26214400);$part=$spool->put($job,$bytes);
    if($spool->get($job,$part)!==$bytes)throw new RuntimeException('Native encrypted artifact did not round-trip.');
    $row=$db->executeQuery('SELECT * FROM *PREFIX*mcp_forms_spool WHERE job_id = ?',[$job])->fetch();
    $cipher=(int)$row['used_bytes'];
    if($cipher<=52428800||$row['io_token']!=='')throw new RuntimeException('Native ciphertext or physical completion evidence mismatch.');
    $stage='seal';$spool->seal($job);
    $reserved=(int)$db->executeQuery('SELECT reserved_bytes FROM *PREFIX*mcp_forms_spool WHERE job_id = ?',[$job])->fetchOne();
    if($reserved!==$cipher)throw new RuntimeException('Unused quota was not released.');
    $folder=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('mcp_forms_results')->getFolder('forms-results-v2-'.$job);
    $stage='expiry cleanup';$db->executeStatement('UPDATE *PREFIX*mcp_forms_spool SET expires_at = ? WHERE job_id = ?',[time()-1,$job]);
    $spool->cleanup();
    $row=$db->executeQuery('SELECT state,reserved_bytes FROM *PREFIX*mcp_forms_spool WHERE job_id = ?',[$job])->fetch();
    if($row['state']!=='expired'||(int)$row['reserved_bytes']!==0||$folder->fileExists($part['key']))throw new RuntimeException('Native cache cleanup was not verified.');
    $refused=false;try{$spool->put($job,'late');}catch(RuntimeException){$refused=true;}
    if(!$refused||$folder->fileExists(hash('sha256','late')))throw new RuntimeException('Expired native cache identity was recreated.');
    if((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*mcp_operations')->fetchOne()!==$before)throw new RuntimeException('Cache cleanup changed operation evidence.');
    echo json_encode(['ok'=>true,'checks'=>['plain_bytes'=>26214400,'cipher_bytes'=>$cipher,'seal_exact'=>true,'cleanup_verified'=>true,'late_write_refused'=>true,'receipts_unchanged'=>true,'provider'=>get_class(Server::get(\OCP\Lock\ILockingProvider::class))]],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}
