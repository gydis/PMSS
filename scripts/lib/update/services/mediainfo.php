<?php
/**
 * Mediainfo installer helper.
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

if (!function_exists('pmssInstallMediaInfo')) {
    /**
     * Install mediainfo and its dependencies if the binary is absent.
     */
    function pmssInstallMediaInfo(string $lsbCodename, ?callable $logger = null): void
    {
        if (file_exists('/usr/bin/mediainfo')) {
            return;
        }

        $log = pmssSelectLogger($logger);
        $installRc = runStep('Installing mediainfo package', aptCmd('install -y mediainfo'));
        if ($installRc !== 0) {
            runStep('Retrying mediainfo install', aptCmd('--fix-broken install -y'));
            $installRc = runStep('Installing mediainfo package (retry)', aptCmd('install -y mediainfo'));
        }

        if ($installRc === 0 && file_exists('/usr/bin/mediainfo')) {
            $version = trim((string)@shell_exec('dpkg-query -W -f=${Version} mediainfo 2>/dev/null'));
            if ($version !== '') {
                $log('mediainfo installed via apt repository (version '.$version.')');
            } else {
                $log('mediainfo installed via apt repository (version unknown)');
            }
            return;
        }

        $log('[WARN] mediainfo install via apt failed; binary still missing');
    }
}
