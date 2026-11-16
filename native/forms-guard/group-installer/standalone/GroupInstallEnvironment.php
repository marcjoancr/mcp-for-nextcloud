<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Public defaults of the pinned helper image; never inherit application credentials. */
final class GroupInstallEnvironment {
 public static function child():array {
  $environment=['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
  foreach(['PHP_MEMORY_LIMIT'=>'512M','PHP_UPLOAD_LIMIT'=>'512M','PHP_OPCACHE_MEMORY_CONSUMPTION'=>'128']as$name=>$expected){if(getenv($name)!==$expected)throw new \RuntimeException('Helper PHP environment is unqualified');$environment[$name]=$expected;}
  return$environment;
 }
}
