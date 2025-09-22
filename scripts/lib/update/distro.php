<?php
/**
 * Distribution detection and updater self-heal helpers.
 */

require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssDetectDistro')) {
    /**
     * Detect distro name, major version, and codename with safe fallbacks.
     */
    function pmssDetectDistro(): array
    {
        $name = strtolower((string) getDistroName());
        if ($name === '') {
            $fallback = strtolower(trim((string) @shell_exec('lsb_release -is 2>/dev/null')));
            $name = $fallback !== '' ? $fallback : 'debian';
            if ($fallback === '') {
                logmsg('Could not detect distro name; defaulting to debian');
            }
        }

        $rawVersion = (string) getDistroVersion();
        if ($rawVersion === '') {
            $fallback = trim((string) @shell_exec('lsb_release -rs 2>/dev/null'));
            if ($fallback !== '') {
                $rawVersion = $fallback;
            } else {
                logmsg('Could not detect distro version; defaulting to 0');
            }
        }

        $version  = (int) filter_var($rawVersion, FILTER_SANITIZE_NUMBER_INT) ?: 0;
        $codename = trim((string) @shell_exec('lsb_release -cs 2>/dev/null'));

        return [
            'name'     => $name,
            'version'  => $version,
            'codename' => $codename,
        ];
    }
}

if (!function_exists('pmssEnsureLatestUpdater')) {
    /**
     * Refresh update.php from upstream if the legacy soft.sh flow is detected.
     */
    function pmssEnsureLatestUpdater(): void
    {
        $updateSource = @file_get_contents('/scripts/update.php');
        if ($updateSource === false || strpos($updateSource, 'soft.sh') === false) {
            return;
        }
        runStep('Fetching latest update.php from GitHub', 'wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php');
        runStep('Executing refreshed update.php', '/scripts/update.php');
        die();
    }
}
