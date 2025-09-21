# Cron Jobs Overview

The system relies on several cron tasks under `/scripts/cron`.
A system-wide crontab `/etc/seedbox/config/root.cron` is installed via
`setupRootCron.php`.

The rootless Docker watchdog `checkRootlessDocker.php` keeps each user's
Docker daemon running:

```
*/5 * * * * root /scripts/cron/checkRootlessDocker.php >> /var/log/pmss/rootlessDocker.log 2>&1
```

Add this line to the root crontab if it is not present.

WireGuard health check (`checkWireguard.php`) reloads the kernel module if needed
and restarts `wg-quick@wg0` when required:

```
*/5 * * * * root /scripts/cron/checkWireguard.php >> /var/log/pmss/checkWireguard.log 2>&1
```

User database cleanup (`cleanupUserDb.php`) prunes stale entries from
`/etc/seedbox/runtime/users.json` every night:

```
30 2 * * * root /scripts/cron/cleanupUserDb.php >> /var/log/pmss/userDbCleanup.log 2>&1
```
