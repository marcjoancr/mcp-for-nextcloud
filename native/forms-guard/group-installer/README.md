# Owned Forms group installer candidate

This installer targets one closed disposable PostgreSQL qualification profile. It installs the separately reviewed Forms-owned group-lifetime bundle without replacing the comments selector, Core group classes, dispatcher, user session or Core schema. It does not enable Circle/provider7 or any public capability.

Use Python3 to invoke `install-groups.py` with explicit `--root`, immutable reviewed `--plan`, new private `--checkpoint`, and `install`, `validate`, `rollback` or `status`; `--dry-run` is source admission only. See RUNTIME-PLAN.md for all required inputs and independent gates. Native app980/c724 and matching production MCP must first pass their separate source-upgrade qualification. No general production or Maria support is claimed.

Pure scripts under tests use synthetic data/processes only. Full command/output evidence, source closure, meaningful mutants and the rejected/safer declaration preparation are retained in this review package. Actual installation, group lifecycle and recovery require separate runtime authorization and evidence.

This R1 correction adds composed-probe, full active network-map and durable epoch/helper-result recovery controls. The external review package retains REPORT-R1.md and the fix-only patch for the three reviewed findings; the original unexecuted freeze remains separate.

Helper processes inherit only PATH and the three public PHP INI defaults of the pinned image (`PHP_MEMORY_LIMIT=512M`, `PHP_UPLOAD_LIMIT=512M`, `PHP_OPCACHE_MEMORY_CONSUMPTION=128`). Missing or different defaults refuse admission; application credentials and other environment values are not inherited. Configured Docker endpoints are checked separately from active attachments, including stopped containers and newly created helpers; the original observed MAC is verified after the final start. The local data proof covers at most 32,768 entries and 2 GiB within 30 seconds, with complete byte/metadata equality.
