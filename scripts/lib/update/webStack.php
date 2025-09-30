<?php
/**
 * Web stack configuration helpers for update-step2.
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/runtime/processes.php';
// #TODO Create services/systemd.php with helpers stopIfPresent()/restartIfPresent()
//       and refactor scattered stop/restart logic across modules to use it.

if (!function_exists('pmssConfigureWebStack')) {
    /**
     * Switch legacy lighttpd instances to nginx and refresh configs.
     */
    function pmssConfigureWebStack(int $distroVersion): void
    {
        // Stop nginx first so package upgrades and template refreshes never race against an active daemon.
        runStep('Stopping nginx prior to configuration refresh', 'systemctl stop nginx || /etc/init.d/nginx stop || true');
        if ($distroVersion < 10) {
            runStep('Stopping lighttpd (init.d)', '/etc/init.d/lighttpd stop');
            runStep('Disabling lighttpd from sysvinit runlevels', 'update-rc.d lighttpd stop 2 3 4 5');
            runStep('Removing lighttpd sysvinit hooks', 'update-rc.d lighttpd remove');
            killProcess('lighttpd', 'Terminating lingering lighttpd processes');
            killProcess('php-cgi', 'Terminating lingering php-cgi processes');
            runStep('Ensuring nginx defaults set in sysvinit', 'update-rc.d nginx defaults');
        } else {
            runStep('Stopping lighttpd (systemd)', '/etc/init.d/lighttpd stop');
            disableUnitIfPresent('lighttpd', 'Disabling lighttpd systemd service');
            killProcess('lighttpd', 'Terminating lingering lighttpd processes');
            killProcess('php-cgi', 'Terminating lingering php-cgi processes');
            runStep('Enabling nginx systemd service', 'systemctl enable nginx');
        }

        runStep('Refreshing lighttpd configuration', '/scripts/util/configureLighttpd.php');
        runStep('Regenerating nginx configuration', '/scripts/util/createNginxConfig.php');
        runStep('Verifying user HTTP authentication files', '/scripts/util/checkUserHtpasswd.php');
        runStep('Restarting nginx service', '/etc/init.d/nginx restart');
        runStep('Checking lighttpd per-user instances', '/scripts/cron/checkLighttpdInstances.php');
        runStep('Setting /home directory permissions', 'chmod 751 /home');
        runStep('Setting user home directory permissions', 'chmod 740 /home/*');
    }
}

if (!function_exists('pmssAdjustLighttpdSecurity')) {
    /**
     * Ensure lighttpd configuration files have tight ownership and ACLs.
     */
    function pmssAdjustLighttpdSecurity(): void
    {
        runStep('Adjusting /etc/lighttpd/lighttpd.conf permissions', 'chmod 750 /etc/lighttpd/lighttpd.conf');
        runStep('Setting ownership on /etc/lighttpd/lighttpd.conf', 'chown www-data.www-data /etc/lighttpd/lighttpd.conf');
        runStep('Setting ownership on /etc/lighttpd/.htpasswd', 'chown www-data.www-data /etc/lighttpd/.htpasswd');
        runStep('Adjusting /etc/lighttpd/.htpasswd permissions', 'chmod 750 /etc/lighttpd/.htpasswd');
    }
}

if (!function_exists('pmssPostUpdateWebRefresh')) {
    /**
     * Re-run web service configuration after application installers finish.
     */
    function pmssPostUpdateWebRefresh(): void
    {
        runStep('Post-update lighttpd configuration refresh', '/scripts/util/configureLighttpd.php');
        runStep('Post-update nginx configuration refresh', '/scripts/util/createNginxConfig.php');
        runStep('Post-update htpasswd verification', '/scripts/util/checkUserHtpasswd.php');
        runStep('Restarting nginx after configuration refresh', '/etc/init.d/nginx restart');
        runStep('Checking lighttpd instances after update', '/scripts/cron/checkLighttpdInstances.php');
    }
}
