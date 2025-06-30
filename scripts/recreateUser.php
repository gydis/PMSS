#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 *  Pulsed Media – recreateUser.php  (v2 – with validation)
 *  Rebuild a user’s home plus service configs, then verify it worked.
 *
 *  Usage:  recreateUser.php USERNAME MAX_RTORRENT_MEMORY_MiB DISK_QUOTA_GiB
 */

const USAGE = "Usage: recreateUser.php USERNAME MAX_RTORRENT_MEMORY_MiB DISK_QUOTA_GiB\n";

/* ───────────────────── 1 · CLI parsing ───────────────────── */
[$_, $userName, $ramMiB, $quotaGiB] = array_pad($argv, 4, null);

if ($argc !== 4)                                    die(USAGE);
if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $userName))
    die("Invalid username\n");
if (!ctype_digit($ramMiB) || (int)$ramMiB < 1)
    die("ramMiB must be a positive integer\n");
if (!ctype_digit($quotaGiB) || (int)$quotaGiB < 1)
    die("quotaGiB must be a positive integer\n");

$ramMiB   = (int)$ramMiB;
$quotaGiB = (int)$quotaGiB;

/* ─────────────────── 2 · Derived paths ───────────────────── */
$homeDir   = "/home/{$userName}";
$backupDir = "/home/backup-{$userName}";

/* ───────────────── 3 · Pre-flight sanity ─────────────────── */
if (!is_dir($homeDir))
    die("Home directory {$homeDir} does not exist – aborting.\n");
if (is_dir($backupDir))
    die("Backup directory {$backupDir} already exists – remove or rename it.\n");

/* ──────────────── 4 · Helper wrappers ───────────────────── */
function run(string $cmd): void
{
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed ({$code}): {$cmd}\n");
        exit($code);
    }
}
function must(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "Validation failed: {$msg}\n");
        exit(1);
    }
}

/* ─────────────────── 5 · Actual surgery ─────────────────── */
echo "▶ Killing all processes for {$userName}\n";
run('pkill -9 -u ' . escapeshellarg($userName) . ' || true');

echo "▶ Moving {$homeDir} → {$backupDir}\n";
run('mv ' . escapeshellarg($homeDir) . ' ' . escapeshellarg($backupDir));
must(is_dir($backupDir), "backup directory not created!");

echo "▶ Creating fresh skeleton\n";
run('cp -Rp /etc/skel ' . escapeshellarg($homeDir));
run('chown -R ' . escapeshellarg($userName) . ':' . escapeshellarg($userName) . ' ' . escapeshellarg($homeDir));
must(is_dir($homeDir),  "new home directory missing!");
must(is_dir("{$homeDir}/data"),     "data dir missing in skeleton");
must(is_dir("{$homeDir}/session"),  "session dir missing in skeleton");

echo "▶ Copying lighttpd defaults\n";
run('cp -Rp /etc/skel/.lighttpd ' . escapeshellarg($homeDir) . '/');
run('chown -R ' . escapeshellarg($userName) . ':' . escapeshellarg($userName) . ' ' . escapeshellarg($homeDir) . '/.lighttpd');
must(is_dir("{$homeDir}/.lighttpd"), ".lighttpd dir missing after copy");

/* ───────────────── 6 · Per-service config ────────────────── */
run(sprintf(
    '/scripts/util/userConfig.php %s %d %d',
    escapeshellarg($userName),
    $ramMiB,
    $quotaGiB
));
run('/scripts/util/setupUserHomePermissions.php ' . escapeshellarg($userName));
run('/scripts/util/createNginxConfig.php');
run('/scripts/util/configureLighttpd.php ' . escapeshellarg($userName));
run('/scripts/util/userPermissions.php ' . escapeshellarg($userName));

must(file_exists("{$homeDir}/.rtorrent.rc") ||
     file_exists("{$homeDir}/.config/rtorrent/rtorrent.rc"),
     ".rtorrent config missing after userConfig.php");

/* ──────────────── 7 · Restore user data ─────────────────── */
echo "▶ Restoring data & session dirs\n";
foreach (['data', 'session'] as $d) {
    $src = "{$backupDir}/{$d}";
    $dst = "{$homeDir}/{$d}";
    if (is_dir($src)) {
        run('rsync -a ' . escapeshellarg($src . '/') . ' ' . escapeshellarg($dst . '/'));
        must(count(glob("{$dst}/*")) >= count(glob("{$src}/*")), "restore of {$d} incomplete");
    }
}
if (is_file("{$backupDir}/.lighttpd/.htpasswd")) {
    run('cp ' . escapeshellarg("{$backupDir}/.lighttpd/.htpasswd") . ' ' .
        escapeshellarg("{$homeDir}/.lighttpd/"));
}

/* ───────────────── 8 · Post-flight checks ───────────────── */
echo "▶ Verifying ownerships\n";
$uid = posix_getpwnam($userName)['uid'] ?? -1;
$gid = posix_getpwnam($userName)['gid'] ?? -1;
$stat = stat($homeDir);
must($stat['uid'] === $uid && $stat['gid'] === $gid, "homeDir not owned by {$userName}");

echo "✔  Re-creation ok – inspect & then:\n    rm -rf " . escapeshellarg($backupDir) . "\n";
exit(0);
