// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { checkedFile } from '../app-archive.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';

const hookSha = '1a3c0d4e4223b6e7f6d6730353afab022b2554edcb6c6961d571e3fe71dc2617';
const packagesSha = '29690dc95bf5cfd85383643f656f2bdf3dea3ff241050fc76c79a430895fd2c6';
const hookTarget = '/custom-cont-init.d/50-ocrmypdf.sh';

export async function stageHostStartup(root, inputs) {
  const stage = path.join(root, 'host-startup');
  await fs.mkdir(stage);
  const hook = path.join(stage,'hook.sh');
  await checkedFile(path.join(inputs,'hook.sh'),hook,hookSha,335);
  await fs.chmod(hook,0o555);
  const manifest = path.join(stage,'package-manifest.json');
  await checkedFile(path.join(here,'alpine-ocr-packages.json'),manifest,packagesSha,26756);
  const pins = JSON.parse(await fs.readFile(manifest));
  for (const name of ['main','community']) {
    const repo = path.join(name,'x86_64');
    await fs.mkdir(path.join(stage,'repo',repo),{recursive:true});
    const index = path.join(inputs,repo,'APKINDEX.tar.gz');
    await checkedFile(index,path.join(stage,'repo',repo,'APKINDEX.tar.gz'),pins[name+'IndexSha256'],(await fs.lstat(index)).size);
  }
  for (const row of pins.packages) {
    const relative = path.join(row.repository,'x86_64',row.filename);
    await checkedFile(path.join(inputs,relative),path.join(stage,'repo',relative),row.sha256,row.bytes);
  }
  const repositories = path.join(stage,'repositories');
  await fs.writeFile(repositories,'file:///repo/main\nfile:///repo/community\n',{mode:0o444});
  return ['-v',hook+':'+hookTarget+':ro','-v',path.join(stage,'repo')+':/repo:ro','-v',repositories+':/etc/apk/repositories:ro'];
}

export async function verifyHostStartup(c, label) {
  await c.wait('native custom-init OCR hook',async () => {
    const output = await command('docker',['logs',c.app]);
    if (output.code) throw new Error('Cannot verify custom-init status');
    const exit = /\[custom-init\] 50-ocrmypdf\.sh: exited (\d+)/.exec(output.out + output.err);
    if (exit && exit[1] !== '0') throw new Error('OCR hook installation failed (' + exit[1] + ')');
    return Boolean(exit);
  });
  const langs = (await c.exec(c.app,['tesseract','--list-langs'])).split(/\s+/);
  if (!['eng','spa','osd'].every(lang => langs.includes(lang))) throw new Error('OCR languages missing after startup');
  const ocr = (await c.exec(c.app,['ocrmypdf','--version'])).trim();
  if (ocr !== '16.11.1') throw new Error('Unexpected OCRmyPDF version');
  // Render a synthetic raster page; require recognized text, not just a generated PDF.
  const scan = `set -eu
dir=$(mktemp -d); trap 'rm -rf "$dir"' EXIT; cd "$dir"
printf '%s\\n' '%!PS' '/Helvetica-Bold findfont 36 scalefont setfont' '60 600 moveto (OCR validation 2026) show' 'showpage' > page.ps
gs -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pnggray -r150 -sOutputFile=page.png page.ps
ocrmypdf --jobs 1 --image-dpi 150 --language eng+spa --sidecar words.txt page.png result.pdf >/dev/null 2>&1
test -s result.pdf && grep -Eiq 'validation[[:space:]]+2026' words.txt`;
  await c.exec(c.app,['s6-setuidgid','abc','sh','-ec',scan]);
  await c.record(label+'-host-hook',{hookSha256:hookSha,packageManifestSha256:packagesSha,ocrmypdf:ocr,languages:['eng','spa','osd'],syntheticOcr:true});
}
