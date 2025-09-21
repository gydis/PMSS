#!/usr/bin/php
<?php
# PMSS: User configuration
# Copyright (C) Magna Capax Finland Oy 2010-2023

#TODO Refactor, commenting etc. etc.
#TODO Save every detail so we can locally check the settings later on, AKA local user database
#TODO Better input parser, just like for userTrafficLimit.php
#TODO Rtorrent + deluge config should be completely transferred to within userspace
#TODO Deluge should not have special config in nginx, and should be changed like qBittorrent works
#TODO Make IOWeights actually work and include other cgroup parameters as well
#TODO Set port range for user based on user id, something like this: ((UserID-1000)*100)+1000 to give 100 ports for user \
#       to employ systematically the same always to remove slightest chance of conflicts
#TODO Check if docker rootless can be preinstalled just in skel
#TODO Update skel user .bashrc to have the docker settings
#TODO Should target about 30 lines of actual code when refactored properly, aka this should just be a "controller", see setting trafficlimit

require_once '/scripts/lib/rtorrentConfig.php';
require_once '/scripts/lib/update.php';

/**
 * Run a shell command with logging while keeping failures non-fatal.
 */
function runUserCommand(string $description, string $command): int
{
    if ($description !== '') echo $description . "\n";
    return runCommand($command, false, 'logMessage');
}

/**
 * Apply or refresh the user-specific traffic cap.
 */
function applyTrafficLimit(array $user): void
{
    if (empty($user['trafficLimit']) || $user['trafficLimit'] <= 1) {
        return;
    }
    $cmd = sprintf('/scripts/util/userTrafficLimit.php %s %s',
        escapeshellarg($user['name']),
        escapeshellarg($user['trafficLimit'])
    );
    runUserCommand('Updating traffic limit', $cmd);
}

/**
 * Build and write the rTorrent configuration file, returning details for reuse.
 */
function configureRtorrent(array $user): array
{
    echo "Creating rTorrent config\n";
    $resources = file_exists('/etc/seedbox/config/system.rtorrent.resources')
        ? unserialize(file_get_contents('/etc/seedbox/config/system.rtorrent.resources'))
        : [];

    $template = file_exists('/etc/seedbox/config/template.rtorrentrc')
        ? file_get_contents('/etc/seedbox/config/template.rtorrentrc')
        : null;

    $rtorrentConfig = new rtorrentConfig($resources, $template);
    $configuration = $rtorrentConfig->createConfig([
        'ram' => $user['memory'],
        'dht' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht'),
        'pex' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex'),
    ]);
    $rtorrentConfig->writeConfig($user['name'], $configuration['configFile']);

    return $configuration;
}

/**
 * Update ruTorrent configuration files for the selected account.
 */
function configureRutorrent(array $user, array $configuration): void
{
    echo "Changing ruTorrent config\n";
    $scgiPort = $configuration['config']['scgiPort'] ?? 0;
    updateRutorrentConfig($user['name'], $scgiPort);
}

/**
 * Ensure helper ports and directories exist for rclone integrations.
 */
function ensureRclonePort(array $user): void
{
    $rclonePortFile = "/home/{$user['name']}/.rclonePort";
    if (!file_exists($rclonePortFile)) {
        file_put_contents($rclonePortFile, (int) round(rand(1500, 65500)));
    }
}

/**
 * Prepare Deluge configuration files and directories.
 */
function configureDeluge(array $user, array $configuration): void
{
    $username = $user['name'];
    $home = "/home/{$username}";
    $configDir = "$home/.config/deluge";
    $unfinishedDir = "$home/dataUnfinished";
    $sessionDir = "$home/.sessionDeluge";

    if (!file_exists($configDir)) {
        runUserCommand('Creating Deluge config dir', sprintf('mkdir -p %s', escapeshellarg($configDir)));
    }
    if (!file_exists($unfinishedDir)) {
        runUserCommand('Creating Deluge unfinished dir', sprintf('mkdir -p %s', escapeshellarg($unfinishedDir)));
        runUserCommand('Fixing Deluge unfinished ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username . ':' . $username), escapeshellarg($unfinishedDir)));
    }
    if (!file_exists($sessionDir)) {
        runUserCommand('Creating Deluge session dir', sprintf('mkdir -p %s', escapeshellarg($sessionDir)));
        runUserCommand('Fixing Deluge session ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username . ':' . $username), escapeshellarg($sessionDir)));
    }

    $scgiPort = $configuration['config']['scgiPort'] ?? 5000;
    $existingPort = file_exists("$home/.delugePort") ? (int) file_get_contents("$home/.delugePort") : 0;
    $delugePort = ($existingPort >= 1024 && $existingPort <= 65000) ? $existingPort : $scgiPort;

    $coreTemplate = file_get_contents('/etc/seedbox/config/template.deluge.core.conf');
    $coreConfig = str_replace(
        ['##USERNAME##', '##CACHE', '##DAEMONPORT'],
        [$username, (int) ($user['memory'] * 1024 / 16), $delugePort],
        $coreTemplate
    );
    file_put_contents("$configDir/core.conf", $coreConfig);

    $hostlistTemplate = file_get_contents('/etc/seedbox/config/template.deluge.hostlist.conf');
    $hostlistConfig = str_replace('##DAEMONPORT', $delugePort, $hostlistTemplate);
    file_put_contents("$configDir/hostlist.conf", $hostlistConfig);
    if (!file_exists("$configDir/hostlist.conf.1.2")) {
        @symlink("$configDir/hostlist.conf", "$configDir/hostlist.conf.1.2");
    }

    $webTemplate = file_get_contents('/etc/seedbox/config/template.deluge.web.conf');
    $webConfig = str_replace(['##WEBPORT', '##USER'], [$delugePort + 1, $username], $webTemplate);
    file_put_contents("$configDir/web.conf", $webConfig);
    file_put_contents("$home/.delugePort", $delugePort);

    if (!file_exists("$configDir/auth")) {
        runUserCommand('Provisioning Deluge auth template', sprintf('cp %s %s',
            escapeshellarg('/etc/seedbox/config/template.deluge.auth'),
            escapeshellarg("$configDir/auth"))
        );
    }
    if (!file_exists("$configDir/web.conf")) {
        runUserCommand('Provisioning Deluge web template', sprintf('cp %s %s',
            escapeshellarg('/etc/seedbox/config/template.deluge.web.conf'),
            escapeshellarg("$configDir/web.conf"))
        );
    }
    runUserCommand('Fixing Deluge ownership', sprintf('chown %1$s -R %2$s', escapeshellarg($username . ':' . $username), escapeshellarg("$home/.config/")));
}

/**
 * Ensure qBittorrent configuration exists for the account.
 */
function configureQbittorrent(array $user): void
{
    $configDir = "/home/{$user['name']}/.config/qBittorrent";
    $configFile = "$configDir/qBittorrent.conf";
    if (file_exists($configFile)) {
        return;
    }

    $template = file_get_contents('/etc/seedbox/config/template.qbittorrent.conf');
    $port = round(rand(1500, 65500));
    if (!file_exists($configDir)) {
        mkdir($configDir, 0770, true);
    }
    $config = str_replace(['##username', '##port'], [$user['name'], $port], $template);
    file_put_contents($configFile, $config);
    file_put_contents("/home/{$user['name']}/.qbittorrentPort", $port);
}

/**
 * Configure disk quota limits for the user.
 */
function applyDiskQuota(array $user): void
{
    $filesLimitPerGb = 500;
    $quota = $user['quota'] * 1024 * 1024;
    $filesLimit = max($user['quota'] * $filesLimitPerGb, 15000);
    $quotaBurst = floor($quota * 1.25);
    $filesBurst = floor($filesLimit * 1.25);

    $cmd = sprintf('setquota %s %d %d %d %d -a',
        escapeshellarg($user['name']),
        $quota,
        $quotaBurst,
        $filesLimit,
        $filesBurst
    );
    runUserCommand('Applying disk quota', $cmd);
}

/**
 * Restart rTorrent if the lock file reports an active process.
 */
function restartRtorrentIfRunning(array $user): void
{
    $lockFile = "/home/{$user['name']}/session/rtorrent.lock";
    if (!file_exists($lockFile)) {
        return;
    }
    $pidChunk = explode(':+', file_get_contents($lockFile));
    $pid = (int) $pidChunk;
    if ($pid > 0) {
        runUserCommand('Restarting rTorrent', sprintf('kill -9 %d', $pid));
    }
}

/**
 * Ensure the login shell is bash when available.
 */
function ensureUserShell(array $user): void
{
    if (!file_exists('/bin/bash')) {
        return;
    }
    runUserCommand('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name'])));
}

/**
 * Write the systemd slice overrides controlling resource limits.
 */
function configureSystemdSlice(array $user): void
{
    $slicePath = "/etc/systemd/system/user-{$user['id']}.slice.d";
    if (!file_exists($slicePath)) {
        mkdir($slicePath, 0755, true);
    }
    $template = file_get_contents('/etc/seedbox/config/template.user-slice.conf');
    $rendered = str_replace(
        ['##USER_MEMORY##', '##USER_MEMORY_MAX##', '##USER_CPUWEIGHT##', '##USER_IOWEIGHT##'],
        [$user['memory'], $user['memory'] * 2, $user['CPUWeight'], $user['IOWeight']],
        $template
    );

    if (file_exists("$slicePath/99-pmss.conf")) {
        unlink("$slicePath/99-pmss.conf");
    }
    file_put_contents("$slicePath/90-pmss-user.conf", $rendered);
    chmod("$slicePath/90-pmss-user.conf", 0644);
    runUserCommand('Reloading systemd configuration', 'systemctl daemon-reload');
}

/**
 * Enable lingering services and install rootless Docker helpers.
 */
function enableLingerAndDocker(array $user): void
{
    runUserCommand('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    runUserCommand('Installing rootless Docker prerequisites', 'apt-get install -y uidmap slirp4netns dbus-user-session fuse-overlayfs');
    runUserCommand('Configuring rootless Docker', sprintf('machinectl shell %1$s@ /usr/bin/dockerd-rootless-setuptool.sh install', escapeshellarg($user['name'])));
}

$usage = 'Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000]';
if (empty($argv[1]) or
    empty($argv[2]) or
    empty($argv[3]) ) die('need user name. ' . $usage . "\n");
    
$user = array(
    'name'      => $argv[1],
    'memory'    => (int) $argv[2],
    'quota'     => (int) $argv[3]
);
$user['id'] = (int) `id -u {$user['name']}`;
if (isset($argv[4])) $user['trafficLimit'] = (int) $argv[4];
if (isset($argv[5])) $user['CPUWeight'] = (int) $argv[5];
if (isset($argv[6])) $user['IOWeight'] = (int) $argv[6];

if (!isset($user['id']) OR $user['id'] < 1000) die("No system ID or user does not exist\n");
if (!file_exists("/home/{$user['name']}")) die("User does not exist\n");

$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false) die("No such user in passwd list\n");

applyTrafficLimit($user);

// Check for valid weights and set default
if (empty($user['CPUWeight']) or (int) $user['CPUWeight'] == 0) $user['CPUWeight'] = 500;
if (empty($user['IOWeight']) or (int) $user['IOWeight'] == 0) $user['IOWeight'] = 500;

$configuration = configureRtorrent($user);
configureRutorrent($user, $configuration);
ensureRclonePort($user);
configureDeluge($user, $configuration);
configureQbittorrent($user);
applyDiskQuota($user);
restartRtorrentIfRunning($user);
ensureUserShell($user);
configureSystemdSlice($user);
enableLingerAndDocker($user);
