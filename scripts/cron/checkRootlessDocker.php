#!/usr/bin/php
<?php
// Ensure rootless Docker daemon is running for each user

echo date('Y-m-d H:i:s') . ": Checking rootless Docker" . "\n";

$users = explode("\n", trim(shell_exec('/scripts/listUsers.php')));

foreach ($users as $user) {
    if (empty($user)) continue;
    if (file_exists("/home/{$user}/www-disabled") || !file_exists("/home/{$user}/www")) {
        echo "User: {$user} is suspended\n";
        continue;
    }

    $status = trim(shell_exec("su {$user} -c 'systemctl --user is-active docker.service 2>/dev/null'"));
    if ($status !== 'active') {
        echo "Starting Docker for {$user}\n";
        passthru("su {$user} -c 'systemctl --user start docker.service' >/dev/null 2>&1");
    } else {
        echo "Docker already running for {$user}\n";
    }
}
