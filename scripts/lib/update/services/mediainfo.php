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
        if (file_exists('/usr/bin/mediainfo') || $lsbCodename === '') {
            return;
        }

        $log = pmssSelectLogger($logger);
        $cwd = getcwd();
        @mkdir('/tmp/mediainfo', 0755, true);
        chdir('/tmp/mediainfo');

        $libzen       = sprintf('libzen0_0.4.24-1_amd64.Debian_%s.deb', $lsbCodename);
        $libmediainfo = sprintf('libmediainfo0_0.7.53-1_amd64.Debian_%s.deb', $lsbCodename);
        $mediainfo    = sprintf('mediainfo_0.7.52-1_amd64.Debian_%s.deb', $lsbCodename);

        runStep('Downloading libzen package', "wget http://pulsedmedia.com/remote/pkg/{$libzen}");
        runStep('Downloading libmediainfo package', "wget http://pulsedmedia.com/remote/pkg/{$libmediainfo}");
        runStep('Downloading mediainfo package', "wget http://pulsedmedia.com/remote/pkg/{$mediainfo}");

        runStep('Installing libzen', 'dpkg -i '.$libzen);
        runStep('Installing libmediainfo', 'dpkg -i '.$libmediainfo);
        runStep('Installing mediainfo', 'dpkg -i '.$mediainfo);

        chdir($cwd);
        if (file_exists('/usr/bin/mediainfo')) {
            $log('Installed mediainfo package set for '.$lsbCodename);
        } else {
            $log('[WARN] Mediainfo installation finished without binary presence');
        }
    }
}
