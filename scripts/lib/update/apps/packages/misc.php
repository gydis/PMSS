<?php
/**
 * Miscellaneous application installers.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallSabnzbd(): void
{
    if (!file_exists('/usr/bin/sabnzbdplus')) {
        echo "## Installing Sabnzbdplus\n";
        passthru('apt-get install sabnzbdplus -y;');
    }
}

function pmssInstallMiscTools(): void
{
    if (!file_exists('/usr/bin/mkvextract')) {
        passthru('apt-get install mkvtoolnix -y');
    }

    if (!file_exists('/usr/sbin/openvpn')) {
        passthru('apt-get install openvpn easy-rsa -y ');
    }

    passthru('apt-get remove munin -y');
    passthru('apt-get install sudo -y');
    passthru('apt-get remove consolekit -y');
    passthru('apt-get install expect -y');

    if (!file_exists('/sbin/ipset')) {
        passthru('apt-get install ipset -y');
    }
}
