<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# MinicoLab startup hook qualification

**2026-10-31 result.** The audited OCR hook worked in two fresh standalone
containers and all three sealed Nextcloud service containers in the integrated
devbox replay. Startup depends on a working package source at every recreation.
[Production decision and rollback reserve](minicolab-production-window.md)

## Pinned inputs and source check

The fixture used the audited LinuxServer image
`lscr.io/linuxserver/nextcloud:version-35.0.2@sha256:e7f790011bf023d33517b4be70231d7aa2e19d54c619664a8533e8bedd4716ac`.
It runs Alpine 3.24.2. The four stock scripts for Nextcloud configuration,
Nginx, permissions and cron matched the host audit byte for byte.

The authorized host read returned the original 335-byte executable hook with
SHA-256 `1a3c0d4e4223b6e7f6d6730353afab022b2554edcb6c6961d571e3fe71dc2617`.
Its source stays private. It invokes `apk add --no-cache` for OCRmyPDF and
the English, Spanish and orientation/script detection models. Static inspection,
`bash -n` and ShellCheck passed. The script invokes no Nextcloud updater.

| Installed on the host on 31 October | Version |
| --- | --- |
| OCRmyPDF | 16.11.1-r2 |
| Tesseract and its `eng`, `spa` and `osd` language packages | 5.5.2-r0 |
| Ghostscript | 10.08.0-r0 |

## Standalone control and package supply

In two fresh containers, the image-provided `with-contenv` wrapper ran the
original hook with `--network none`, the verified local package mirror mounted
read-only, and no published ports. APK accepted the signed Alpine v3.24 indexes
and the 80 pinned archives (71,328,933 bytes). The `main` index hashed to
`e652eb0f9eaa4239512cee071c0149aaf4f153b5c54239e0f6ef800df1ff698a`
and the `community` index to
`baa48fec68b77366c6cfdbc70dacca3bf9714720c7d882c88c9d0ae22fe781de`.
The package manifest SHA-256 is
`29690dc95bf5cfd85383643f656f2bdf3dea3ff241050fc76c79a430895fd2c6`.
[Public package inventory](../../../mcp/tests/Integration/ci/linuxserver/alpine-ocr-packages.json)

Each run processed an image-only PDF and recognized
`MCP OCR FIXTURE ALPHA SEVEN`. A fresh container with neither the mirror nor
outbound access returned status 4 from the hook. All standalone fixtures were
removed. The private probe and mirror are retained under
`mcp/build/production-qualification/` in the preparation worktree.

The stock image points to HTTP Alpine repositories. The hook requests package
names without pinning versions. A fresh production container therefore needs
a verified local package source or approved access to an available repository.
The staged mirror removes the external download dependency for this profile.

## Integrated service replay

The integrated rehearsal mounted the exact executable hook at its original
path, the verified package snapshot at `/repo`, and an APK repository list
pointing only to the two local signed views. All three service containers used
the stock LinuxServer launcher with read-only `/app` and `/config`, port
publication disabled, and the operator's request gate closed at startup.

Before admitting the synthetic client, each generation checked that the
launcher reported exit 0 for this hook, OCRmyPDF reported 16.11.1, Tesseract
listed `eng`, `spa` and `osd`, and UID 1000 could recognize text from a
rasterized page using English and Spanish. The same checks passed after the
operator updated signed Notes and after another container recreation. The
maintenance container ran CLI without web or cron services.

The launcher invokes the executable and logs its exit code. Its shell continues
after that log statement. Operational admission should check the hook and an
OCR result explicitly. Web availability alone cannot distinguish a failed
package installation. This replay used an offline mirror. Production mirror
provisioning or production outbound reachability remains an operator decision.
No production service or data was used in the isolated trials.
