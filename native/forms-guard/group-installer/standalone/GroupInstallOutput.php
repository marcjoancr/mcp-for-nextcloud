<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Migration output never exposes database/server details. Warnings stop the qualified path. */
final class GroupInstallOutput implements \OCP\Migration\IOutput {
 public function debug(string$message):void{}
 public function info($message){}
 public function warning($message){throw new \RuntimeException('Installer migration warning');}
 public function startProgress($max=0){}
 public function advance($step=1,$description=''){}
 public function finishProgress(){}
}
