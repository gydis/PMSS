#!/usr/bin/php
<?php
/**
 * User configuration controller.
 *
 * Delegates to helper modules under scripts/lib/user so the runtime logic stays
 * small and maintainable.
 */

require_once '/scripts/lib/user/traffic.php';
require_once '/scripts/lib/user/rtorrent.php';
require_once '/scripts/lib/user/deluge.php';
require_once '/scripts/lib/user/qbittorrent.php';
require_once '/scripts/lib/user/integrations.php';
require_once '/scripts/lib/user/system.php';
require_once '/scripts/lib/user/helpers.php';

$usage = 'Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000]';
if (empty($argv[1]) || empty($argv[2]) || empty($argv[3])) {
    die('need user name. '.$usage."\n");
}

$user = [
    'name'   => $argv[1],
    'memory' => (int) $argv[2],
    'quota'  => (int) $argv[3],
    'id'     => (int) trim(`id -u {$argv[1]}`),
    'CPUWeight' => isset($argv[5]) ? (int) $argv[5] : 500,
    'IOWeight'  => isset($argv[6]) ? (int) $argv[6] : 500,
];

if (isset($argv[4])) {
    $user['trafficLimit'] = (int) $argv[4];
}

if ($user['id'] < 1000) die("No system ID or user does not exist\n");
if (!file_exists("/home/{$user['name']}")) die("User does not exist\n");

$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false) die("No such user in passwd list\n");

userApplyTrafficLimit($user);
$user['CPUWeight'] = $user['CPUWeight'] ?: 500;
$user['IOWeight']  = $user['IOWeight']  ?: 500;

$configuration = userConfigureRtorrent($user);
userConfigureRutorrent($user, $configuration);
userEnsureRclonePort($user);
userConfigureDeluge($user, $configuration);
userConfigureQbittorrent($user);
userApplyDiskQuota($user);
userRestartRtorrentIfRunning($user);
userEnsureShell($user);
userConfigureSystemdSlice($user);
userEnableLingerAndDocker($user);
