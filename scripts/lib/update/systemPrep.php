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
