<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Build a dependency overlay against one pinned upstream archive. Never installs into a live Nextcloud.
if($argc!==3)throw new RuntimeException('Usage: build.php PINNED_ARCHIVE OUTPUT_UNDER_MCP_BUILD');
$archive=$argv[1];$destination=$argv[2];$root=dirname(__DIR__,2);$build=realpath($root.'/mcp/build');
if($build===false||!is_dir(dirname($destination))||!str_starts_with(realpath(dirname($destination)).'/', $build.'/'))throw new RuntimeException('Output must stay under this project mcp/build.');
if(hash_file('sha256',$archive)!=='c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0')throw new RuntimeException('Unsupported native source archive.');
// Stream only bounded, fixed members. PharData expands the whole release into the host tmpfs.
$read=static function(string $path)use($archive):string {
    $process=proc_open(['tar','-xOzf',$archive,'--occurrence=1','mail/'.$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot read pinned native source.');
    fclose($pipes[0]);$bytes=stream_get_contents($pipes[1],2097153);$error=stream_get_contents($pipes[2],8192);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||strlen($bytes)>2097152)throw new RuntimeException('Pinned native source member unavailable.');
    return $bytes;
};$files=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/src',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){if($file->isFile()&&$file->getExtension()==='php')$files['lib/'.substr($file->getPathname(),strlen(__DIR__.'/src/'))]=file_get_contents($file->getPathname());}
$p='lib/IMAP/HordeImapClient.php';$s=$read($p);$needle="class HordeImapClient extends Horde_Imap_Client_Socket {";
if(substr_count($s,$needle)!==1)throw new RuntimeException('Unexpected native client source.');$files[$p]=str_replace($needle,$needle."\n\tuse GuardedFlagMutation, GuardedMoveMutation, GuardedMailboxMutation;",$s);
$p='lib/Listener/MessageCacheUpdaterListener.php';$s=$read($p);$needle='public function handle(Event $event): void {';
if(substr_count($s,$needle)!==1)throw new RuntimeException('Unexpected native listener source.');$files[$p]=str_replace($needle,$needle."\n\t\tif (\$event instanceof \\OCA\\Mail\\Events\\GuardedMessageFlaggedEvent || \$event instanceof \\OCA\\Mail\\Events\\GuardedMessageDeletedEvent) { return; }",$s);
$p='lib/Listener/MailboxesSynchronizedSpecialMailboxesUpdater.php';$s=$read($p);$needle='public function handle(Event $event): void {';
if(substr_count($s,$needle)!==1)throw new RuntimeException('Unexpected role listener source.');$files[$p]=str_replace($needle,$needle."\n\t\tif (\$event instanceof \\OCA\\Mail\\Events\\GuardedMailboxesSynchronizedEvent) { return; }",$s);
$hashes=[];foreach($files as $p=>$bytes){$target=$destination.'/mail/'.$p;if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);file_put_contents($target,$bytes);$hashes[$p]=hash('sha256',$bytes);}
foreach(['lib/AppInfo/Application.php','lib/Events/MailboxesSynchronizedEvent.php','lib/Events/BeforeMessageDeletedEvent.php','lib/Events/MessageDeletedEvent.php','lib/Db/Mailbox.php','lib/Db/Message.php','lib/Db/MailAccount.php','lib/Db/MailAccountMapper.php','lib/Service/AccountService.php','lib/Service/MailManager.php','lib/IMAP/IMAPClientFactory.php','lib/Db/MailboxMapper.php','lib/Db/MessageMapper.php','vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Base.php','vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Socket.php'] as $p)$hashes[$p]=hash('sha256',$read($p));
ksort($hashes);file_put_contents($destination.'/manifest.json',json_encode(['contract'=>'mcp-mail-mutations-v3','mail'=>'5.12.5','files'=>$hashes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$phpHeader="<?php\n/*\n * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>\n * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\ndeclare(strict_types=1);\nnamespace OCA\\Mcp\\Modules\\Mail\\Integration;\n/** Generated from the pinned native source and reviewed overlay. No credentials. */\nfinal class NativeGuardManifest {\n    public const DATA = ";
file_put_contents($destination.'/NativeGuardManifest.php',$phpHeader.var_export(['contract'=>'mcp-mail-mutations-v3','mail'=>'5.12.5','files'=>$hashes],true).";\n    public const LEGACY_FLAGS_DATA = ".var_export(json_decode(file_get_contents(__DIR__.'/flags-v1-manifest.json'),true,512,JSON_THROW_ON_ERROR),true).";\n    public const LEGACY_MOVES_DATA = ".var_export(json_decode(file_get_contents(__DIR__.'/moves-v2-manifest.json'),true,512,JSON_THROW_ON_ERROR),true).";\n}\n");
$generated=$destination.'/NativeGuardManifest.php';file_put_contents($generated,preg_replace('/[ \t]+$/m','',file_get_contents($generated)));
echo 'Native overlay built: '.count($files).' files, manifest '.hash_file('sha256',$destination.'/manifest.json')."\n";
