<?php
/**
 * Bootstrap helpers migrated from install.sh.
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/quota.php';

if (!function_exists('pmssEnvFlagEnabled')) {
    /**
     * Determine whether an environment flag resolves to "true".
     */
    function pmssEnvFlagEnabled(string $name): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return false;
        }
        $normalised = strtolower(trim($value));
        return !in_array($normalised, ['', '0', 'false', 'no'], true);
    }
}

if (!function_exists('pmssApplyHostnameConfig')) {
    /**
     * Apply hostname overrides provided by the installer.
     */
    function pmssApplyHostnameConfig(?callable $logger = null): void
    {
        $log   = pmssSelectLogger($logger);
        $skip  = pmssEnvFlagEnabled('PMSS_SKIP_HOSTNAME');
        $target = getenv('PMSS_HOSTNAME');

        if ($skip) {
            $log('[SKIP] Hostname configuration skipped via PMSS_SKIP_HOSTNAME');
            return;
        }
        if ($target === false || trim($target) === '') {
            $log('[SKIP] No hostname override provided');
            return;
        }

        $hostname = trim($target);
        $hasHostnamectl = trim((string) @shell_exec('command -v hostnamectl')) !== '';
        $command        = $hasHostnamectl
            ? sprintf('hostnamectl set-hostname %s', escapeshellarg($hostname))
            : sprintf('hostname %s', escapeshellarg($hostname));
        $description    = $hasHostnamectl ? 'Setting hostname via hostnamectl' : 'Setting hostname';
        runStep($description, $command);

        $existing = @file_get_contents('/etc/hostname');
        if ($existing === false || trim($existing) !== $hostname) {
            @file_put_contents('/etc/hostname', $hostname.PHP_EOL);
            $log('Updated /etc/hostname to '.$hostname);
        } else {
            $log('[SKIP] /etc/hostname already set to '.$hostname);
        }
    }
}

if (!function_exists('pmssConfigureQuotaMount')) {
    /**
     * Ensure quota options exist for the requested mount and remount it.
     */
    function pmssConfigureQuotaMount(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        if (pmssEnvFlagEnabled('PMSS_SKIP_QUOTA')) {
            $log('[SKIP] Quota configuration skipped via PMSS_SKIP_QUOTA');
            return;
        }

        $mount = getenv('PMSS_QUOTA_MOUNT');
        $mount = $mount === false || trim($mount) === '' ? '/home' : trim($mount);
        pmssEnsureQuotaOptions($mount, null, $log);
        if (is_dir($mount)) {
            runStep('Remounting '.$mount.' to refresh quota options', sprintf('mount -o remount %s', escapeshellarg($mount)));
            return;
        }
        $log('[WARN] Skipping remount for '.$mount.' (mount path not found)');
    }
}
