#!/usr/bin/php
<?php
/**
 * WireGuard provisioning for PMSS deployments.
 */

require_once __DIR__.'/../users.php';

if (!function_exists('logmsg')) {
    require_once __DIR__.'/../update.php';
}

function wgLog(string $message): void
{
    logmsg('[wireguard] '.$message);
}

function wgInstallPackages(): void
{
    $cmd = 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none '
        .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
        .'install -y wireguard wireguard-tools wireguard-dkms';
    passthru($cmd, $rc);
    if ($rc !== 0) {
        wgLog('Package installation returned rc='.$rc);
    }
}

function wgSupports(): bool
{
    exec('command -v wg', $out, $rc);
    if ($rc !== 0) {
        wgLog('wg binary not available after installation');
        return false;
    }
    return true;
}

function wgEnsureKeys(string $dir): array
{
    $privFile = $dir.'/server_private.key';
    $pubFile  = $dir.'/server_public.key';

    if (file_exists($privFile) && file_exists($pubFile)) {
        return [trim((string)file_get_contents($privFile)), trim((string)file_get_contents($pubFile))];
    }

    exec('wg genkey', $privOut, $rc);
    $priv = $privOut[0] ?? '';
    if ($rc !== 0 || $priv === '') {
        wgLog('Failed to generate server private key');
        return ['', ''];
    }

    exec('echo '.escapeshellarg($priv).' | wg pubkey', $pubOut, $rc);
    $pub = $pubOut[0] ?? '';
    if ($rc !== 0 || $pub === '') {
        wgLog('Failed to derive server public key');
        return ['', ''];
    }

    file_put_contents($privFile, $priv.PHP_EOL);
    file_put_contents($pubFile, $pub.PHP_EOL);
    chmod($privFile, 0600);
    chmod($pubFile, 0640);
    return [$priv, $pub];
}

function wgRenderTemplate(string $path, array $placeholders): ?string
{
    $template = @file_get_contents($path);
    if ($template === false) {
        wgLog('Template missing: '.$path);
        return null;
    }
    return str_replace(array_keys($placeholders), array_values($placeholders), $template);
}

function wgWriteConfig(string $privKey, int $port): void
{
    $configPath = '/etc/wireguard/wg0.conf';
    if (file_exists($configPath)) {
        wgLog('Existing wg0.conf detected; skipping overwrite');
        return;
    }

    $rendered = wgRenderTemplate(
        '/etc/seedbox/config/template.wireguard.conf',
        [
            '%PRIVATE_KEY%'  => $privKey,
            '%LISTEN_PORT%'  => (string)$port,
        ]
    );
    if ($rendered === null) {
        return;
    }

    file_put_contents($configPath, $rendered.PHP_EOL);
    chmod($configPath, 0640);
    wgLog('Base configuration written to '.$configPath);
}

function wgWriteReadme(string $pubKey, int $port): string
{
    $hostname = trim((string)file_get_contents('/etc/hostname'));
    $endpoint = gethostbyname($hostname);
    $rendered = wgRenderTemplate(
        '/etc/seedbox/config/template.wireguard.readme',
        [
            '%HOSTNAME%'   => $hostname,
            '%ENDPOINT%'   => $endpoint,
            '%PUBLIC_KEY%' => $pubKey,
            '%LISTEN_PORT%' => (string)$port,
        ]
    );
    if ($rendered === null) {
        return '';
    }
    file_put_contents('/etc/wireguard/README', $rendered);
    return $rendered;
}

function wgDistributeToUsers(string $content): void
{
    if ($content === '') {
        return;
    }
    foreach (users::listHomeUsers() as $user) {
        $target = "/home/{$user}/wireguard.txt";
        @file_put_contents($target, $content);
        @chown($target, $user);
        @chgrp($target, $user);
        @chmod($target, 0600);
    }
}

function wgEnableService(): void
{
    if (!is_dir('/run/systemd/system')) {
        wgLog('systemd unavailable; skipping wg-quick@wg0 enable');
        return;
    }
    exec('systemctl enable --now wg-quick@wg0', $_, $rc);
    if ($rc !== 0) {
        wgLog('wg-quick@wg0 failed to start (rc='.$rc.')');
    }
}

if (!is_dir('/etc/wireguard')) {
    @mkdir('/etc/wireguard', 0750, true);
}

wgInstallPackages();
if (!wgSupports()) {
    return;
}

[$privKey, $pubKey] = wgEnsureKeys('/etc/wireguard');
if ($privKey === '' || $pubKey === '') {
    return;
}

$listenPort = 51820;
wgWriteConfig($privKey, $listenPort);
$guide = wgWriteReadme($pubKey, $listenPort);
wgDistributeToUsers($guide);
wgEnableService();
