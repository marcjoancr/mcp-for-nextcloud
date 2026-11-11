<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';
use OCP\{Server,IConfig};
use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,NativeGuardManifest};
use OCA\Mcp\Tests\Integration\Mail\MailCleanHttpActor;
$config=Server::get(IConfig::class);
if(getenv('MCP_IT_QUALIFIED_PROFILE')!=='mail-qualified-nc35-pg16-s3-v11'
    ||getenv('MCP_IT_MAIL_GUARD')!=='1'||getenv('MCP_IT_DB')!=='pgsql'
    ||$config->getSystemValueString('dbtype')!=='pgsql'
    ||!str_starts_with($config->getSystemValueString('version'),'35.0.2.')
    ||$config->getSystemValueBool('app.mail.mcp-transmission.enabled',false)
    ||($config->getSystemValue('objectstore',[])['class']??null)!=='\\OC\\Files\\ObjectStore\\S3')throw new RuntimeException('Qualified fixture prerequisites differ.');
$apps=Server::get(\OCP\App\IAppManager::class);$apps->loadApp('mail');
if($apps->getAppVersion('mail')!=='5.12.5.11'||NativeGuardManifest::DATA['contract']!=='mcp-mail-mutations-v11')throw new RuntimeException('Exact native v11 required.');
MailCleanHttpActor::assertClean();
$boundary=Server::get(MailNativeBoundary::class);
if(!$boundary->boundedMetadataSourceVerified()||!$boundary->draftsVerified()||!$boundary->refreshVerified())throw new RuntimeException('Native storage/metadata guarantees unavailable.');
foreach(['mcp','mail']as$app){$m=new \OC\DB\MigrationService($app,Server::get(\OC\DB\Connection::class));if(array_diff($m->getAvailableVersions(),$m->getMigratedVersions())!==[])throw new RuntimeException('Pending native migration.');}
