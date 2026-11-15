<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Disposable Maria HTTP cell; cancelled/unknown copies retain exact source and recovery. */
final class FormsTransferControlHttpTest extends TestCase {
 public static function cases():array{return ['before'=>['cancel_before'],'staged'=>['cancel_staged'],'ack_first'=>['ack_first'],'ack_second'=>['ack_second']];}
 #[DataProvider('cases')]
 public function testTransferCancellationOrCopyReceiptFailureNeverReplaysAndPreservesRecovery(string $case):void{
  if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Forms Maria HTTP cell.');
  self::assertNotFalse(getenv('MCP_FORMS_TRANSFER_HTTP_EXPECTED'),'Provide fresh unique private per-attempt evidence.');
  $fixture=getenv('MCP_FORMS_TRANSFER_CONTROL_HTTP_PROBE')?:__DIR__.'/fixtures/transfer-control-http.php';self::assertFileExists($fixture);
  $prior=getenv('MCP_FORMS_TRANSFER_CONTROL_CASE');$priorExpected=getenv('MCP_FORMS_TRANSFER_HTTP_EXPECTED');$caseExpected='/tmp/forms-transfer-http-'.substr(hash('sha256',$priorExpected.':'.$case),0,16).'.json';putenv('MCP_FORMS_TRANSFER_HTTP_EXPECTED='.$caseExpected);putenv('MCP_FORMS_TRANSFER_CONTROL_CASE='.$case);
  try{$p=proc_open([PHP_BINARY,$fixture],[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes);self::assertIsResource($p);fclose($pipes[0]);$out='';$oversized=false;while(!feof($pipes[1])){$chunk=fread($pipes[1],4096);if($chunk===false)break;if(strlen($out)+strlen($chunk)>16384)$oversized=true;if(!$oversized)$out.=$chunk;}fclose($pipes[1]);if($oversized)$out='';self::assertSame(0,proc_close($p),self::childFailure($out));$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertIsArray($r);self::assertTrue($r['ok']??false);self::assertSame($case,$r['mode']);
   foreach(['honest_cancel_or_uncertain_state','exact_original_rows_and_bytes_retained','fresh_same_operation_recovery_evidence','exact_confirmation_and_new_continue_do_not_replay'] as$key)self::assertTrue($r['checks'][$key]??false,$key);
   if($case==='cancel_before')self::assertTrue($r['checks']['early_cancel_has_no_target_or_recovery_bytes']??false);
   else{self::assertTrue($r['checks']['all_original_bytes_and_exact_private_recovery']??false);if($case==='cancel_staged')self::assertTrue($r['checks']['staged_cancel_retains_one_copy_and_complete_backup']??false);else{self::assertTrue($r['checks']['actual_copy_then_mapping_receipt_failure']??false);self::assertSame($case==='ack_first'?1:2,$r['position']);}}
  }finally{putenv($prior===false?'MCP_FORMS_TRANSFER_CONTROL_CASE':'MCP_FORMS_TRANSFER_CONTROL_CASE='.$prior);putenv('MCP_FORMS_TRANSFER_HTTP_EXPECTED='.$priorExpected);}
 }
 private static function childFailure(string $output):string {
  $stages=['bootstrap','native transfer golden source','exact public transfer control admission','cancel before staging','advance exact transfer to staged-file boundary','actual target copy followed by exact mapping receipt fault','public cancellation before any Files staging','public cancellation after exactly one staged copy','fresh same-attempt control and recovery evidence','exact confirmation and new continuation never repeat File effects','public exact sealed pre-transfer recovery'];
  $classes=['RuntimeException','LogicException','JsonException','OCP\\Files\\NotFoundException','OCP\\Files\\ForbiddenException'];
  $data=null;if(strlen($output)<=16384){try{$data=json_decode($output,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){}}
  $stage=is_array($data)&&in_array($data['stage']??null,$stages,true)?$data['stage']:'unknown';
  $class=is_array($data)&&in_array($data['error_class']??null,$classes,true)?$data['error_class']:'unknown';
  return 'Child failed; stage='.$stage.'; class='.$class;
 }
 public function testChildDiagnosticsOnlyExposeAllowlistedMetadata():void {
  self::assertSame('Child failed; stage=bootstrap; class=RuntimeException',self::childFailure(json_encode(['ok'=>false,'stage'=>'bootstrap','error_class'=>'RuntimeException','private'=>'secret-token'],JSON_THROW_ON_ERROR)));
  self::assertSame('Child failed; stage=unknown; class=unknown',self::childFailure('{private secret-token'));
  self::assertSame('Child failed; stage=unknown; class=unknown',self::childFailure(json_encode(['stage'=>'secret-token','error_class'=>'secret-token'],JSON_THROW_ON_ERROR)));
  self::assertSame('Child failed; stage=unknown; class=unknown',self::childFailure(str_repeat('secret-token',2000)));
 }

}
