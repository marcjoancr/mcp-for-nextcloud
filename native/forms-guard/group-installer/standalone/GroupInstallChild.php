<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Same-image finite helper starts as root only to drop this fresh child to the native service identity.
if(PHP_SAPI!=='cli'||posix_getppid()!==1||posix_geteuid()!==0||!posix_initgroups('www-data',33)||!posix_setgid(33)||!posix_setuid(33)){exit(2);}
require __DIR__.'/GroupInstallWorker.php';
