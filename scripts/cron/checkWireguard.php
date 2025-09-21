#!/usr/bin/php
<?php
/**
 * Ensure the WireGuard service remains healthy.
 */

$logPrefix = date('c') . ' ';
$config = '/etc/wireguard/wg0.conf';
if (!file_exists($config)) {
    echo $logPrefix . "wireguard config missing; skipping check\n";
    exit(0);
}

exec('lsmod | grep -q "^wireguard\b"', $null, $moduleStatus);
if ($moduleStatus !== 0) {
    exec('modprobe wireguard', $out, $rc);
    if ($rc !== 0) {
        echo $logPrefix . "failed to load wireguard kernel module (rc={$rc})\n";
    } else {
        echo $logPrefix . "loaded wireguard kernel module\n";
    }
}

if (is_dir('/run/systemd/system')) {
    exec('systemctl is-active --quiet wg-quick@wg0', $out, $status);
    if ($status === 0) {
        echo $logPrefix . "wg-quick@wg0 active\n";
        exit(0);
    }
    echo $logPrefix . "wg-quick@wg0 inactive, attempting restart\n";
    exec('systemctl restart wg-quick@wg0', $out, $restartStatus);
    if ($restartStatus === 0) {
        echo $logPrefix . "wg-quick@wg0 restarted successfully\n";
    } else {
        echo $logPrefix . "failed to restart wg-quick@wg0 (rc={$restartStatus})\n";
    }
} else {
    exec('wg show', $out, $status);
    if ($status !== 0) {
        echo $logPrefix . "wg0 interface missing; attempting wg-quick up\n";
        exec('wg-quick up wg0', $out, $rc);
        if ($rc === 0) {
            echo $logPrefix . "wg0 brought up successfully\n";
        } else {
            echo $logPrefix . "failed to bring up wg0 (rc={$rc})\n";
        }
    } else {
        echo $logPrefix . "wg show reports interface active\n";
    }
}
