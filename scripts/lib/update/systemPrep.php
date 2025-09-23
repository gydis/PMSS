<?php
/**
 * Base system preparation helpers executed during update-step2.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssEnsureCgroupsConfigured')) {
    /**
     * Guarantee that cgroup mounts and PID limits are configured sanely.
     */
    function pmssEnsureCgroupsConfigured(?callable $logger = null): void
    {
        $log   = pmssSelectLogger($logger);
        $fstab = @file_get_contents('/etc/fstab');
        if ($fstab === false || strpos($fstab, 'cgroup') === false) {
            runStep('Ensuring cgroup-bin package present', aptCmd('install -y -q cgroup-bin'));
            $mountLine = "\ncgroup  /sys/fs/cgroup  cgroup  defaults  0   0\n";
            if (@file_put_contents('/etc/fstab', $mountLine, FILE_APPEND) === false) {
                $log('[WARN] Unable to append cgroup mount to /etc/fstab');
            } else {
                $log('Appended cgroup mount configuration to /etc/fstab');
            }
            runStep('Mounting /sys/fs/cgroup', 'mount /sys/fs/cgroup');
        } else {
            $log('[SKIP] cgroup entry already present in /etc/fstab');
        }

        $rootPidSlice = '/sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max';
        if (file_exists($rootPidSlice)) {
            runStep('Raising PID limit for root user slice', "sh -c 'echo 100000 > {$rootPidSlice}'");
        } else {
            $log('[SKIP] Raising PID limit for root user slice (pids controller path missing)');
        }
    }
}

if (!function_exists('pmssEnsureSystemdSlices')) {
    /**
     * Install tuned systemd slice overrides when missing.
     */
    function pmssEnsureSystemdSlices(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);

        $obsolete = '/usr/lib/systemd/user-.slice.d/99-pmss.conf';
        if (file_exists($obsolete)) {
            @unlink($obsolete);
            $log('Removed obsolete user slice override '.$obsolete);
        }

        $target = '/usr/lib/systemd/system/user-.slice.d/15-pmss.conf';
        if (file_exists($target)) {
            $log('[SKIP] user slice override already present');
            return;
        }

        runStep('Installing user slice override template', 'cp -p /etc/seedbox/config/template.user-slices-pmss.conf '.$target);
        runStep('Setting permissions on user slice override', 'chmod 644 '.$target);
        runStep('Reloading systemd manager configuration', 'systemctl daemon-reload');
    }
}

if (!function_exists('pmssResetCorePermissions')) {
    /**
     * Normalise permissions on key configuration directories.
     */
    function pmssResetCorePermissions(): void
    {
        runStep('Resetting /etc/seedbox permissions', 'chmod -R 755 /etc/seedbox');
        runStep('Resetting /scripts permissions', 'chmod -R 750 /scripts');
    }
}

if (!function_exists('pmssEnsureLocaleBaseline')) {
    /**
     * Make sure essential locale assets exist before other services start.
     */
    function pmssEnsureLocaleBaseline(): void
    {
        runStep('Generating en_US.UTF-8 locale', 'locale-gen en_US.UTF-8');
        runStep('Setting default system locale', 'update-locale LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8');
        generateMotd();
    }
}

if (!function_exists('pmssReapplyLocaleDefinitions')) {
    /**
     * Reapply locale configuration to catch legacy installations.
     */
    function pmssReapplyLocaleDefinitions(): void
    {
        runStep('Ensuring en_US.UTF-8 locale is enabled', "sed -i 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/g' /etc/locale.gen");
        runStep('Regenerating locales', 'locale-gen');
        runStep('Setting default LANG in /etc/default/locale', "sed -i 's/LANG=en_US\\n/LANG=en_US.UTF-8/g' /etc/default/locale");
    }
}

if (!function_exists('pmssEnsureLegacySysctlBaseline')) {
    /**
     * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
     */
    function pmssEnsureLegacySysctlBaseline(?callable $logger = null): void
    {
        $log     = pmssSelectLogger($logger);
        $target  = '/etc/sysctl.d/1-pmss-defaults.conf';
        $content = <<<CONF
# Pulsed Media Config
block/sda/queue/scheduler = bfq
block/sdb/queue/scheduler = bfq
block/sdc/queue/scheduler = bfq
block/sdd/queue/scheduler = bfq
block/sde/queue/scheduler = bfq
block/sdf/queue/scheduler = bfq

block/sda/queue/read_ahead_kb = 1024
block/sdb/queue/read_ahead_kb = 1024
block/sdc/queue/read_ahead_kb = 1024
block/sdd/queue/read_ahead_kb = 1024
block/sde/queue/read_ahead_kb = 1024
block/sdf/queue/read_ahead_kb = 1024

net.ipv4.ip_forward = 1
CONF;

        $existing = @file_get_contents($target);
        if ($existing !== false && trim($existing) === trim($content)) {
            $log('[SKIP] Legacy sysctl defaults already present');
            return;
        }

        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        @file_put_contents($target, $content.PHP_EOL);
        runStep('Reloading sysctl configuration', 'sysctl --system');
        $log('Refreshed legacy sysctl defaults at '.$target);
    }
}

if (!function_exists('pmssConfigureRootShellDefaults')) {
    /**
     * Ensure root shell defaults mirror the historical installer behaviour.
     */
    function pmssConfigureRootShellDefaults(?callable $logger = null): void
    {
        $log    = pmssSelectLogger($logger);
        $bashrc = '/root/.bashrc';
        $lines  = file_exists($bashrc) ? file($bashrc, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $updates = [];
        $alias   = "alias ls='ls --color=auto'";
        $pathAdd = 'PATH=$PATH:/scripts';

        if (!in_array($alias, $lines, true)) {
            $lines[]   = $alias;
            $updates[] = $alias;
        }
        if (!in_array($pathAdd, $lines, true)) {
            $lines[]   = $pathAdd;
            $updates[] = $pathAdd;
        }

        if ($updates === []) {
            $log('[SKIP] Root shell defaults already configured');
            return;
        }

        @file_put_contents($bashrc, implode(PHP_EOL, $lines).PHP_EOL);
        $log('Appended root shell defaults: '.implode(', ', $updates));
    }
}

if (!function_exists('pmssProtectHomePermissions')) {
    /**
     * Match the historical chmod applied by install.sh to /home.
     */
    function pmssProtectHomePermissions(): void
    {
        runStep('Restricting world access to /home', 'chmod o-rw /home');
    }
}
