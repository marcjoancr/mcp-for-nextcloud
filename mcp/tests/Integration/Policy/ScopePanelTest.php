<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Service\Scope\NativeResourceEnumerator;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Server;
use RuntimeException;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Throwable;

/**
 * B7 on the real server: the exact requests the resource scope panel sends, over HTTP from a signed-in administrator
 * session (cookies and requesttoken, as the browser sends them), against the routes of B2 and the matrix and
 * personal routes. Calendars live in the container's DAV app; the matrix cell and the personal page read back what
 * the panel saved; a conflict writes nothing; Files is browsable but refused on save while its barrier is closed.
 * It also measures the folder picker on a wide folder (pending item F3 of the B2 review): the native listing
 * materializes the whole folder before the page of 50.
 */
final class ScopePanelTest extends IntegrationTestCase {
    private const ADMIN = 'admin';
    private const WIDE = 1000;

    private string $run = '';
    /** @var list<int> */
    private array $calendars = [];
    /** @var array{cookie:string, token:string}|null */
    private static ?array $session = null;

    protected function setUp(): void {
        parent::setUp();
        $this->run = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE))))->executeStatement();
        }
        foreach ($this->calendars as $id) {
            try {
                Server::get(CalDavBackend::class)->deleteCalendar($id, true);
            } catch (Throwable) {
            }
        }
        try {
            $this->home()->get('mcp-it-wide-' . $this->run)->delete();
        } catch (Throwable) {
        }
        parent::tearDown();
    }

    private function home(): Folder {
        return Server::get(IRootFolder::class)->getUserFolder(Fixtures::ALICE);
    }

    private function calendar(string $uri): int {
        $id = (int)Server::get(CalDavBackend::class)->createCalendar('principals/users/' . Fixtures::ALICE, $uri, [
            '{DAV:}displayname' => $uri,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
        ]);
        $this->calendars[] = $id;
        return $id;
    }

    /** @return array{cookie:string, token:string} a session of the administrator, opened like a browser that signed in */
    private static function session(): array {
        if (self::$session !== null) {
            return self::$session;
        }
        $response = Http::request('GET', Http::BASE . '/index.php/csrftoken', [
            'Authorization: ' . LoginFactory::basic(self::ADMIN)->authorization(), 'Accept: application/json',
        ]);
        preg_match_all('/^Set-Cookie:\s*([^=;\s]+=[^;\r\n]*)/mi', $response['headers'], $cookies);
        $token = json_decode($response['body'], true)['token'] ?? null;
        if ($response['status'] !== 200 || !is_string($token) || $cookies[1] === []) {
            throw new RuntimeException('No administrator session: HTTP ' . $response['status']);
        }
        return self::$session = ['cookie' => implode('; ', $cookies[1]), 'token' => $token];
    }

    /** @return array{status:int, body:array<string, mixed>} what the panel's fetch() receives */
    private function panel(string $method, string $path, ?array $body = null, ?array $session = null): array {
        $session ??= self::session();
        $response = Http::request($method, Http::BASE . '/index.php/apps/mcp' . $path, [
            'Cookie: ' . $session['cookie'], 'requesttoken: ' . $session['token'],
            'Accept: application/json', 'Content-Type: application/json',
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        return ['status' => $response['status'], 'body' => json_decode($response['body'], true) ?? []];
    }

    private function scopePath(string $module, string $suffix = ''): string {
        return '/api/scope/' . rawurlencode(Fixtures::ALICE) . '/' . $module . $suffix;
    }

    public function testS14ThePanelSavesUndoesAndShowsTheScopeWhereTheAdminAndTheUserReadIt(): void {
        $c1 = (string)$this->calendar("mcp-it-panel-a-{$this->run}");
        $c2 = (string)$this->calendar("mcp-it-panel-b-{$this->run}");

        $shown = $this->panel('GET', $this->scopePath('calendar'));
        $this->assertSame(200, $shown['status'], json_encode($shown['body']));
        $this->assertSame(['unrestricted', true, []], [$shown['body']['mode'], $shown['body']['active'], $shown['body']['items']]);
        $found = $this->panel('GET', $this->scopePath('calendar', '/candidates'), null)['body'];
        $this->assertContains($c1, array_column($found['items'], 'id'));
        $this->assertContains($c2, array_column($found['items'], 'id'));

        $saved = $this->panel('PUT', $this->scopePath('calendar'), ['expectedRevision' => $shown['body']['revision'], 'mode' => 'restricted', 'items' => [$c1]]);
        $this->assertSame(200, $saved['status'], json_encode($saved['body']));
        $this->assertSame([true, ['mode' => 'restricted', 'count' => 1]], [$saved['body']['changed'], $saved['body']['scope']]);
        $this->assertSame([[$c1, true, "mcp-it-panel-a-{$this->run}"]],
            array_map(static fn (array $i): array => [$i['id'], $i['found'], $i['name']], $this->panel('GET', $this->scopePath('calendar'))['body']['items']));

        // The stale view of a second tab: refused, nothing written, never retried.
        $stale = $this->panel('PUT', $this->scopePath('calendar'), ['expectedRevision' => $shown['body']['revision'], 'mode' => 'restricted', 'items' => [$c2]]);
        $this->assertSame(409, $stale['status']);
        $this->assertSame([$c1], array_column($this->panel('GET', $this->scopePath('calendar'))['body']['items'], 'id'));

        // The matrix cell and the personal page read the same scope.
        $matrix = $this->panel('GET', '/api/grants?' . http_build_query(['search' => Fixtures::ALICE]));
        $row = array_values(array_filter($matrix['body']['users'] ?? [], static fn (array $u): bool => $u['uid'] === Fixtures::ALICE))[0] ?? null;
        $this->assertSame(['mode' => 'restricted', 'count' => 1], $row['scope']['calendar'] ?? null, json_encode($matrix['body']));
        $this->assertSame(['mode' => 'unrestricted', 'count' => null], $row['scope']['tasks']);
        $mine = Http::request('GET', Http::BASE . '/index.php/apps/mcp/api/my/scope', [
            'Authorization: ' . LoginFactory::basic(Fixtures::ALICE)->authorization(), 'Accept: application/json',
        ]);
        $this->assertSame(200, $mine['status'], $mine['body']);
        $personal = json_decode($mine['body'], true)['modules']['calendar'];
        $this->assertSame(['restricted', [$c1]], [$personal['mode'], array_column($personal['items'], 'id')]);

        $undone = $this->panel('POST', '/api/grants/undo', ['receiptId' => $saved['body']['receiptId'], 'expectedRevision' => $saved['body']['revision']]);
        $this->assertSame(200, $undone['status'], json_encode($undone['body']));
        $this->assertSame('unrestricted', $this->panel('GET', $this->scopePath('calendar'))['body']['mode']);
    }

    public function testS14FilesIsBrowsableButItsSaveIsRefusedWhileItsBarrierIsClosed(): void {
        $folder = $this->home()->newFolder('mcp-it-wide-' . $this->run);
        $shown = $this->panel('GET', $this->scopePath('files'))['body'];
        $this->assertFalse($shown['active'], 'the panel says the Files scope does not apply yet');
        $root = $this->panel('GET', $this->scopePath('files', '/candidates'), null);
        $this->assertSame(200, $root['status']);
        $this->assertContains((string)$folder->getId(), array_column($root['body']['items'], 'id'));

        $refused = $this->panel('PUT', $this->scopePath('files'), ['expectedRevision' => $shown['revision'], 'mode' => 'restricted', 'items' => [(string)$folder->getId()]]);
        $this->assertSame([422, 'ScopeInactive'], [$refused['status'], $refused['body']['error'] ?? null]);
        $this->assertSame('unrestricted', $this->panel('GET', $this->scopePath('files'))['body']['mode']);
    }

    public function testANonAdministratorIsRefusedBeforeTheTargetIsRead(): void {
        $bob = Http::request('GET', Http::BASE . '/index.php/csrftoken', ['Authorization: ' . LoginFactory::basic(Fixtures::BOB)->authorization(), 'Accept: application/json']);
        preg_match_all('/^Set-Cookie:\s*([^=;\s]+=[^;\r\n]*)/mi', $bob['headers'], $cookies);
        $session = ['cookie' => implode('; ', $cookies[1]), 'token' => (string)(json_decode($bob['body'], true)['token'] ?? '')];
        foreach ([['GET', $this->scopePath('calendar')], ['GET', $this->scopePath('calendar', '/candidates')], ['PUT', $this->scopePath('calendar')]] as [$method, $path]) {
            $answer = $this->panel($method, $path, $method === 'PUT' ? ['expectedRevision' => '1', 'mode' => 'unrestricted'] : null, $session);
            $this->assertSame(403, $answer['status'], "$method $path");
            $this->assertArrayNotHasKey('items', $answer['body']);
        }
    }

    /** F3 of the B2 review: one page of the folder picker over a folder of WIDE subfolders, measured and recorded. */
    public function testTheFolderPickerPagesAWideFolder(): void {
        $wide = $this->home()->newFolder('mcp-it-wide-' . $this->run);
        for ($i = 0; $i < self::WIDE; $i++) {
            $wide->newFolder(sprintf('f%04d', $i));
        }
        $started = hrtime(true);
        $page = Server::get(NativeResourceEnumerator::class)->candidates(Fixtures::ALICE, 'files', '', 1, (string)$wide->getId());
        $service = (hrtime(true) - $started) / 1e6;
        $started = hrtime(true);
        $http = $this->panel('GET', $this->scopePath('files', '/candidates'), null);
        $over = $this->panel('GET', $this->scopePath('files', '/candidates') . '?' . http_build_query(['parent' => (string)$wide->getId(), 'page' => '20']));
        $route = (hrtime(true) - $started) / 1e6;

        $this->assertSame([self::WIDE, 50, true], [$page['total'], count($page['items']), $page['hasMore']]);
        $this->assertSame(200, $http['status']);
        $this->assertSame([200, 50, false], [$over['status'], count($over['body']['items'] ?? []), $over['body']['hasMore'] ?? null], 'the last page');
        file_put_contents('/tmp/mcp-it-observations.txt', sprintf("[%s] folder picker over %d subfolders: service page 1 %.0f ms; two HTTP pages %.0f ms; memory peak %d MiB\n",
            self::class, self::WIDE, $service, $route, intdiv(memory_get_peak_usage(true), 1048576)), FILE_APPEND);
        $this->assertLessThan(30000.0, $service, 'a page of a wide folder stays usable');
    }
}
