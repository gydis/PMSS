<?php
/**
 * Miscellaneous security tweaks applied post-update.
 */

require_once __DIR__.'/../runtime/commands.php';

if (!function_exists('pmssRemoveAutodlConfig')) {
    /**
     * Drop obsolete global autodl configuration file if it exists.
     */
    function pmssRemoveAutodlConfig(): void
    {
        if (file_exists('/etc/autodl.cfg')) {
            unlink('/etc/autodl.cfg');
        }
    }
}

if (!function_exists('pmssEnsureTestfile')) {
    /**
     * Ensure the standard download speed test file exists.
     */
    function pmssEnsureTestfile(): void
    {
        $path = '/var/www/testfile';
        if (file_exists($path) && filesize($path) === 104857600) {
            return;
        }
        runStep('Generating /var/www/testfile sample', 'dd if=/dev/urandom of=/var/www/testfile bs=1M count=100 status=none');
    }
}

if (!function_exists('pmssRestrictAtopBinary')) {
    /**
     * Restrict atop execution permissions to privileged users.
     */
    function pmssRestrictAtopBinary(): void
    {
        @chmod('/usr/bin/atop', 0750);
    }
}

if (!function_exists('pmssApplySecurityHardening')) {
    /**
     * Apply quick hardening tweaks for logs and network utilities.
     */
    function pmssApplySecurityHardening(): void
    {
        runStep('Hardening access to session and network binaries', 'chmod o-r /var/log/wtmp /var/run/utmp /usr/bin/netstat /usr/bin/who /usr/bin/w');
    }
}
