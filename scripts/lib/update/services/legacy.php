<?php
/**
 * Legacy service disablement helpers.
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../runtime/processes.php';

if (!function_exists('pmssDisableLegacyServices')) {
    /**
     * Stop and disable daemons that should run per user instead of globally.
     */
    function pmssDisableLegacyServices(array $services, int $distroVersion): void
    {
        foreach ($services as $service) {
            if (file_exists('/etc/init.d/'.$service)) {
                runStep("Stopping legacy service {$service}", "/etc/init.d/{$service} stop");
            }
            if ($distroVersion < 10) {
                runStep("Disabling {$service} in sysvinit", "update-rc.d {$service} disable");
            } else {
                disableUnitIfPresent($service, "Disabling {$service} systemd unit");
            }
        }
    }
}
