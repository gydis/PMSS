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

/**
 * Return the directory used for WireGuard configuration state.
 */
function wgConfigDir(): string
{
    $override = getenv('PMSS_WG_CONFIG_DIR');
    if ($override !== false && $override !== '') {
        return rtrim($override, '/');
    }
    return '/etc/wireguard';
}

/**
 * Compose an absolute path inside the WireGuard configuration directory.
 */
function wgConfigPath(string $file): string
{
    return wgConfigDir().'/'.$file;
}

/**
 * Resolve the home directory base used when distributing user files.
 */
function wgHomeBase(): string
{
    $override = getenv('PMSS_WG_HOME_BASE');
    if ($override !== false && $override !== '') {
        return rtrim($override, '/');
    }
    return '/home';
}

/**
 * Enumerate tenants targeted for configuration distribution.
 */
function wgListHomeUsers(): array
{
    $override = getenv('PMSS_WG_USER_LIST');
    if ($override !== false && $override !== '') {
        $users = array_filter(array_map('trim', explode(',', $override)), 'strlen');
        return array_values($users);
    }
    return users::listHomeUsers();
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

/**
 * Produce a WireGuard private key, optionally via test overrides.
 */
function wgGeneratePrivateKey(): string
{
    $override = getenv('PMSS_WG_PRIVATE_KEY');
    if ($override !== false) {
        return trim($override);
    }

    exec('wg genkey', $privOut, $rc);
    return $rc === 0 ? trim($privOut[0] ?? '') : '';
}

/**
 * Derive the WireGuard public key from the supplied private key.
 */
function wgDerivePublicKey(string $private): string
{
    $override = getenv('PMSS_WG_PUBLIC_KEY');
    if ($override !== false) {
        return trim($override);
    }

    exec('echo '.escapeshellarg($private).' | wg pubkey', $pubOut, $rc);
    return $rc === 0 ? trim($pubOut[0] ?? '') : '';
}

/**
 * Confirm that the supplied address is a routable public IPv4 endpoint.
 */
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

/**
 * Query the external helper service for the host's public address.
 */
function wgFetchExternalEndpoint(): ?string
{
    // #TODO Replace with an internal endpoint discovery helper instead of calling out.
    $override = getenv('PMSS_WG_EXTERNAL_IP');
    if ($override !== false) {
        return $override === '' ? null : wgValidatePublicIp($override);
    }

    $url     = 'https://pulsedmedia.com/remote/myip.php';
    $context = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    return wgValidatePublicIp($response);
}

/**
 * Discover the IPv4 address currently bound to the uplink interface.
 */
function wgDetectInterfaceAddress(): ?string
{
    $override = getenv('PMSS_WG_INTERFACE_IP');
    if ($override !== false && $override !== '') {
        return trim($override);
    }

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

/**
 * Determine the best endpoint to advertise to tenants.
 */
function wgResolveEndpoint(string $hostname): array
{
    // Prefer DNS resolution before hitting external services or interface inspection.
    $hostnameIp = '';
    if ($hostname !== '') {
        $dnsOverride = getenv('PMSS_WG_DNS_IP');
        if ($dnsOverride !== false && $dnsOverride !== '') {
            $resolved = $dnsOverride;
        } else {
            $resolved = gethostbyname($hostname);
        }
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

/**
 * Ensure server key material exists, creating it when missing.
 */
function wgEnsureKeys(string $dir): array
{
    $privFile = $dir.'/server_private.key';
    $pubFile  = $dir.'/server_public.key';

    if (file_exists($privFile) && file_exists($pubFile)) {
        return [trim((string)file_get_contents($privFile)), trim((string)file_get_contents($pubFile))];
    }

    $priv = wgGeneratePrivateKey();
    if ($priv === '') {
        wgLog('Failed to generate server private key');
        return ['', ''];
    }

    $pub = wgDerivePublicKey($priv);
    if ($pub === '') {
        wgLog('Failed to derive server public key');
        return ['', ''];
    }

    file_put_contents($privFile, $priv.PHP_EOL);
    file_put_contents($pubFile, $pub.PHP_EOL);
    chmod($privFile, 0600);
    chmod($pubFile, 0640);
    return [$priv, $pub];
}

/**
 * Render the provided template file with placeholder replacements.
 */
function wgRenderTemplate(string $path, array $placeholders): ?string
{
    $template = @file_get_contents($path);
    if ($template === false) {
        wgLog('Template missing: '.$path);
        return null;
    }
    return str_replace(array_keys($placeholders), array_values($placeholders), $template);
}

/**
 * Lay down the initial WireGuard configuration when absent.
 */
function wgWriteConfig(string $privKey, int $port): void
{
    $configPath = wgConfigPath('wg0.conf');
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

/**
 * Refresh the operator README with the currently advertised endpoint.
 */
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
    file_put_contents(wgConfigPath('README'), $rendered);
    return $rendered;
}

/**
 * Copy connection instructions to every tenant home directory.
 */
function wgDistributeToUsers(string $content): void
{
    if ($content === '') {
        return;
    }
    $homeBase = wgHomeBase();
    foreach (wgListHomeUsers() as $user) {
        $target = $homeBase.'/'.$user.'/wireguard.txt';
        @file_put_contents($target, $content);
        @chown($target, $user);
        @chgrp($target, $user);
        @chmod($target, 0600);
    }
}

/**
 * Enable and start the wg-quick unit unless explicitly disabled.
 */
function wgEnableService(): void
{
    if (getenv('PMSS_WG_SKIP_SERVICE') === '1') {
        wgLog('Service enable skipped via PMSS_WG_SKIP_SERVICE');
        return;
    }
    if (!is_dir('/run/systemd/system')) {
        wgLog('systemd unavailable; skipping wg-quick@wg0 enable');
        return;
    }
    exec('systemctl enable --now wg-quick@wg0', $_, $rc);
    if ($rc !== 0) {
        wgLog('wg-quick@wg0 failed to start (rc='.$rc.')');
    }
}

if (!defined('PMSS_WIREGUARD_NO_ENTRYPOINT')) {
    if (!is_dir(wgConfigDir())) {
        @mkdir(wgConfigDir(), 0750, true);
    }

    if (!wgSupports()) {
        wgLog('WireGuard tooling missing; ensure packages are installed via pmssInstallWireguardPackages()');
        return;
    }

    [$privKey, $pubKey] = wgEnsureKeys(wgConfigDir());
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
}
