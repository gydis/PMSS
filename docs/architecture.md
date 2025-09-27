# PMSS Architecture Overview

A quick map for agents touching this repository:

## Usage Notes (agents only)
- Keep this doc compact; use it as a jumping-off point when diving into unfamiliar code.
- Testing philosophy: maintain fast dev-time suites that avoid network/system mutations, and plan for separate production probes that capture real-world health (package presence, service status) for logs once implemented.
- Tests live under `scripts/lib/tests/development` (unit-style) and `scripts/lib/tests/production` (post-provision probes). Use the matching runner for each tier.
- Never break old users: upgrades must be backward compatible and data-safe; treat the existing fleet as immutable requirements.
- Contracts and invariants: each module must declare its pre/postconditions (e.g., package phase leaves services runnable) and tests should enforce them.
- Repo detection prefers `VERSION_CODENAME`; if neither codename nor numeric version is known the updater skips rewriting `sources.list` and logs a warning (preventing accidental downgrades).
- `scripts/util/systemTest.php` offers a read-only CLI probe of system readiness (binary versions, config presence). Run it only on real hosts after provisioning.

## Bootstrap Flow
Keep the canonical installer/update details under `docs/install.md` and
`docs/update.md`. This section highlights the responsibility breakpoints:

1. **install.sh** – Thin bootstrapper; ensures core tools exist and then defers
   to `update.php`. See [`docs/install.md`](./install.md) for the authoritative
   checklist.
2. **update.php** – Snapshot fetch + staging. Logging, argument parsing, and
   hand-off behaviour live in [`docs/update.md`](./update.md#phase-1--scriptsupdatephp).
3. **update-step2.php** – Orchestrator that consumes the staged tree and runs
   modules under `scripts/lib/update/`. Responsibilities and ordering are
   documented in [`docs/update.md`](./update.md#phase-2--scriptsutilupdate-step2php).

## Key Modules
- **scripts/lib/update/environment.php** – dpkg/apt guards plus helper to apply release-specific package selections.
- **scripts/lib/update/repositories.php** – Applies `/etc/seedbox/config/template.sources.<suite>` when version is known; otherwise logs and leaves sources untouched. Finishes with `apt update` via `runStep()`.
- **scripts/lib/update/systemPrep.php** – Cgroups, systemd slices, base permissions, locale setup.
- **scripts/lib/update/services/** – Runtime templates (rc.local, systemd, sshd), legacy service disablement, mediainfo installer, security tweaks.
- **scripts/lib/update/user/** – User maintenance (quota skeleton, permissions, ruTorrent refresh).
- **scripts/lib/update/apps/** – Application installers (rtorrent, deluge, docker, etc.) called during phase 2.

## Package Strategy
- Per-release bootstrap baselines live under `scripts/lib/update/dpkg/`; the installer only ensures core tools. Apps (Radarr, SabNZBd, etc.) are built via update-step2 modules.
- Release-specific dpkg snapshots: `scripts/lib/update/dpkg/selections-debian10/11/12.txt`. Apply via `pmssApplyDpkgSelections()` once per run (update-step2 picks the codename-resolved version or logs if unavailable).
- Dynamic installs use `pmssQueuePackages()` + `pmssFlushPackageQueue()`; per-app scripts queue what they need.
- Always run repository refresh + package installation at the start of phase 2;
  no other orchestration steps may run before APT completes.
- Tests that touch package logic should remain hermetic—seed inputs via temp files and environment overrides (e.g., `PMSS_OS_RELEASE_PATH`, `PMSS_APT_SOURCES_PATH`).

## Testing Layout
- Development runner: `php scripts/lib/tests/development/Runner.php`
- Production scaffolding: `php scripts/lib/tests/production/Runner.php`
- Shared helpers: `scripts/lib/tests/common/`
- CLI probes: `/scripts/util/systemTest.php`, `/scripts/util/componentStatus.php`

Development tests must avoid network/system changes; production tests and the CLI probe are intended for curated post-provision runs.

## Config Templates
- `/etc/seedbox/config/template.*` (rc.local, systemd.conf, nginx, proftpd, etc.) are copied by service helpers.
- `/etc/seedbox/config/template.sources.<suite>` defines apt sources for each distro.
- `etc/skel/www` is read-only for agents—never modify it without explicit user instruction.

## Logs & Profile
- Plain log: `/var/log/pmss-update.log`
- JSON events: `/var/log/pmss-update.jsonl`
- Optional profile: `PMSS_PROFILE_OUTPUT` or `<json>.profile.json`
- `runStep()` in `install.sh` and update modules logs every command; respect fail-soft principle.
