// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** Decode PostgreSQL COPY text, never execute SQL or expose credential rows in failures. */
function copyValue(text) {
  if (text === '\\N') return null;
  const bytes = Buffer.from(text), out = [];
  const escapes = { b: 8, f: 12, n: 10, r: 13, t: 9, v: 11 };
  for (let i = 0; i < bytes.length; i++) {
    if (bytes[i] !== 92) { out.push(bytes[i]); continue; }
    if (++i === bytes.length) throw new Error('Invalid COPY escape');
    const next = String.fromCharCode(bytes[i]);
    if (Object.hasOwn(escapes, next)) out.push(escapes[next]);
    else if (/[0-7]/.test(next)) {
      let digits = next;
      while (digits.length < 3 && i + 1 < bytes.length && /[0-7]/.test(String.fromCharCode(bytes[i + 1]))) digits += String.fromCharCode(bytes[++i]);
      out.push(Number.parseInt(digits, 8));
    } else if (next === 'x') {
      let digits = '';
      while (digits.length < 2 && i + 1 < bytes.length && /[0-9a-f]/i.test(String.fromCharCode(bytes[i + 1]))) digits += String.fromCharCode(bytes[++i]);
      if (!digits) throw new Error('Invalid COPY hex escape');
      out.push(Number.parseInt(digits, 16));
    } else out.push(bytes[i]);
  }
  return new TextDecoder('utf-8', { fatal: true }).decode(Uint8Array.from(out));
}
function canonical(rows) {
  return rows.map(row => JSON.stringify(Object.fromEntries(Object.keys(row).sort().map(key => [key,
    row[key] === null ? null : typeof row[key] === 'boolean' ? (row[key] ? 't' : 'f') : String(row[key]),
  ])))).sort();
}
export function verifyPolicyDump(sql, expected) {
  const tables = {}, seen = new Set();
  let table = null, columns;
  for (const line of sql.split('\n')) {
    if (table !== null) {
      if (line === '\\.') { table = null; continue; }
      const values = line.split('\t');
      if (values.length !== columns.length) throw new Error('Invalid COPY column count');
      tables[table].push(Object.fromEntries(columns.map((key, index) => [key, copyValue(values[index])])));
      continue;
    }
    const header = /^COPY public\.oc_([a-z_]+) \(([a-z0-9_", ]+)\) FROM stdin;$/.exec(line);
    if (!header) continue;
    table = header[1]; columns = header[2].split(', ').map(name => {
      if (!/^(?:[a-z_][a-z0-9_]*|"[a-z_][a-z0-9_]*")$/.test(name)) throw new Error('Invalid COPY column');
      return name.replaceAll('"', '');
    });
    if (seen.has(table)) throw new Error('Duplicate COPY table');
    seen.add(table); tables[table] = [];
  }
  if (table !== null) throw new Error('Unterminated COPY table');
  for (const [name, rows] of Object.entries(expected)) {
    if (!Object.hasOwn(tables, name)) throw new Error(`Policy dump table missing: ${name}`);
    const actual = tables[name].filter(row => name === 'migrations' ? row.app === 'mcp' : name === 'preferences' ? row.appid === 'mcp' : true);
    if (JSON.stringify(canonical(actual)) !== JSON.stringify(canonical(rows))) throw new Error(`Policy oracle differs from dump: ${name}`);
  }
  if (!Object.keys(expected).length) throw new Error('Empty policy oracle');
  return Object.keys(expected).length;
}
