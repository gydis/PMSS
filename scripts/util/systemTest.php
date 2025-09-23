#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * PMSS system status probe.
 *
 * Aggregates non-destructive checks to highlight runtime readiness. Intended for
 * production hosts; development environments may report WARN for missing
 * packages.
 */

require_once __DIR__.'/../lib/runtime.php';

/**
 * Execute a command and return trimmed output.
 */
function pmssExec(string $command): string
{
    $output = @shell_exec($command);
    return $output === null ? '' : trim((string)$output);
}

/**
 * Normalize a status tuple for display.
 */
function renderStatus(array $result): void
{
    $label  = str_pad('['.$result['status'].']', 9);
    $detail = $result['detail'] !== '' ? ' - '.$result['detail'] : '';
    echo $label.$result['name'].$detail.PHP_EOL;
}

$checks = [];

// Detect OS codename for later comparisons.
$osInfo    = parse_ini_file('/etc/os-release') ?: [];
$codename  = strtolower(trim($osInfo['VERSION_CODENAME'] ?? ''));
$checks[] = (function () use ($codename) {
    if ($codename === '') {
        return ['name' => 'OS codename', 'status' => 'WARN', 'detail' => 'VERSION_CODENAME missing'];
    }
    return ['name' => 'OS codename', 'status' => 'OK', 'detail' => $codename];
})();

$binaryChecks = [
    'rtorrent' => 'rtorrent -h 2>&1 | head -n 1',
    'nginx'    => 'nginx -v 2>&1',
    'php'      => 'php -v 2>&1 | head -n 1',
    'proftpd'  => 'proftpd -v 2>&1 | head -n 1',
    'openvpn'  => 'openvpn --version 2>&1 | head -n 1',
];

foreach ($binaryChecks as $binary => $infoCmd) {
    $exists = pmssExec('command -v '.escapeshellarg($binary));
    if ($exists === '') {
        $checks[] = [
            'name'   => sprintf('Binary: %s', $binary),
            'status' => 'WARN',
            'detail' => 'Not found in PATH',
        ];
        continue;
    }
    $detail = pmssExec($infoCmd);
    $checks[] = [
        'name'   => sprintf('Binary: %s', $binary),
        'status' => 'OK',
        'detail' => $detail !== '' ? $detail : 'present',
    ];
}

$configPaths = [
    'Apt sources'          => '/etc/apt/sources.list',
    'ProFTPD configuration' => '/etc/proftpd/proftpd.conf',
    'OpenVPN directory'     => '/etc/openvpn',
    'VPN Easy-RSA'          => '/etc/openvpn/easy-rsa',
    'Seedbox localnet'      => '/etc/seedbox/localnet',
    'Nginx directory'       => '/etc/nginx',
];

foreach ($configPaths as $label => $path) {
    if (is_dir($path) || is_file($path)) {
        $checks[] = ['name' => $label, 'status' => 'OK', 'detail' => $path];
    } else {
        $checks[] = ['name' => $label, 'status' => 'WARN', 'detail' => $path.' missing'];
    }
}

// Validate sources list contains detected codename if possible.
if ($codename !== '' && is_file('/etc/apt/sources.list')) {
    $sources = file_get_contents('/etc/apt/sources.list');
    if ($sources !== false && stripos($sources, $codename) === false) {
        $checks[] = [
            'name'   => 'Sources codename match',
            'status' => 'WARN',
            'detail' => sprintf("%s not present in sources.list", $codename),
        ];
    } else {
        $checks[] = [
            'name'   => 'Sources codename match',
            'status' => 'OK',
            'detail' => 'sources.list references '.$codename,
        ];
    }
}

// Render summary banner.
echo "\nPMSS System Check (".date('Y-m-d H:i:s').")\n";
echo str_repeat('-', 60)."\n";

foreach ($checks as $result) {
    renderStatus($result);
}

echo str_repeat('-', 60)."\n";
$errors = count(array_filter($checks, static fn($c) => $c['status'] === 'ERR'));
$warnings = count(array_filter($checks, static fn($c) => $c['status'] === 'WARN'));

echo sprintf("Summary: %d OK, %d WARN, %d ERR\n", count($checks) - $warnings - $errors, $warnings, $errors);
exit(0);
