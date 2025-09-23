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
    pmssQueuePackages(['screen', 'mc', 'wget', 'gawk', 'subversion', 'libtool', 'libncurses5', 'sqlite', 'locate', 'ntpdate']);
    pmssInstallBestEffort([
        ['python3-pycurl', 'python-pycurl'],
        ['python3-crypto', 'python-crypto'],
        ['python3-cheetah', 'python-cheetah'],
    ], 'Python support libraries');
    pmssQueuePackages(['zip', 'unzip', 'bwm-ng', 'sysstat', 'apache2-utils', 'irssi', 'iotop', 'ethtool']);

    if ($distroVersion >= 8) {
        pmssQueuePackages(['unrar-free', 'unp']);
    } else {
        pmssQueuePackages(['unrar', 'rar', 'php-apc']);
    }
}

function pmssInstallMediaAndNetworkTools(int $distroVersion): void
{
    if ($distroVersion >= 10) {
        pmssQueuePackages(['libzen0v5', 'sox', 'tmux', 'tree', 'ncdu', 'weechat', 'php-xml', 'php-zip', 'php-sqlite3', 'php-mbstring', 'qbittorrent-nox']);
        if (!file_exists('/etc/apt/sources.list.d/mediaarea.list')) {
            runStep('Adding mediaarea repository', 'wget https://mediaarea.net/repo/deb/repo-mediaarea_1.0-20_all.deb && dpkg -i repo-mediaarea_1.0-20_all.deb && apt-get update');
        }
        pmssQueuePackages(['mediainfo', 'libmediainfo0v5']);
    } else {
        pmssQueuePackages(['sox', 'nzbget', 'tmux', 'tree', 'ncdu', 'weechat']);
    }

    pmssQueuePackages(['zsh', 'atop', 'php-cgi', 'php-cli']);
    pmssQueuePackages(['aria2', 'htop', 'mtr', 'mktorrent']);
    pmssQueuePackages(['genisoimage', 'xorriso']);
    pmssQueuePackages(['uidmap']);
    pmssQueuePackages(['net-tools', 'nicstat']);
    pmssQueuePackages(['restic', 'borgbackup', 'borgmatic', 'borgbackup-doc', 'backupninja']);

    pmssQueuePackages(['links', 'elinks', 'lynx', 'ethtool', 'zip', 'p7zip-full', 'smartmontools', 'flac', 'lame', 'lame-doc', 'mp3diags', 'gcc', 'g++', 'gettext', 'fuse', 'glib-networking', 'libglib2.0-dev', 'libfuse-dev', 'apt-transport-https', 'pigz']);
    pmssInstallBestEffort([
        ['python3-cheetah', 'python-cheetah'],
    ], 'Python Cheetah templates');

    // #TODO revisit curl/libcurl upgrades once a consistent backports policy is defined.
    pmssQueuePackages(['unionfs-fuse', 'sshfs', 's3fs']);
    pmssQueuePackages(['ranger', 'nethack-console']);

    if ($distroVersion >= 10) {
        pmssQueuePackages(['libmozjs-52-0', 'libmozjs-60-0']);
    } else {
        pmssQueuePackages(['libmozjs185-1.0', 'libmozjs-24-0']);
    }

    if ($distroVersion == 10) {
        pmssQueuePackages(['linux-image-amd64', 'firmware-bnx2', 'firmware-bnx2x'], 'buster-backports');
    }

    pmssQueuePackages(['libarchive-zip-perl', 'libnet-ssleay-perl', 'libhtml-parser-perl', 'libxml-libxml-perl', 'libjson-perl', 'libjson-xs-perl', 'libxml-libxslt-perl']);
    pmssQueuePackages(['lftp']);
    pmssQueuePackages(['nginx', 'ntp']);
    runStep('Stopping nginx to prepare for configuration refresh', '/etc/init.d/nginx stop');
}
