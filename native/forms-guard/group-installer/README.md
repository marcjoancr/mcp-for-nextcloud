# Owned Forms group installer candidate

This is an unexecuted installer for one closed disposable PostgreSQL qualification profile. It installs the separately reviewed Forms-owned group-lifetime bundle without replacing the comments selector, Core group classes, dispatcher, user session or Core schema. It does not enable Circle/provider7 or any public capability.

Use Python3 to invoke `install-groups.py` with explicit `--root`, immutable reviewed `--plan`, new private `--checkpoint`, and `install`, `validate`, `rollback` or `status`; `--dry-run` is source admission only. See RUNTIME-PLAN.md for all required inputs and independent gates. Current PG948 is unsupported; app980 and matching MCP must first undergo their separate upgrade review/qualification. No general production or Maria support is claimed.

Pure scripts under tests use synthetic data/processes only. Full command/output evidence, source closure, meaningful mutants and the rejected/safer declaration preparation are retained in this review package. Actual installation, group lifecycle and recovery require separate runtime authorization and evidence.

This R1 correction adds composed-probe, full active network-map and durable epoch/helper-result recovery controls. The external review package retains REPORT-R1.md and the fix-only patch for the three reviewed findings; the original unexecuted freeze remains separate.
