# Maintenance Guide

This short checklist makes it easy to rehearse and diagnose an update.

## 1. Run The Tests
```
php scripts/lib/tests/development/Runner.php
```
Runs the development suite (self-contained, no system changes). Ensure it
passes before packaging or deploying changes.

## 2. Dry-Run The Updater
```
/scripts/update.php --dry-run --scriptonly --verbose
```
This executes the logging pipeline without mutating the system. The summary is
still printed, and the profiler records each step with `status=SKIP`.

## 3. Capture Structured Logs
```
/scripts/update.php --jsonlog --profile-output=/var/log/pmss-update.profile.json
```
- `/var/log/pmss-update.jsonl` receives one JSON object per step.
- The profile output file stores the full runtime breakdown.
- Combine with `--dry-run` for a rehearsal log you can share with teammates.

## 4. Review Log Rotation
`/etc/logrotate.d/pmss-update` is installed automatically and rotates the text
log, JSON log, and profile snapshot daily (7 copies, compressed, copytruncate).
Verify the file exists and tweak the template under
`etc/seedbox/config/template.logrotate.pmss` if retention needs to change.

## 5. Confirm Version Metadata
After a real run, `/etc/seedbox/config/version` contains the canonical spec plus
timestamp, e.g.
```
git/main:2025-01-01@2025-01-02 03:04
```
`version.meta` records the resolved branch, commit, and log destinations in a
human-readable JSON structure for audits.

## 6. Preserve dpkg Baseline
`scripts/lib/update/dpkg/selections.txt` (and the per-release variants
`selections-debian10.txt`, `selections-debian11.txt`, `selections-debian12.txt`)
are captured from production. Do **not** edit them without approval. The
Bookworm snapshot is derived from the Bullseye list and should be validated in
testing before production rollout.
- Be cautious with `install.sh`; it has been in service for over a decade and should only be modified when absolutely necessary.
- `install.sh` is purely a bootstrap into `update.php`—keep it minimal:
  1. Ensure core tooling is present (`bash`, `php` CLI, `git`, `curl`, `wget`,
     `ca-certificates`, `rsync`).
  2. Fetch or clone the repository so `/scripts`, `/etc`, and `/var` are staged
     locally.
  3. Invoke `update.php` with any positional arguments supplied by the operator.
- To refresh a snapshot: run `dpkg --get-selections > selections-debianXX.txt`
  on a live host, then remove any `deinstall` entries before committing.
- After provisioning on a real host, gather a human-readable snapshot with:
```
/scripts/util/systemTest.php
```
This lists binary versions, configuration presence, and other health probes for
the production environment.
- For machine-readable output suitable for dashboards or pipelines, run:
```
/scripts/util/componentStatus.php --json
```
The command emits structured JSON describing binary/config status without
mutating the system.
