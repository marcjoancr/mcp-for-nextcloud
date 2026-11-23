<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Resources;

use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\Calendar\Resource\IResourceMetadata;
use OCP\Calendar\Room\IRoomMetadata;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/** Resolves native CalDAV room/resource principals only after an ACL-filtered DAV search. */
final class CalendarResourceDirectory
{
    public const ROOM = 'ROOM';
    public const RESOURCE = 'RESOURCE';
    private const PREFIXES = [self::ROOM => 'principals/calendar-rooms', self::RESOURCE => 'principals/calendar-resources'];
    private const DISPLAYNAME = '{DAV:}displayname';
    private const EMAIL = '{http://sabredav.org/ns}email-address';

    public function __construct(
        private \Closure $rooms,
        private \Closure $resources,
        private IUserSession $session,
        private IUserManager $users,
    ) {}

    /** @return list<array{id:string,type:string,name:string,email:string,metadata:array<string,string>}> */
    public function search(string $actorId, string $type, string $query, int $limit = 50): array
    {
        $this->assertActor($actorId);
        $types = strtoupper($type) === 'ALL' ? [self::ROOM, self::RESOURCE] : [$this->normalizeType($type)];
        $out = [];
        foreach ($types as $kind) {
            $backend = $this->backend($kind);
            if ($backend === null) continue;
            $prefix = self::PREFIXES[$kind];
            // Nextcloud's native AbstractPrincipalBackend applies group_restrictions here.
            $uris = $backend->searchPrincipals($prefix, [self::DISPLAYNAME => $query], 'allof');
            foreach (array_slice(array_values(array_unique(array_filter($uris, 'is_string'))), 0, $limit - count($out)) as $uri) {
                $principal = $backend->getPrincipalByPath($uri);
                if (!is_array($principal)) continue;
                $out[] = $this->publicPrincipal($uri, $kind, $principal);
            }
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Resolves an explicit ID only if it appears in the actor-filtered principal search. */
    public function resolve(string $actorId, string $type, string $id): array
    {
        $this->assertActor($actorId);
        $kind = $this->normalizeType($type);
        $backend = $this->backend($kind);
        if ($backend === null) throw CalendarException::notFound();
        $prefix = self::PREFIXES[$kind];
        $id = preg_replace('/^principal:/', '', $id) ?? $id;
        if (!str_starts_with($id, $prefix . '/')) throw CalendarException::notFound();
        $visible = $backend->searchPrincipals($prefix, [self::DISPLAYNAME => ''], 'allof');
        if (!in_array($id, $visible, true)) throw CalendarException::notFound();
        // findByUri is a native lookup, not an ACL check. The membership check above is mandatory.
        $path = $backend->findByUri('principal:' . $id, $prefix);
        if ($path !== $id) throw CalendarException::notFound();
        $principal = $backend->getPrincipalByPath($path);
        if (!is_array($principal)) throw CalendarException::notFound();
        $resolved = $this->publicPrincipal($path, $kind, $principal);
        $this->assertUniqueAddress($resolved);
        return $resolved;
    }

    /** Prevent an external email change from being routed to a room/resource principal. */
    public function assertNotResourceAddress(string $actorId, string $address): void
    {
        $this->assertActor($actorId);
        $email = strtolower(trim(preg_replace('/^mailto:/i','',$address) ?? $address));
        foreach (self::PREFIXES as $type => $prefix) {
            $backend = $this->backend($type);
            if ($backend === null) continue;
            foreach ($backend->searchPrincipals($prefix,[self::EMAIL=>$email],'allof') as $uri) {
                if (!is_string($uri)) continue;
                $principal = $backend->getPrincipalByPath($uri);
                if (is_array($principal) && strtolower(trim((string)($principal[self::EMAIL] ?? ''))) === $email) {
                    throw new CalendarArgumentException('This address belongs to a room or resource; reserve it through calendar_reserve_resource.','add_attendee_emails','use resource reservation');
                }
            }
        }
    }

    private function assertActor(string $actorId): void
    {
        $user = $this->session->getUser();
        if ($user === null || $user->getUID() !== $actorId) throw CalendarException::notFound();
    }

    private function normalizeType(string $type): string
    {
        $normalized = strtoupper($type);
        if (!isset(self::PREFIXES[$normalized])) throw new \InvalidArgumentException('Resource type must be ROOM or RESOURCE.');
        return $normalized;
    }

    private function backend(string $type): ?BackendInterface
    {
        $provider = $type === self::ROOM ? $this->rooms : $this->resources;
        $backend = $provider();
        return $backend instanceof BackendInterface ? $backend : null;
    }

    /** Resource addresses may collide because the native backend search is a substring match. */
    private function assertUniqueAddress(array $resource): void
    {
        $email = strtolower(trim($resource['email']));
        if ($email === '' || $this->users->getByEmail($email) !== []) {
            throw CalendarException::blocked('The resource booking address is missing or ambiguous.');
        }
        $matches = [];
        foreach (self::PREFIXES as $type => $prefix) {
            $backend = $this->backend($type);
            if ($backend === null) continue;
            foreach ($backend->searchPrincipals($prefix, [self::EMAIL => $email], 'allof') as $uri) {
                if (!is_string($uri)) continue;
                $principal = $backend->getPrincipalByPath($uri);
                if (!is_array($principal)) continue;
                $candidate = strtolower(trim((string)($principal[self::EMAIL] ?? '')));
                $matches[] = ['principal' => $type . ':' . $uri, 'email' => $candidate];
            }
        }
        if (count($matches) !== 1 || $matches[0]['email'] !== $email || $matches[0]['principal'] !== $resource['type'] . ':' . $resource['id']) {
            throw CalendarException::blocked('The resource booking address is missing or ambiguous.');
        }
    }

    /** @param array<string,mixed> $principal @return array{id:string,type:string,name:string,email:string,metadata:array<string,string>} */
    private function publicPrincipal(string $uri, string $type, array $principal): array
    {
        $metadata = [];
        $allowed = $type === self::ROOM
            ? [IRoomMetadata::ROOM_TYPE, IRoomMetadata::CAPACITY, IRoomMetadata::BUILDING_ADDRESS, IRoomMetadata::BUILDING_STORY, IRoomMetadata::BUILDING_ROOM_NUMBER, IRoomMetadata::FEATURES]
            : [IResourceMetadata::RESOURCE_TYPE, IResourceMetadata::VEHICLE_TYPE, IResourceMetadata::VEHICLE_MAKE, IResourceMetadata::VEHICLE_MODEL, IResourceMetadata::VEHICLE_IS_ELECTRIC, IResourceMetadata::VEHICLE_RANGE, IResourceMetadata::VEHICLE_SEATING_CAPACITY];
        foreach ($allowed as $key) {
            $value = $principal[$key] ?? null;
            if (is_scalar($value)) $metadata[$key] = (string)$value;
        }
        return [
            'id' => $uri,
            'type' => $type,
            'name' => (string)($principal['{DAV:}displayname'] ?? ''),
            'email' => (string)($principal[self::EMAIL] ?? ''),
            'metadata' => $metadata,
        ];
    }
}
