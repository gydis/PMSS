<?php
/**
 * Base system package groups.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallBaseTools(): void
{
    pmssQueuePackages(['lighttpd', 'lighttpd-mod-webdav']);
}

function pmssInstallSystemUtilities(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping system utility install: unsupported Debian release');
        return;
    }

    pmssQueuePackages(['screen', 'mc', 'wget', 'gawk', 'subversion', 'libtool', 'sqlite', 'locate', 'ntpdate', 'build-essential', 'pkg-config', 'autoconf', 'automake', 'python3', 'python3-pip', 'python3-venv', 'python3-dev']);
    pmssInstallBestEffort([
        'libncurses6',
    ], 'ncurses runtime');
    pmssInstallBestEffort([
        'libncurses-dev',
    ], 'ncurses development headers');
    pmssQueuePackages(['python3-pycurl', 'python3-crypto', 'python3-cheetah']);
    pmssQueuePackages(['zip', 'unzip', 'bwm-ng', 'sysstat', 'apache2-utils', 'irssi', 'iotop', 'ethtool']);
    pmssQueuePackages(['unrar-free', 'unp']);
}

function pmssInstallMediaAndNetworkTools(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping media/network tool install: unsupported Debian release');
        return;
    }

    pmssQueuePackages(['libzen0v5', 'sox', 'tmux', 'tree', 'ncdu', 'weechat', 'php-xml', 'php-zip', 'php-sqlite3', 'php-mbstring', 'qbittorrent-nox']);
    if (!file_exists('/etc/apt/sources.list.d/mediaarea.list')) {
        runStep('Adding mediaarea repository', 'wget https://mediaarea.net/repo/deb/repo-mediaarea_1.0-20_all.deb && dpkg -i repo-mediaarea_1.0-20_all.deb && apt-get update');
    }
    pmssQueuePackages(['mediainfo', 'libmediainfo0v5']);

    pmssQueuePackages(['zsh', 'atop', 'php-cgi', 'php-cli']);
    pmssQueuePackages(['aria2', 'htop', 'mtr', 'mktorrent']);
    pmssQueuePackages(['genisoimage', 'xorriso']);
    pmssQueuePackages(['uidmap']);
    pmssQueuePackages(['net-tools', 'nicstat']);
    pmssQueuePackages(['restic', 'borgbackup', 'borgmatic', 'borgbackup-doc', 'backupninja']);

    pmssQueuePackages(['links', 'elinks', 'lynx', 'ethtool', 'zip', 'p7zip-full', 'smartmontools', 'flac', 'lame', 'lame-doc', 'mp3diags', 'gcc', 'g++', 'gettext', 'fuse3', 'glib-networking', 'libglib2.0-dev', 'libfuse-dev', 'apt-transport-https', 'pigz']);
    pmssQueuePackages(['python3-cheetah']);

    // #TODO revisit curl/libcurl upgrades once a consistent backports policy is defined.
    pmssQueuePackages(['unionfs-fuse', 'sshfs', 's3fs']);
    pmssQueuePackages(['ranger', 'nethack-console']);

    pmssQueuePackages(['libmozjs-52-0', 'libmozjs-60-0']);

    if ($distroVersion == 10) {
        pmssQueuePackages(['linux-image-amd64', 'firmware-bnx2', 'firmware-bnx2x'], 'buster-backports');
    }

    pmssQueuePackages(['libarchive-zip-perl', 'libnet-ssleay-perl', 'libhtml-parser-perl', 'libxml-libxml-perl', 'libjson-perl', 'libjson-xs-perl', 'libxml-libxslt-perl']);
    pmssQueuePackages(['lftp']);
    pmssQueuePackages(['nginx', 'ntp']);
    runStep('Stopping nginx to prepare for configuration refresh', '/etc/init.d/nginx stop');
}
