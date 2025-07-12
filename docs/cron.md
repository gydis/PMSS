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
