<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
// Pure source/environment probe supplied on stdin; never bootstraps Nextcloud.
error_reporting(0);ini_set('display_errors','0');ini_set('log_errors','0');
try{
$forbidden=json_decode('["FORWARDED_FOR_HEADERS", "MAIL_DOMAIN", "MAIL_FROM_ADDRESS", "OBJECTSTORE_S3_AUTOCREATE", "OBJECTSTORE_S3_BUCKET", "OBJECTSTORE_S3_HOST", "OBJECTSTORE_S3_KEY", "OBJECTSTORE_S3_KEY_FILE", "OBJECTSTORE_S3_LEGACYAUTH", "OBJECTSTORE_S3_OBJECT_PREFIX", "OBJECTSTORE_S3_PORT", "OBJECTSTORE_S3_REGION", "OBJECTSTORE_S3_SECRET", "OBJECTSTORE_S3_SECRET_FILE", "OBJECTSTORE_S3_SSE_C_KEY", "OBJECTSTORE_S3_SSE_C_KEY_FILE", "OBJECTSTORE_S3_SSE_KMS_ENABLED", "OBJECTSTORE_S3_SSE_KMS_KEY_ID", "OBJECTSTORE_S3_SSL", "OBJECTSTORE_S3_STORAGE_CLASS", "OBJECTSTORE_S3_USEPATH_STYLE", "OBJECTSTORE_SWIFT_AUTOCREATE", "OBJECTSTORE_SWIFT_CONTAINER_NAME", "OBJECTSTORE_SWIFT_PROJECT_DOMAIN", "OBJECTSTORE_SWIFT_PROJECT_NAME", "OBJECTSTORE_SWIFT_REGION", "OBJECTSTORE_SWIFT_SERVICE_NAME", "OBJECTSTORE_SWIFT_URL", "OBJECTSTORE_SWIFT_USER_DOMAIN", "OBJECTSTORE_SWIFT_USER_NAME", "OBJECTSTORE_SWIFT_USER_PASSWORD", "OVERWRITECLIURL", "OVERWRITECONDADDR", "OVERWRITEHOST", "OVERWRITEPROTOCOL", "OVERWRITEWEBROOT", "REDIS_HOST", "REDIS_HOST_PASSWORD", "REDIS_HOST_PASSWORD_FILE", "REDIS_HOST_PORT", "REDIS_HOST_USER", "REDIS_PROTOCOL", "SMTP_AUTHTYPE", "SMTP_HOST", "SMTP_NAME", "SMTP_PASSWORD", "SMTP_PASSWORD_FILE", "SMTP_PORT", "SMTP_SECURE", "TRUSTED_PROXIES"]',true);
foreach(['APACHE_DISABLE_REWRITE_IP','NEXTCLOUD_INIT_HTACCESS','NEXTCLOUD_CONFIG_DIR','FRANKENPHP_WORKER','APACHE_PID_FILE','PHP_INI_SCAN_DIR']as$name)$forbidden[]=$name;
$absent=true;foreach(array_unique($forbidden)as$name)if(getenv($name)!==false)$absent=false;
foreach(getenv()as$name=>$_)if(str_starts_with($name,'NC_'))$absent=false;
$paths=(getenv('PHP_INI_DIR')===false||getenv('PHP_INI_DIR')==='/usr/local/etc/php')&&getenv('APACHE_CONFDIR')==='/etc/apache2'&&getenv('APACHE_ENVVARS')==='/etc/apache2/envvars';
$defaults=true;foreach(['APACHE_RUN_USER'=>'www-data','APACHE_RUN_GROUP'=>'www-data','APACHE_RUN_DIR'=>'/var/run/apache2','APACHE_LOCK_DIR'=>'/var/lock/apache2','APACHE_LOG_DIR'=>'/var/log/apache2']as$name=>$value)if(getenv($name)!==false&&getenv($name)!==$value)$defaults=false;
$sources=[];foreach(['/entrypoint.sh','/usr/local/bin/apache2-foreground','/etc/apache2/envvars','/usr/src/nextcloud/version.php','/var/www/html/version.php']as$file){if(!is_file($file)||is_link($file))throw new \RuntimeException('Startup source unavailable');$sources[$file]=hash_file('sha256',$file);}
$hooks=[];if(is_dir('/docker-entrypoint-hooks.d'))foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/docker-entrypoint-hooks.d',\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST)as$file){if($file->isLink()||!$file->isDir())$hooks[]='present';if(count($hooks)>100)break;}
$main=\McpFormsServerExtension\GroupInstallLiteralConfig::read(file_get_contents('/var/www/html/config/config.php'));
$dns=($main['dbhost']??null)==='forms-guard-db-20261113'||($main['dbhost']??null)==='forms-guard-db-20261113:5432';
if(($main['version']??null)!=='35.0.2.2'||($main['dbtype']??null)!=='pgsql'||!is_bool($main['maintenance']??false))throw new \RuntimeException('Startup native profile refused');
echo json_encode(['sources'=>$sources,'hooks'=>$hooks,'default_paths'=>$paths,'dangerous_env_absent'=>$absent,'apache_defaults'=>$defaults,'no_preload'=>(string)ini_get('opcache.preload')==='','dbhost_dns'=>$dns,'maintenance'=>$main['maintenance']??false]),"\n";

}catch(\Throwable){echo json_encode(['ok'=>false,'stage'=>'startup_probe']);exit(1);}
