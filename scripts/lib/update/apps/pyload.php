<?php
/**
 * Install or upgrade pyLoad (pyload-ng) using a Python 3 virtual environment.
 */

require_once __DIR__.'/packages/helpers.php';

$distroVersion = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
if ($distroVersion > 0 && $distroVersion < 10) {
    if (function_exists('logmsg')) {
        logmsg('[WARN] Skipping pyLoad setup: unsupported Debian release');
    }
    return;
}

$venvDir  = '/opt/pyload';
$binDir   = $venvDir.'/bin';
$pyloadBin = $binDir.'/pyload';
$pythonBin = $binDir.'/python';

$aptDeps = [
    'python3',
    'python3-distutils',
    'python3-venv',
    'python3-pip',
    'libffi-dev',
    'libssl-dev',
    'libjpeg-dev',
    'zlib1g-dev',
];

$aptArgs = implode(' ', array_map('escapeshellarg', $aptDeps));
runStep('Installing pyLoad dependencies', aptCmd('install -y '.$aptArgs));

if (!is_dir($venvDir)) {
    runStep('Creating pyLoad virtualenv', sprintf('python3 -m venv %s', escapeshellarg($venvDir)));
}

runStep('Upgrading virtualenv pip/setuptools', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));
runStep('Installing pyLoad (pyload-ng)', sprintf('%s -m pip install --upgrade pyload-ng', escapeshellarg($pythonBin)));

if (!is_link('/usr/local/bin/pyload')) {
    runStep('Linking pyLoad executable', sprintf('ln -sf %s %s', escapeshellarg($pyloadBin), escapeshellarg('/usr/local/bin/pyload')));
}
