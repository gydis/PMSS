#!/usr/bin/php
<?php
/**
 * WireGuard provisioning for PMSS deployments.
 */

require_once __DIR__.'/../users.php';
require_once __DIR__.'/../../networkInfo.php';

if (!function_exists('logmsg')) {
    require_once __DIR__.'/../update.php';
}

function wgLog(string $message): void
{
    logmsg('[wireguard] '.$message);
}

function wgSupports(): bool
{
    exec('command -v wg', $out, $rc);
    if ($rc !== 0) {
        wgLog('wg binary not available on PATH');
        return false;
    }
    return true;
}

function wgValidatePublicIp(string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    $ip    = filter_var($candidate, FILTER_VALIDATE_IP, $flags);
    return $ip === false ? null : $ip;
}

function wgFetchExternalEndpoint(): ?string
{
    // #TODO Replace with an internal endpoint discovery helper instead of calling out.
    $url     = 'https://pulsedmedia.com/remote/myip.php';
    $context = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    return wgValidatePublicIp($response);
}

function wgDetectInterfaceAddress(): ?string
{
    $interface = detectPrimaryInterface();
    if ($interface === '') {
        return null;
    }

    // Look for the primary IPv4 address associated with the uplink interface.
    $cmd = '/sbin/ip -4 -o addr show dev '.escapeshellarg($interface).' 2>/dev/null';
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
        return null;
    }

    foreach ($output as $line) {
        if (preg_match('/inet\\s+([0-9.]+)/', $line, $matches)) {
            return $matches[1];
        }
    }

    return null;
}

function wgResolveEndpoint(string $hostname): array
{
    // Prefer DNS resolution before hitting external services or interface inspection.
    $hostnameIp = '';
    if ($hostname !== '') {
        $resolved = gethostbyname($hostname);
        if ($resolved !== $hostname) {
            $hostnameIp = $resolved;
            $public     = wgValidatePublicIp($resolved);
            if ($public !== null) {
                return [$public, 'hostname'];
            }
        }
    }

    $external = wgFetchExternalEndpoint();
    if ($external !== null) {
        return [$external, 'external'];
    }

    $interfaceIp = wgDetectInterfaceAddress();
    if ($interfaceIp !== null) {
        $public = wgValidatePublicIp($interfaceIp);
        if ($public !== null) {
            return [$public, 'interface'];
        }
        return [$interfaceIp, 'interface_private'];
    }

    if ($hostnameIp !== '') {
        return [$hostnameIp, 'hostname_private'];
    }

    return ['', 'unknown'];
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

function wgWriteReadme(string $hostname, string $endpoint, string $pubKey, int $port): string
{
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

if (!wgSupports()) {
    wgLog('WireGuard tooling missing; ensure packages are installed via pmssInstallWireguardPackages()');
    return;
}

[$privKey, $pubKey] = wgEnsureKeys('/etc/wireguard');
if ($privKey === '' || $pubKey === '') {
    return;
}

$listenPort = 51820;
wgWriteConfig($privKey, $listenPort);

$hostname = trim((string)file_get_contents('/etc/hostname'));
[$endpoint, $endpointSource] = wgResolveEndpoint($hostname);
if ($endpoint === '') {
    // Ensure users still receive usable details even when endpoint discovery fails.
    wgLog('Unable to determine public endpoint; falling back to hostname '.$hostname);
    $endpoint = $hostname;
} else {
    wgLog(sprintf('Using %s endpoint %s', $endpointSource, $endpoint));
}

// Keep the README in sync so operators and users see the current endpoint.
$guide = wgWriteReadme($hostname, $endpoint, $pubKey, $listenPort);
wgDistributeToUsers($guide);
wgEnableService();
