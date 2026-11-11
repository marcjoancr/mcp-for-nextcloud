<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Credentials and response bytes stay in process memory and anonymous pipes only.
ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,'PROJECTION_HTTP_FAILED '.get_class($e)."\n");exit(1);});
$base=dirname(__DIR__);
require getenv('MCP_IT_PROFILE')==='linuxserver'?$base.'/ci/linuxserver/l3-test-http.php':$base.'/Support/Http.php';
foreach(['Credential','ToolReply','McpClient']as$class)require $base.'/Support/'.$class.'.php';
$in=json_decode((string)stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
if(getenv('MCP_IT_CLEAN_MAIL')!=='1'||!in_array($in['tool'],['mail_send','mail_set_message_flag'],true))throw new RuntimeException('Closed synthetic request required');
$c=new \OCA\Mcp\Tests\Integration\Support\McpClient(\OCA\Mcp\Tests\Integration\Support\Credential::bearer($in['uid'],$in['token']));
$r=$c->tool($in['tool'],$in['arguments']);$data=$r->structured()!==[]?$r->structured():$r->json();
echo json_encode(['ok'=>$r->ok(),'canary'=>str_contains($r->raw,$in['canary']),'operation_exposed'=>isset($data['operation']),'response_sha256'=>hash('sha256',$r->raw)],JSON_THROW_ON_ERROR)."\n";
