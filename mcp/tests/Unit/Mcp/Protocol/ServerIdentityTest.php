<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;

use OCA\Mcp\Mcp\Protocol\{McpProtocol, PromptRequests, ProtocolMetadata, ResourceRequests, ServerIdentity, ToolRequests};
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tests\Unit\{InMemoryConfig, InstalledApp};
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ServerIdentityTest extends TestCase {
    public function testInstalledVersionReachesLegacyAndEveryModernResultOwner(): void {
        $apps = $this->createMock(IAppManager::class);
        // Core manifest caching remains enabled: getAppVersion is called with its default cache argument.
        $apps->method('getAppVersion')->with('mcp')->willReturn(InstalledApp::VERSION);
        $identity = new ServerIdentity($apps, new \OCA\Mcp\Release\SourceOffer($this->createMock(\OCP\IConfig::class), $apps));
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $policy->setGrant('alice', 'files', 'edit', true);
        $prompts = new PromptRequests($identity, new PromptCatalog(), $policy);
        $tools = $this->createMock(ToolRegistry::class);
        $tools->method('list')->willReturn([]);
        $tools->method('call')->willReturn(['content' => [['type' => 'text', 'text' => 'Synthetic result']]]);
        $resources = $this->createMock(ResourceRegistry::class);
        $resources->method('list')->willReturn(['resources' => []]);
        $resources->method('templates')->willReturn(['resourceTemplates' => []]);
        $resources->method('read')->willReturn(['contents' => []]);
        $protocol = new McpProtocol($identity, new ToolRequests($identity, $tools), $prompts,
            new ResourceRequests($identity, $resources), $this->createMock(LoggerInterface::class));
        $init = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => ProtocolMetadata::VERSION, 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]), '', 'alice');
        self::assertSame(InstalledApp::INFO, $init['body']['result']['serverInfo']);
        $methods = [
            ['server/discover', []], ['tools/list', []], ['tools/call', ['name' => 'mcp_status']],
            ['tools/call', ['name' => 'synthetic']], ['prompts/list', []],
            ['prompts/get', ['name' => $prompts->list('alice')[0]['name']]],
            ['resources/list', []], ['resources/templates/list', []], ['resources/read', ['uri' => 'nc://test']],
        ];
        foreach ($methods as [$method, $params]) {
            $params['_meta'] = [ProtocolMetadata::META_VERSION => ProtocolMetadata::MODERN_VERSION];
            $response = $protocol->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params]),
                ProtocolMetadata::MODERN_VERSION, 'alice', ['method' => $method]);
            self::assertArrayHasKey('result', $response['body'], $method);
            self::assertSame(InstalledApp::INFO, $response['body']['result']['_meta'][ProtocolMetadata::META_SERVER_INFO], $method);
        }
    }
}
