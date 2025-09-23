#!/usr/bin/php
<?php
#TODO Wrong naming etc.

passthru("cd /etc/skel; chmod o-w * -R; chmod o-w .* -R"); // not using 775 because there might be places where the perms differ and need to differ
passthru("chmod 770 /etc/skel");

passthru("cd /etc/seedbox; chmod o-w * -R; chmod o-w .* -R"); // not using 775 because there might be places where the perms differ and need to differ
passthru("chmod o+x /etc/seedbox");

// Setup openvpn config perms
if (is_dir('/etc/openvpn')) {
    @chmod('/etc/openvpn', 0771);
}
if (is_file('/etc/openvpn/openvpn.conf')) {
    @chmod('/etc/openvpn/openvpn.conf', 0640);
}
if (is_dir('/etc/openvpn/easy-rsa')) {
    @chmod('/etc/openvpn/easy-rsa', 0771);
}
if (is_file('/etc/seedbox/config/localnet')) {
    @chmod('/etc/seedbox/config/localnet', 0664);
}
@chmod('/etc/seedbox/localnet', 0664);
