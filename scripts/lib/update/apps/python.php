<?php
/**
 * FlexGet + gdrivefs installer using a dedicated Python 3 virtual environment.
 */

require_once __DIR__.'/packages/helpers.php';

$python  = trim(shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
$venvDir = '/opt/flexget';
$pythonBin = $venvDir.'/bin/python';
$pipBin    = $venvDir.'/bin/pip';

runStep('Ensuring Python 3 tooling for FlexGet', aptCmd('install -y python3 python3-distutils python3-venv python3-setuptools'));

if (!is_dir($venvDir)) {
    runStep('Creating FlexGet virtualenv', sprintf('%s -m venv %s', escapeshellarg($python), escapeshellarg($venvDir)));
}

runStep('Upgrading FlexGet virtualenv tooling', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));

echo "### Install gdrivefs\n";
runStep('Installing gdrivefs in FlexGet venv', sprintf('%s install --upgrade gdrivefs', escapeshellarg($pipBin)));

echo "### Install/Update FlexGet:\n";
runStep('Installing FlexGet dependencies', sprintf("%s install --upgrade pyopenssl ndg-httpsclient cryptography funcsigs 'chardet==3.0.3' 'certifi==2017.4.17'", escapeshellarg($pipBin)));
runStep('Installing FlexGet', sprintf('%s install --upgrade flexget', escapeshellarg($pipBin)));
runStep('Installing youtube-dl for FlexGet', sprintf('%s install --upgrade youtube_dl', escapeshellarg($pipBin)));

if (!is_link('/usr/local/bin/flexget') || readlink('/usr/local/bin/flexget') !== $venvDir.'/bin/flexget') {
    runStep('Linking FlexGet CLI', sprintf('ln -sf %s %s', escapeshellarg($venvDir.'/bin/flexget'), escapeshellarg('/usr/local/bin/flexget')));
}
