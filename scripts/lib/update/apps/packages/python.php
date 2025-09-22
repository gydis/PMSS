<?php
/**
 * Python ecosystems and related tooling.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallPythonToolchain(int $distroVersion): void
{
    pmssInstallBestEffort([
        'libffi-dev',
        ['python3-dev', 'python-dev'],
        'python3-venv',
        ['python3-virtualenv', 'python-virtualenv'],
        ['python3-pip', 'python-pip'],
    ], 'Python development toolchain');
}

function pmssInstallZncStack(int $distroVersion): void
{
    if ($distroVersion < 8) {
        return;
    }

    pmssInstallBestEffort([
        'znc',
        'znc-perl',
        'znc-tcl',
        ['znc-python3', 'znc-python'],
        'git',
    ], 'ZNC stack');

    pmssInstallBestEffort([
        'python3',
        ['python3-pip', 'python-pip'],
        ['python3-virtualenv', 'python-virtualenv'],
    ], 'Python base packages');
    passthru('pip3 install --upgrade git+https://github.com/yadayada/acd_cli.git;');

    pmssInstallBestEffort([
        'python',
        'python3',
        ['python3-twisted', 'python-twisted'],
        ['python3-openssl', 'python-openssl'],
        ['python3-setuptools', 'python-setuptools'],
        'intltool',
        ['python3-xdg', 'python-xdg'],
        ['python3-chardet', 'python-chardet'],
        'geoip-database',
        ['python3-libtorrent', 'python-libtorrent'],
        ['python3-notify2', 'python-notify'],
        ['python3-pygame', 'python-pygame'],
        ['python3-gi', 'python-glade2'],
        'librsvg2-common',
        'xdg-utils',
        ['python3-mako', 'python-mako'],
        ['python3-setproctitle', 'python-setproctitle'],
    ], 'Python application stack');

    if (!file_exists('/usr/bin/ffmpeg')) {
        pmssQueuePackage('ffmpeg');
    }

    passthru('systemctl disable lighttpd');
}
