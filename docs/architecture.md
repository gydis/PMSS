# PMSS Architecture Overview

A quick map for agents touching this repository:

## Usage Notes (agents only)
- Keep this doc compact; use it as a jumping-off point when diving into unfamiliar code.
- Testing philosophy: maintain fast dev-time suites that avoid network/system mutations, and plan for separate production probes that capture real-world health (package presence, service status) for logs once implemented.

## Bootstrap Flow
1. **install.sh** – Minimal installer that ensures base tools (bash, php, git, curl, wget, ca-certificates, rsync) are present, fetches `/scripts`/`/etc`/`/var`, then invokes `/scripts/update.php` with any CLI args. Treat it as a thin wrapper.
2. **update.php** – Fetches the requested snapshot (git branch, release), stages the tree, records `/etc/seedbox/config/version`, re-runs itself if updated, then hands off to `update-step2.php`. Logs to `/var/log/pmss-update.log` and `/var/log/pmss-update.jsonl`.
3. **update-step2.php** – Main orchestrator. Detects distro from `/etc/os-release`, resolves the apt suite (codename > VERSION_ID, otherwise skip with warning), refreshes repos, flushes package queues, then runs helper modules under `scripts/lib/update/`.

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

## Config Templates
- `/etc/seedbox/config/template.*` (rc.local, systemd.conf, nginx, proftpd, etc.) are copied by service helpers.
- `/etc/seedbox/config/template.sources.<suite>` defines apt sources for each distro.

## Logs & Profile
- Plain log: `/var/log/pmss-update.log`
- JSON events: `/var/log/pmss-update.jsonl`
- Optional profile: `PMSS_PROFILE_OUTPUT` or `<json>.profile.json`
- `runStep()` in `install.sh` and update modules logs every command; respect fail-soft principle.
