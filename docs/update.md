# Update Workflow

PMSS updates in two deliberate phases. `scripts/update.php` handles snapshot
selection and staging while `scripts/util/update-step2.php` performs the
heavyweight configuration work after the full repository is in place.

## Phase 1 – `scripts/update.php`

Responsibilities:
- Parse the requested source (`git/<branch>`, `release[:tag]`, or a custom repo)
  and optional flags.
- Fetch the snapshot (shallow git clone or release tarball).
- Stage the snapshot by replacing `/scripts` and refreshing `/etc` and `/var`
  trees. `/scripts` and `/etc/skel` are wiped before copying so stale files do
  not survive.
- Record the selected version under `/etc/seedbox/config/version` and
  `version.meta`.
- Re-run itself once if the fetched snapshot updated `update.php`.
- Invoke phase 2 unless explicitly skipped.

Common flags:

```
/scripts/update.php [<spec>] [--repo=<url>] [--branch=<name>] \
    [--dry-run] [--dist-upgrade] [--scripts-only]
```

- `--dry-run` – exercise the staging logic without copying or running phase 2.
- `--dist-upgrade` – run `scripts/util/update-dist-upgrade.php` and exit.
- `--scripts-only` – deploy the new `/scripts` and `/etc/skel` content but skip
  `update-step2.php`; useful for emergency repairs.
- `--repo`/`--branch` – override the default repository when building a `git/*`
  spec on the fly.

Version specs normalise user input so `main`, `git main`, and `git/main` produce
identical results. If no spec is supplied the previously recorded one is reused,
falling back to `git/main`.

Every run emits structured events to `/var/log/pmss-update.jsonl`, making it easy
to audit which spec was applied, whether the run was dry, and if phase 2 was
invoked.

## Phase 2 – `scripts/util/update-step2.php`

Phase 2 executes with the full repository mounted locally, so it may load shared
helpers from `scripts/lib/update/…`. The orchestrator is intentionally thin and
mostly wires together specialised modules:

```
scripts/lib/update/distro.php          # OS detection and legacy self-heal
scripts/lib/update/environment.php     # dpkg/apt environment guards
scripts/lib/update/repositories.php    # sources.list templates and apt refresh
scripts/lib/update/systemPrep.php      # cgroups, slices, base locale and perms
scripts/lib/update/webStack.php        # lighttpd/nginx lifecycle
scripts/lib/update/services/*          # runtime templates, legacy daemons,
                                       # mediainfo installer, security tweaks
scripts/lib/update/userMaintenance.php # per-user refresh and skeleton/cron sync
scripts/lib/update/networking.php      # network template seeding & rollout
scripts/lib/update/runtime/*           # shared runStep/logging/profile helpers
```

Environment hints captured by `install.sh` are passed via `PMSS_HOSTNAME`,
`PMSS_SKIP_HOSTNAME`, `PMSS_QUOTA_MOUNT`, and `PMSS_SKIP_QUOTA`; phase 2 honors
those flags when reapplying legacy hostname/quota defaults.

Execution outline:
1. Detect distro name/version/codename and ensure `update.php` is up to date.
2. Enforce non-interactive apt settings and finish any pending dpkg configs.
3. Immediately refresh APT repositories and install every queued package _before_ any
   other orchestration (this ordering is mandatory for all future regressions). #TODO Once
   the dpkg baselines include the full package set we can drop the per-app queue entirely.
4. Prepare the host (cgroups, systemd slices, base permissions, MOTD, locales) and
   reapply legacy installer defaults (sysctl tuning, root shell config, `/home`
   permissions, hostname/quota overrides exported by `install.sh`).
5. Apply repository templates, refresh apt indexes, migrate legacy files.
6. Run application installers under `scripts/lib/update/apps/*.php`.
7. Configure the web stack, disable legacy daemons, and install supporting
   packages (e.g., mediainfo, Let’s Encrypt helpers).
8. Update every user environment via `pmssUpdateUserEnvironment` and rescan
   skeletons, crontabs, and logrotate policies.
9. Reapply network templates, apply security hardening, summarise profiling, and
   log completion markers.

Every step flows through the shared `runStep()` helper which logs to
`pmss-update.log`, records JSON events, and collects profiling metadata. When
`PMSS_DRY_RUN=1` the orchestration still logs planned work but skips execution.

## Usage Examples

Upgrade to the latest release:
```
/scripts/update.php release
```

Deploy the `wireguard` branch but skip phase 2 (useful for hotfixing scripts):
```
/scripts/update.php git/wireguard --scripts-only
```

Dry-run a release update to inspect logging only:
```
/scripts/update.php release --dry-run
```

## Operational Tips

- Always run `php -l scripts/update.php` and
  `php scripts/lib/tests/development/Runner.php` after touching update logic.
- Check `/var/log/pmss-update.jsonl` for a structured summary of the last run;
  a missing `update_step2_end` event typically means phase 2 was skipped.
- When developing helpers under `scripts/lib/update`, mirror the existing
  pattern: one focused responsibility per file, concise docblocks, and reuse of
  the runtime helpers for logging.

Keeping the bootstrap minimal and the second phase modular allows PMSS to update
safely even on partially broken systems while keeping the complex logic in files
that are easy to test and reason about.
