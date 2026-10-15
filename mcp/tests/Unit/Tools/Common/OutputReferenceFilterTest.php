<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Common;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Common\FileReferenceScope;
use OCA\Mcp\Tools\Common\OutputReferenceFilter;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** The structured-reference output rule: which references stay, which go, and that the source object never changes. */
final class OutputReferenceFilterTest extends TestCase {
    private const HIDDEN_TAG = '7';

    private FakeTree $tree;
    private InMemoryConfig $config;
    /** @var array<string, list<string>> tags by object id */
    private array $tags = [];
    /** @var array<string, IShare> shares by full id */
    private array $shares = [];
    /** @var array<string, IShare> public shares by token */
    private array $tokens = [];
    private VisibilityGuard $guard;
    private int $okId;
    private int $hiddenId;
    private int $inheritedId;
    private int $otherId;

    protected function setUp(): void {
        $this->tree = new FakeTree($this, '/alice/files');
        $this->okId = $this->tree->addFile('/alice/files/Docs/ok.pdf', 'ok');
        $this->hiddenId = $this->tree->addFile('/alice/files/Docs/direct.pdf', 'tagged');
        $secret = $this->tree->addFolder('/alice/files/Secret');
        $this->inheritedId = $this->tree->addFile('/alice/files/Secret/plan.pdf', 'inherited');
        $this->otherId = $this->tree->addFile('/alice/files/Other/out.pdf', 'out of scope');
        $this->tags = [(string)$this->hiddenId => [self::HIDDEN_TAG], (string)$secret => [self::HIDDEN_TAG]];
        $this->config = new InMemoryConfig();
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
            $result = [];
            foreach ($ids as $id) {
                $result[(string)$id] = $this->tags[(string)$id] ?? [];
            }
            return $result;
        });
        $this->guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot());
    }

    private function filter(?FileReferenceScope $scope = null, array $trusted = ['cloud.example.com', '*.cloud.example.net'], string $webroot = ''): OutputReferenceFilter {
        $shares = $this->createMock(IShareManager::class);
        $shares->method('getShareById')->willReturnCallback(fn (string $id) => $this->shares[$id] ?? throw new \OCP\Share\Exceptions\ShareNotFound());
        $shares->method('getShareByToken')->willReturnCallback(fn (string $token) => $this->tokens[$token] ?? throw new \OCP\Share\Exceptions\ShareNotFound());
        return OutputReferenceFilters::over($this, $this->tree->filesystemRoot(), $this->guard, $shares, $scope, $trusted, $webroot);
    }

    private function roomShare(int $id, int $nodeId, string $token = 'room1'): void {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
        $share->method('getSharedWith')->willReturn($token);
        $share->method('getNodeId')->willReturn($nodeId);
        $this->shares['ocRoomShare:' . $id] = $share;
    }

    /** A scope that keeps only /Docs and records the order the gates were asked in. */
    private function docsScope(array &$asked = []): FileReferenceScope {
        return new class ($asked) implements FileReferenceScope {
            public function __construct(private array &$asked) {}
            public function restricts(string $userId): bool {
                return true;
            }
            public function allows(string $userId, Node $node): bool {
                $this->asked[] = $node->getPath();
                return str_starts_with($node->getPath(), '/alice/files/Docs/');
            }
        };
    }

    private function calendar(string ...$attach): VCalendar {
        $lines = array_map(static fn (string $line) => 'ATTACH' . $line, $attach);
        return Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VTODO\r\nUID:t\r\nSUMMARY:S\r\n"
            . implode("\r\n", $lines) . ($lines === [] ? '' : "\r\n")
            . "BEGIN:VALARM\r\nACTION:AUDIO\r\nTRIGGER:-PT5M\r\nATTACH:" . $this->ids('https://cloud.example.com/f/HIDDEN') . "\r\nEND:VALARM\r\n"
            . "END:VTODO\r\nEND:VCALENDAR\r\n");
    }

    /** @return list<string> ATTACH values of the calendar and its nested components */
    private static function attaches(VCalendar $calendar): array {
        $values = [];
        $walk = static function ($component) use (&$walk, &$values): void {
            foreach ($component->select('ATTACH') as $attach) {
                $values[] = (string)$attach->getValue();
            }
            foreach ($component->getComponents() as $child) {
                $walk($child);
            }
        };
        $walk($calendar);
        return $values;
    }

    private function ids(string $url): string {
        return strtr($url, ['OK' => (string)$this->okId, 'HIDDEN' => (string)$this->hiddenId, 'INHERITED' => (string)$this->inheritedId, 'OTHER' => (string)$this->otherId]);
    }

    /** @return array<string, array{string, bool}> URL and whether it stays in the output */
    public static function urls(): array {
        return [
            'short link allowed' => ['https://cloud.example.com/f/OK', true],
            'short link tagged' => ['https://cloud.example.com/f/HIDDEN', false],
            'short link inherited' => ['https://cloud.example.com/index.php/f/INHERITED', false],
            'files app query allowed' => ['https://cloud.example.com/index.php/apps/files/?dir=/Docs&fileid=OK', true],
            'files app query tagged' => ['https://cloud.example.com/apps/files/?dir=/Docs&fileid=HIDDEN', false],
            'files app path route' => ['https://cloud.example.com/apps/files/files/HIDDEN?dir=/Docs&openfile=true', false],
            'dav path allowed' => ['https://cloud.example.com/remote.php/dav/files/alice/Docs/ok.pdf', true],
            'dav path inherited' => ['https://cloud.example.com/remote.php/dav/files/alice/Secret/plan.pdf', false],
            'dav path encoded' => ['https://cloud.example.com/remote.php/dav/files/alice/Secret/plan%2Epdf', false],
            'dav path of another user' => ['https://cloud.example.com/remote.php/dav/files/bruno/Docs/ok.pdf', false],
            'dav traversal' => ['https://cloud.example.com/remote.php/dav/files/alice/Docs/../Secret/plan.pdf', false],
            'legacy webdav' => ['https://cloud.example.com/remote.php/webdav/Secret/plan.pdf', false],
            'relative short link' => ['/f/HIDDEN', false],
            'relative allowed' => ['/index.php/f/OK', true],
            'host-relative' => ['//cloud.example.com/f/HIDDEN', false],
            'wildcard trusted domain' => ['https://node1.cloud.example.net/f/HIDDEN', false],
            'host case and port' => ['https://CLOUD.example.com:443/f/HIDDEN', false],
            'host trailing dot' => ['https://cloud.example.com./f/HIDDEN', false],
            'host escaped letter' => ['https://%63loud.example.com/f/HIDDEN', false],
            'local missing id' => ['https://cloud.example.com/f/987654', false],
            'local unsupported shape' => ['https://cloud.example.com/apps/deck/board/1', false],
            'local non-numeric fileid' => ['https://cloud.example.com/apps/files/?fileid=OK,HIDDEN', false],
            'path and query both named' => ['https://cloud.example.com/apps/files/files/OK?fileid=HIDDEN', false],
            'local webdav scheme' => ['webdav://cloud.example.com/f/OK', false],
            'external same path' => ['https://files.example.org/f/HIDDEN', true],
            'external dav' => ['https://files.example.org/remote.php/dav/files/alice/Secret/plan.pdf', true],
            'mail reference' => ['mailto:someone@example.org', true],
            'content id' => ['cid:part1@example.org', true],
        ];
    }

    #[DataProvider('urls')]
    public function testAttachIsKeptOnlyWhenEveryNamedNodeIsAllowed(string $url, bool $kept): void {
        $url = $this->ids($url);
        $original = $this->calendar(':' . $url);
        $before = $original->serialize();

        $output = $this->filter()->outputCalendar('alice', $original);

        self::assertSame($kept ? [$url] : [], self::attaches($output), $url);
        self::assertSame($before, $original->serialize(), 'the object written to DAV never changes');
        self::assertSame([$url, $this->ids('https://cloud.example.com/f/HIDDEN')], self::attaches($original));
    }

    public function testNestedAlarmAttachIsCheckedAndInlineContentStays(): void {
        $original = $this->calendar(';ENCODING=BASE64;VALUE=BINARY;FMTTYPE=text/plain:aGVsbG8=');

        $output = $this->filter()->outputCalendar('alice', $original);

        self::assertSame(['hello'], self::attaches($output), 'inline data is content, the alarm reference to a tagged file goes');
    }

    public function testWebrootPrefixIsStripped(): void {
        $kept = $this->ids('https://cloud.example.com/nextcloud/index.php/f/OK');
        $gone = $this->ids('https://cloud.example.com/nextcloud/f/HIDDEN');

        $output = $this->filter(null, ['cloud.example.com'], '/nextcloud')->outputCalendar('alice', $this->calendar(':' . $kept, ':' . $gone));

        self::assertSame([$kept], self::attaches($output));
    }

    public function testPublicLinkResolvesThroughItsShareWithoutFollowingIt(): void {
        foreach (['okToken' => $this->okId, 'secretToken' => $this->inheritedId] as $token => $node) {
            $share = $this->createMock(IShare::class);
            $share->method('getNodeId')->willReturn($node);
            $this->tokens[$token] = $share;
        }
        $kept = 'https://cloud.example.com/s/okToken';
        $output = $this->filter()->outputCalendar('alice', $this->calendar(
            ':' . $kept,
            ':https://cloud.example.com/index.php/s/secretToken/download',
            ':https://cloud.example.com/s/unknown',
            ':https://cloud.example.com/s/okToken/download?path=/sub&files=x.pdf',
        ));

        self::assertSame([$kept], self::attaches($output));
    }

    public function testUnreadableFileIsRemovedLikeAMissingOne(): void {
        $this->tree->nodes['/alice/files/Docs/ok.pdf']['readable'] = false;
        $url = $this->ids('https://cloud.example.com/f/OK');

        self::assertSame([], self::attaches($this->filter()->outputCalendar('alice', $this->calendar(':' . $url))));
    }

    public function testScopeIsAskedFirstAndAFileOnlyOutsideItIsRemoved(): void {
        $asked = [];
        $ok = $this->ids('https://cloud.example.com/f/OK');
        $out = $this->ids('https://cloud.example.com/f/OTHER');

        $output = $this->filter($this->docsScope($asked))->outputCalendar('alice', $this->calendar(':' . $ok, ':' . $out));

        self::assertSame([$ok], self::attaches($output));
        self::assertContains('/alice/files/Other/out.pdf', $asked, 'the untagged file outside the scope reached the scope step');
    }

    public function testScopeRefusalStopsBeforeTheExclusionCheck(): void {
        $this->tags = [];
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->expects(self::never())->method('getTagIdsForObjects');
        $this->guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->tree->filesystemRoot());
        $refuseAll = new class implements FileReferenceScope {
            public function restricts(string $userId): bool {
                return true;
            }
            public function allows(string $userId, Node $node): bool {
                return false;
            }
        };

        $output = $this->filter($refuseAll)->outputCalendar('alice', $this->calendar(':' . $this->ids('https://cloud.example.com/f/OK')));

        self::assertSame([], self::attaches($output));
    }

    public function testWithoutExclusionAndScopeTheObjectIsReturnedAsIsWithoutResolvingAnything(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->expects(self::never())->method('getUserFolder');
        $shares = $this->createMock(IShareManager::class);
        $shares->expects(self::never())->method('getShareById');
        $guard = new VisibilityGuard($this->config->mock($this), $this->createMock(ISystemTagObjectMapper::class));
        $filter = OutputReferenceFilters::over($this, $root, $guard, $shares);
        $calendar = $this->calendar(':https://cloud.example.com/f/1');

        self::assertSame($calendar, $filter->outputCalendar('alice', $calendar));
        self::assertSame(55, $filter->attachmentId('alice', 'room1', 55));
    }

    public function testInvalidExclusionConfigurationRemovesEveryLocalReference(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '{"broken":';
        $external = 'https://files.example.org/f/1';

        $output = $this->filter()->outputCalendar('alice', $this->calendar(':' . $this->ids('https://cloud.example.com/f/OK'), ':' . $external));

        self::assertSame([$external], self::attaches($output));
    }

    public function testNothingIsEverFetched(): void {
        $parameters = (new \ReflectionClass(OutputReferenceFilter::class))->getConstructor()->getParameters();
        $types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), $parameters);

        self::assertNotContains(\OCP\Http\Client\IClientService::class, $types);
        foreach ($types as $type) {
            self::assertStringNotContainsStringIgnoringCase('http', $type, 'the filter holds no HTTP client');
        }
    }

    public function testAttachmentIdPassesOnlyForAnAllowedFileOfTheSameConversation(): void {
        $this->roomShare(11, $this->okId);
        $this->roomShare(12, $this->inheritedId);
        $this->roomShare(13, $this->hiddenId);
        $this->roomShare(14, $this->okId, 'other-room');
        $this->roomShare(15, 987654);
        $filter = $this->filter();

        self::assertSame(11, $filter->attachmentId('alice', 'room1', 11));
        foreach ([12, 13, 14, 15, 16] as $id) {
            self::assertNull($filter->attachmentId('alice', 'room1', $id), "share $id");
        }
        self::assertNull($filter->attachmentId('alice', 'room1', null));
    }

    public function testAttachmentIdOfAFileOnlyOutsideTheScopeIsNull(): void {
        $this->tags = [];
        $this->roomShare(11, $this->okId);
        $this->roomShare(17, $this->otherId);
        $filter = $this->filter($this->docsScope());

        self::assertSame(11, $filter->attachmentId('alice', 'room1', 11));
        self::assertNull($filter->attachmentId('alice', 'room1', 17));
    }

    public function testAttachmentOfANonRoomShareIsNull(): void {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn(IShare::TYPE_LINK);
        $share->method('getSharedWith')->willReturn('room1');
        $share->method('getNodeId')->willReturn($this->okId);
        $this->shares['ocRoomShare:20'] = $share;

        self::assertNull($this->filter()->attachmentId('alice', 'room1', 20));
    }
}
