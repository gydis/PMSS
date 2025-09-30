<?php
/**
 * Process and service helpers for update flows.
 */

require_once __DIR__.'/commands.php';
require_once __DIR__.'/../logging.php';

if (!function_exists('killProcess')) {
    /**
     * Kill all processes matching the binary name when present.
     */
    function killProcess(string $name, string $description): void
    {
        // #TODO Implement a graceful termination helper:
        //       stop (if service), then SIGTERM with timeout, finally SIGKILL.
        //       Build a reusable library to replace repeated killall -9 usage.
        exec('pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1', $_, $status);
        if ($status !== 0) {
            logmsg("[SKIP] {$description} (no {$name} processes)");
            return;
        }
        runStep($description, 'killall -9 '.escapeshellarg($name));
    }
}

if (!function_exists('disableUnitIfPresent')) {
    /**
     * Disable a systemd unit only when it exists on the target host.
     */
    function disableUnitIfPresent(string $unit, string $description): void
    {
        if (!is_dir('/run/systemd/system')) {
            logmsg("[SKIP] {$description} (systemd unavailable)");
            return;
        }
        exec('systemctl list-unit-files '.escapeshellarg($unit).' 2>/dev/null', $output, $status);
        $found = false;
        if ($status === 0) {
            foreach ($output as $line) {
                if (stripos($line, $unit) === 0) {
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            logmsg("[SKIP] {$description} (unit {$unit} missing)");
            return;
        }
        runStep($description, 'systemctl disable '.escapeshellarg($unit));
    }
}
