<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{Http,LoginFactory,ToolPermissionFixture};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Release\SourceOffer;
use OCP\{Server,IConfig};
/** Explicit final clean-package acceptance; the parent stages and verifies both published source kits first. */
final class NativeSourceOfferHttpTest extends IntegrationTestCase {
    public function testOrdinaryUserSeesBothIndependentKitsAndInvalidNativeMetadataFailsClosed(): void {
        if(getenv('MCP_IT_NATIVE_SOURCE_OFFER')!=='1')self::markTestSkipped('Requires explicitly staged clean MCP/native source publications.');
        $config=Server::get(IConfig::class);$policy=Server::get(GrantPolicy::class);$uid=MailActor::ALICE;
        self::assertFalse($config->getSystemValueBool('app.mail.mcp-transmission.enabled',false));
        $nativeConfig=$config->getSystemValue('mcp_native_source_offer',null);
        $source=Server::get(SourceOffer::class);$mcp=$source->get();$native=$source->getNative();
        self::assertNotNull($mcp);self::assertNotNull($native);self::assertCount(5,$mcp['files']);self::assertCount(6,$native['files']);
        $snapshot=ToolPermissionFixture::capture([$uid],[ToolGrantsMapper::SERVICE,'mail_send']);
        $global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__absent');$old=[];
        foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$old[$key]=$config->getUserValue($uid,'mcp',$key,'__absent');
        try {
            $policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);
            ToolPermissionFixture::assign($uid,['mail_send'=>'deny']);
            self::assertFalse(Server::get(\OCP\IGroupManager::class)->isAdmin($uid));
            $auth=LoginFactory::basic($uid)->authorization();
            $page=Http::request('GET',Http::BASE.'/index.php/settings/user/mcp',['Authorization: '.$auth,'Accept: text/html']);
            self::assertSame(200,$page['status']);
            foreach([...$mcp['files'],...$native['files']]as$url)self::assertStringContainsString(htmlspecialchars($url,ENT_QUOTES),$page['body']);
            foreach(['initialize','server/discover']as$method){
                $headers=['Authorization: '.$auth,'Content-Type: application/json','Accept: application/json, text/event-stream'];
                $params=['protocolVersion'=>'2025-06-18','clientInfo'=>['name'=>'native-source-acceptance','version'=>'1']];
                if($method==='server/discover'){$headers[]='MCP-Protocol-Version: 2026-07-28';$headers[]='Mcp-Method: server/discover';$params=['_meta'=>['io.modelcontextprotocol/protocolVersion'=>'2026-07-28']];}
                $r=Http::request('POST',Http::BASE.'/index.php/apps/mcp/',$headers,json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR));
                self::assertSame(200,$r['status']);$instructions=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR)['result']['instructions'];
                foreach([...$mcp['files'],...$native['files']]as$url)self::assertStringContainsString($url,$instructions);
            }
            foreach([null,[...$nativeConfig,'sourceCommit'=>str_repeat('f',40)],[...$nativeConfig,'baseUrl'=>'https://user:secret@example.invalid']]as$invalid){
                $config->setSystemValue('mcp_native_source_offer',$invalid);sleep(3);
                $page=Http::request('GET',Http::BASE.'/index.php/settings/user/mcp',['Authorization: '.$auth,'Accept: text/html']);
                self::assertSame(200,$page['status']);self::assertStringNotContainsString('mail-upstream-runtime.tar.gz',$page['body']);
                self::assertStringContainsString(htmlspecialchars($mcp['files']['source.bundle'],ENT_QUOTES),$page['body']);
            }
        } finally {
            $config->setSystemValue('mcp_native_source_offer',$nativeConfig);
            ToolPermissionFixture::restore($snapshot);
            if($global==='__absent')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);
            foreach($old as$key=>$value){if($value==='__absent')$config->deleteUserValue($uid,'mcp',$key);else $config->setUserValue($uid,'mcp',$key,$value);}
        }
        self::assertFalse($config->getSystemValueBool('app.mail.mcp-transmission.enabled',false));
    }
}
