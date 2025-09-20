# Update Workflow

PMSS uses a two‑phase updater. The main entry point is `update.php`, which refreshes the scripts from GitHub and then invokes `util/update-step2.php` for configuration changes.

```
/scripts/update.php [<source-spec>] [--scriptonly] [--verbose]
```

`<source-spec>` defines what to update to. Examples from the script header:

```
release                  # latest GitHub release
release:2025-07-12       # explicit tag
git/main                 # branch "main" from default repo
git/dev:2024-12-05       # branch "dev" pinned to a past date
git/https://url/repo.git:beta[:2025-01-01]  # custom repo
```

Options:
- **--scriptonly** – download and deploy the scripts without running phase 2
- **--verbose** – print debug information

After copying files, phase 2 (`update-step2.php`) performs system-level tasks such as package installation, configuration tweaks and user environment updates.

**Notable behaviours in phase 2**
- Repository stanzas are sourced from `/etc/seedbox/config/template.sources.*` and the updater keeps `/etc/apt/sources.list.pmss-backup` before writing new content, making manual recovery easier.
- Firewall rules are rendered as complete tables and applied with `iptables-restore` for atomic updates. When that fails the script logs a warning and falls back to sequential `iptables` calls so networking remains available.
- Any missing runtime directories (for example `/var/run/pmss`) are recreated with safe permissions, and detailed messages land in `/var/log/pmss-update.log` for troubleshooting.
- The version selector now normalises bare inputs (for example `main`, `git main`, or `release 2025-07-12`) and defaults to `git/main` when it cannot parse a value. The recorded version file stores the canonical spec followed by `@YYYY-MM-DD HH:MM`, preserving a human-readable timestamp without breaking future runs.
- Phase 2 accepts `--dry-run`, which executes the logging path without mutating the system, and `--jsonlog`, which mirrors each step into `/var/log/pmss-update.jsonl` for structured ingest.

Example for upgrading to the current release:

```
/scripts/update.php release
```

**Documentation quality**: The update process is extensive and only partially documented in comments. Additional user-facing notes on rollback and expected downtime would be helpful.
