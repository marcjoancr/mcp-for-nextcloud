// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { randomInt } from 'node:crypto';
import { command } from './command.mjs';
function range(cidr) {
  const [address, prefix = '32'] = cidr.split('/');
  const parts = address.split('.').map(Number), bits = Number(prefix);
  if (parts.length !== 4 || parts.some(n => !Number.isInteger(n) || n < 0 || n > 255) || !Number.isInteger(bits) || bits < 0 || bits > 32) throw new Error('Invalid IPv4 route');
  const value = parts.reduce((n, octet) => n * 256 + octet, 0), size = 2 ** (32 - bits);
  const start = Math.floor(value / size) * size;
  return [start, start + size - 1];
}
export function chooseSubnet(occupied, start = randomInt(256)) {
  const ranges = occupied.filter(value => value !== 'default' && value !== '0.0.0.0/0' && !value.includes(':')).map(range);
  for (let n = 0; n < 256; n++) {
    const subnet = `10.250.${(start + n) % 256}.0/24`, [a, b] = range(subnet);
    if (ranges.every(([x, y]) => b < x || a > y)) return subnet;
  }
  throw new Error('No isolated fixture subnet available');
}
export async function fixtureSubnet(checked) {
  const routes = await command('ip', ['-j', 'route', 'show', 'table', 'all']);
  if (routes.code) throw new Error('Cannot audit host routes');
  const ids = (await checked(['network', 'ls', '-q'])).trim().split(/\s+/).filter(Boolean);
  const networks = ids.length ? JSON.parse(await checked(['network', 'inspect', ...ids])) : [];
  return chooseSubnet([...JSON.parse(routes.out).map(route => route.dst).filter(Boolean), ...networks.flatMap(network => (network.IPAM?.Config ?? []).map(config => config.Subnet)).filter(Boolean)]);
}
