<?php
/**
 * Helpers for per-user maintenance during update-step2.
 */

function pmssUpdateUserEnvironment(string $user, array $options = []): void
{
    $ctx = pmssBuildUserContext($user, $options);
    if ($ctx === null) {
        return;
    }

    echo "***** Updating user {$user}\n";
    logmsg("Updating user {$user}");

    $steps = [
        'HTTP services'       => 'pmssUserConfigureHttp',
        'Skeleton files'      => 'pmssUserApplySkeletonFiles',
        'ruTorrent themes'    => 'pmssUserUpdateThemes',
        'ruTorrent refresh'   => 'pmssUserUpgradeRutorrent',
        'Plugin maintenance'  => 'pmssUserEnsurePlugins',
        'Retracker cleanup'   => 'pmssUserMaintainRetracker',
        'Permission refresh'  => 'pmssUserRefreshPermissions',
    ];

    foreach ($steps as $label => $handler) {
        if (function_exists($handler)) {
            $handler($ctx);
        } else {
            logmsg("[WARN] Missing handler {$handler} for {$label}");
        }
    }
}

function pmssBuildUserContext(string $user, array $options): ?array
{
    $home = "/home/{$user}";
    if (!is_dir($home)) return null;
    if (!file_exists("{$home}/.rtorrent.rc")) return null;
    if (!file_exists("{$home}/data")) return null;
    if (file_exists("{$home}/www-disabled")) return null;

    return [
        'user'       => $user,
        'home'       => $home,
        'user_esc'   => escapeshellarg($user),
        'rutorrent_index_sha' => $options['rutorrent_index_sha'] ?? '',
    ];
}

function pmssUserConfigureHttp(array $ctx): void
{
    $user = $ctx['user'];
    $home = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    runUserStep($user, 'Configuring lighttpd vhost', sprintf('/scripts/util/configureLighttpd.php %s', $userEsc));

    $phpIniPath = "{$home}/.lighttpd/php.ini";
    if (file_exists($phpIniPath)) {
        $phpIni = parse_ini_file($phpIniPath);
        if ($phpIni !== false && !isset($phpIni['error_log'])) {
            $phpIni['error_log'] = "{$home}/.lighttpd/error.log";
            $newContent = '';
            foreach ($phpIni as $key => $value) {
                $newContent .= "{$key} = \"{$value}\"\n";
            }
            file_put_contents($phpIniPath, $newContent);
            echo "Updated php.ini for user {$user}\n";
        }
    }

    $tmpDir = "{$home}/.tmp";
    if (!is_dir($tmpDir)) {
        runUserStep($user, 'Creating ruTorrent temp directory', sprintf('mkdir -p %s', escapeshellarg($tmpDir)));
        runUserStep($user, 'Adjusting ownership for ruTorrent temp directory', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($tmpDir)));
    }

    $irssiDir = "{$home}/.irssi";
    if (!is_dir($irssiDir)) {
        runUserStep($user, 'Creating irssi configuration directory', sprintf('mkdir -p %s', escapeshellarg($irssiDir)));
        runUserStep($user, 'Copying irssi skeleton config', sprintf('cp /etc/skel/.irssi/config %s/', escapeshellarg($irssiDir)));
        runUserStep($user, 'Adjusting irssi configuration ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($irssiDir)));
    }

    $recycleDir = "{$home}/www/recycle";
    if (!is_dir($recycleDir)) {
        runUserStep($user, 'Creating recycle directory', sprintf('mkdir -p %s', escapeshellarg($recycleDir)));
        runUserStep($user, 'Adjusting recycle ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($recycleDir)));
        runUserStep($user, 'Setting recycle permissions', sprintf('chmod 771 %s', escapeshellarg($recycleDir)));
    }
}

function pmssUserApplySkeletonFiles(array $ctx): void
{
    $user = $ctx['user'];

    $files = [
        '.rtorrentExecute.php',
        '.rtorrentRestart.php',
        '.bashrc',
        '.qbittorrentPort.py',
        '.delugePort.py',
        '.scriptsInc.php',
        '.lighttpd/php.ini',
        'www/filemanager.php',
        'www/openvpn-config.tgz',
        'www/rutorrent/js/content.js',
        'www/rutorrent/php/settings.php',
        'www/rutorrent/plugins/theme/conf.php',
    ];
    foreach ($files as $file) {
        updateUserFile($file, $user);
    }

    if (file_exists("/home/{$user}/www/phpXplorer")) {
        unlink("/home/{$user}/www/phpXplorer");
    }

    $quotaFiles = glob('/etc/skel/www/rutorrent/plugins/hddquota/*');
    if ($quotaFiles !== false) {
        foreach ($quotaFiles as $file) {
            $relative = str_replace('/etc/skel/', '', $file);
            updateUserFile($relative, $user);
        }
    }
}

function pmssUserUpdateThemes(array $ctx): void
{
    $user = $ctx['user'];
    $home = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $themesPath = "{$home}/www/rutorrent/plugins/theme/themes/";
    $themes = ['Agent34','Agent46','OblivionBlue','FlatUI_Dark','FlatUI_Light','FlatUI_Material','MaterialDesign','club-QuickBox'];
    foreach ($themes as $theme) {
        if (!file_exists($themesPath . $theme)) {
            runUserStep($user, "Installing ruTorrent theme {$theme}", sprintf('cp -r %s %s',
                escapeshellarg("/etc/skel/www/rutorrent/plugins/theme/themes/{$theme}"),
                escapeshellarg($themesPath)
            ));
            runUserStep($user, "Adjusting theme {$theme} ownership", sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($themesPath . $theme)));
        }
    }
}

function pmssUserUpgradeRutorrent(array $ctx): void
{
    $user = $ctx['user'];
    $home = $ctx['home'];
    $userEsc = $ctx['user_esc'];
    $expectedSha = $ctx['rutorrent_index_sha'];
    $currentIndex = "{$home}/www/rutorrent/index.html";

    if ($expectedSha === '' || !file_exists($currentIndex)) {
        return;
    }
    if (file_exists("{$home}/www/oldRutorrent-3") || $expectedSha === sha1(file_get_contents($currentIndex))) {
        return;
    }

    echo "****** Updating ruTorrent\n";
    echo "******* Backing up old as 'oldRutorrent-3'\n";
    runUserStep($user, 'Backing up existing ruTorrent', sprintf('mv %s %s',
        escapeshellarg("{$home}/www/rutorrent"),
        escapeshellarg("{$home}/www/oldRutorrent-3")
    ));
    echo "******* Copying new ruTorrent from skel\n";
    runUserStep($user, 'Copying new ruTorrent from skel', sprintf('cp -Rp %s %s',
        escapeshellarg('/etc/skel/www/rutorrent'),
        escapeshellarg("{$home}/www/")
    ));
    echo "******* Configuring\n";
    runUserStep($user, 'Restoring ruTorrent config.php', sprintf('cp -p %s %s',
        escapeshellarg("{$home}/www/oldRutorrent-3/conf/config.php"),
        escapeshellarg("{$home}/www/rutorrent/conf/")
    ));
    runUserStep($user, 'Restoring ruTorrent share directory', sprintf('bash -lc %s',
        escapeshellarg("cp -rp {$home}/www/oldRutorrent-3/share/* {$home}/www/rutorrent/share/")
    ));
    updateRutorrentConfig($user, 1);
    runUserStep($user, 'Setting ruTorrent directory ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg("{$home}/www/rutorrent")));
    runUserStep($user, 'Setting ruTorrent recursive ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg("{$home}/www/rutorrent")));
    runUserStep($user, 'Setting ruTorrent permissions', sprintf('chmod 751 %s', escapeshellarg("{$home}/www/rutorrent")));
    runUserStep($user, 'Setting ruTorrent recursive permissions', sprintf('chmod -R 751 %s', escapeshellarg("{$home}/www/rutorrent")));
}

function pmssUserEnsurePlugins(array $ctx): void
{
    $user = $ctx['user'];
    $home = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    if (file_exists("{$home}/www/rutorrent/plugins/cpuload")) {
        runUserStep($user, 'Removing deprecated cpuload plugin', sprintf('rm -rf %s', escapeshellarg("{$home}/www/rutorrent/plugins/cpuload")));
    }

    if (!file_exists("{$home}/www/rutorrent/plugins/unpack")) {
        runUserStep($user, 'Installing unpack plugin', sprintf('cp -Rp %s %s',
            escapeshellarg('/etc/skel/www/rutorrent/plugins/unpack'),
            escapeshellarg("{$home}/www/rutorrent/plugins/unpack")
        ));
        runUserStep($user, 'Adjusting unpack plugin ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg("{$home}/www/rutorrent/plugins/unpack")));
        runUserStep($user, 'Setting unpack plugin permissions', sprintf('chmod -R 755 %s', escapeshellarg("{$home}/www/rutorrent/plugins/unpack")));
    }
}

function pmssUserMaintainRetracker(array $ctx): void
{
    $user = $ctx['user'];
    $home = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $retrackerConfigPath = "{$home}/www/rutorrent/share/users/{$user}/settings";
    if (file_exists("{$retrackerConfigPath}/retrackers.dat")) {
        $retrackCurrent = trim(file_get_contents("{$retrackerConfigPath}/retrackers.dat"));
        if (sha1($retrackCurrent) === '9958caa274c2df67ea6702772821856365bc1201') {
            unlink("{$retrackerConfigPath}/retrackers.dat");
        }
    }
    if (!file_exists("{$home}/www/rutorrent/share/users/{$user}/torrents") &&
        file_exists("{$home}/www/rutorrent/share/users/{$user}")) {
        runUserStep($user, 'Creating ruTorrent torrents directory', sprintf('mkdir -p %s', escapeshellarg("{$home}/www/rutorrent/share/users/{$user}/torrents")));
        runUserStep($user, 'Adjusting retracker ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($retrackerConfigPath)));
    }

    $rssDir = "{$home}/www/rutorrent/share/settings/rss";
    if (!file_exists($rssDir)) {
        runUserStep($user, 'Creating ruTorrent RSS settings directory', sprintf('mkdir -p %s', escapeshellarg($rssDir)));
        runUserStep($user, 'Adjusting RSS settings ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($rssDir)));
        echo "\t*** Created RSS Settings folder\n";
    }
}

function pmssUserRefreshPermissions(array $ctx): void
{
    $user = $ctx['user'];
    $userEsc = $ctx['user_esc'];
    $home = $ctx['home'];

    runUserStep($user, 'Refreshing user permissions', sprintf('/scripts/util/userPermissions.php %s', $userEsc));

    $rcCustomPath = "{$home}/.rtorrent.rc.custom";
    if (file_exists($rcCustomPath)) {
        $rcCustomSha = sha1(file_get_contents($rcCustomPath));
        if ($rcCustomSha === 'dcf21704d49910d1670b3fdd04b37e640b755889' ||
            $rcCustomSha === 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a') {
            runUserStep($user, 'Updating .rtorrent.rc.custom from skeleton', sprintf('cp /etc/skel/.rtorrent.rc.custom %s/', escapeshellarg($home)));
        }
    }
}
