#!/usr/bin/php
<?php
/**
 * Restore access for a previously suspended account.
 */

$usage = 'unsuspend.php USERNAME';
$username = $argv[1] ?? '';
if ($username === '') {
    die($usage."\n");
}

$homeDir = "/home/{$username}";
$activeRoot = "$homeDir/www";
$disabledRoot = "$homeDir/www-disabled";
$marker = $activeRoot.'/.pmss-suspended';

$hasPlaceholder = file_exists($marker);
if (!is_dir($disabledRoot) && !$hasPlaceholder) {
    die("User is not suspended\n");
}

passthru('usermod -U '.escapeshellarg($username));
$farFuture = date('Y-m-d', time() + (60 * 60 * 24 * 365 * 10));
passthru('usermod --expiredate '.escapeshellarg($farFuture).' '.escapeshellarg($username));

if ($hasPlaceholder) {
    pmssRemoveSuspendedLanding($activeRoot);
}

if (is_dir($disabledRoot)) {
    if (!@rename($disabledRoot, $activeRoot)) {
        echo "Warning: failed to restore {$disabledRoot}\n";
    }
}

passthru('/scripts/startRtorrent '.escapeshellarg($username));

/**
 * Delete the placeholder landing page tree.
 */
function pmssRemoveSuspendedLanding(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory.'/'.$item;
        if (is_dir($path)) {
            pmssRemoveSuspendedLanding($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}
