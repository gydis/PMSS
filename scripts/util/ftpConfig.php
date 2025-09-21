#!/usr/bin/php
<?php
/**
 * Render and apply the ProFTPD configuration using project templates.
 */

echo date('Y-m-d H:i:s') . ': Making ProFTPd configuration' . "\n";

$configTemplate = @file_get_contents('/etc/seedbox/config/template.proftpd');
$hostnameRaw    = @file_get_contents('/etc/hostname');

if ($configTemplate === false || $hostnameRaw === false) {
    die('No data, hostname or config template is empty!');
}

$hostname = trim($hostnameRaw);
$tlsBlock = buildTlsConfiguration($hostname);
$rendered = str_replace('%SERVERNAME%', $hostname, $configTemplate);

if ($tlsBlock === '') {
    $rendered = preg_replace('#\n?<IfModule mod_tls\.c>.*?</IfModule>#s', '', $rendered);
} else {
    $rendered = str_replace('%TLS_CONFIGURATION%', $tlsBlock, $rendered);
}

@mkdir('/var/log/proftpd', 0750, true);
@mkdir('/var/run/proftpd', 0750, true);

file_put_contents('/etc/proftpd/proftpd.conf', $rendered);

if (is_dir('/run/systemd/system')) {
    passthru('systemctl restart proftpd', $rc);
} else {
    passthru('/etc/init.d/proftpd restart', $rc);
}

if (($rc ?? 0) !== 0) {
    fwrite(STDERR, "Warning: ProFTPD restart returned code {$rc}.\n");
}

function buildTlsConfiguration(string $hostname): string
{
    $candidates = [];
    $trimmed = trim($hostname);
    if ($trimmed !== '') {
        $candidates[] = "/etc/letsencrypt/live/{$trimmed}";
        if (strpos($trimmed, '.') !== false) {
            [$sub, $domain] = explode('.', $trimmed, 2);
            $candidates[] = "/etc/letsencrypt/live/*.{$domain}";
        }
    }
    $candidates[] = '/etc/seedbox/config/ssl/proftpd';

    foreach ($candidates as $base) {
        if (file_exists($base.'/cert.pem') && file_exists($base.'/privkey.pem') && file_exists($base.'/fullchain.pem')) {
            return implode("\n", [
                '    TLSEngine                     on',
                '    TLSLog                        /var/log/proftpd/tls.log',
                '    TLSProtocol                   TLSv1.2 TLSv1.3',
                '    TLSCipherSuite                HIGH:!aNULL:!MD5:!3DES',
                '    TLSOptions                    NoSessionReuseRequired',
                '    TLSRenegotiate                none',
                '    TLSRSACertificateFile         "'.$base.'/cert.pem"',
                '    TLSRSACertificateKeyFile      "'.$base.'/privkey.pem"',
                '    TLSCACertificateFile          "'.$base.'/fullchain.pem"',
                '    TLSVerifyClient               off',
                '    TLSRequired                   off',
            ]);
        }
    }

    return '';
}
