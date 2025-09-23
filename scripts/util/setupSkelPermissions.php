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

// Normalise permissions inside /etc/seedbox/config so templates stay readable without leaking secrets.
$configDir = '/etc/seedbox/config';
if (is_dir($configDir)) {
    // Secrets get a tighter mask; everything else falls back to group writable templates.
    $restrictedFiles = [
        $configDir . '/api.localKey'  => 0600,
        $configDir . '/api.remoteKey' => 0600,
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $node) {
        $path = $node->getPathname();

        if ($node->isDir()) {
            @chmod($path, 0775);
            continue;
        }

        if (isset($restrictedFiles[$path])) {
            @chmod($path, $restrictedFiles[$path]);
            continue;
        }

        @chmod($path, 0664);
    }

    // Ensure the root directory keeps execute permission for traversal.
    @chmod($configDir, 0775);
}
