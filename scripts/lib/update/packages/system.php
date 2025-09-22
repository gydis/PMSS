<?php
/**
 * Base system package groups.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallBaseTools(): void
{
    passthru('apt-get clean; apt-get update;');
    passthru('apt --fix-broken install -y; dpkg --configure -a');
    passthru('apt-get full-upgrade -y;');
    passthru('apt-get install lighttpd lighttpd-mod-webdav -y;');
}

function pmssInstallSystemUtilities(int $distroVersion): void
{
    passthru('apt-get install screen mc wget gawk subversion libtool libncurses5 sqlite locate ntpdate -y');
    pmssInstallBestEffort([
        ['python3-pycurl', 'python-pycurl'],
        ['python3-crypto', 'python-crypto'],
        ['python3-cheetah', 'python-cheetah'],
    ], 'Python support libraries');
    passthru('apt-get install zip unzip bwm-ng sysstat apache2-utils irssi iotop ethtool -y');

    if ($distroVersion >= 8) {
        passthru('apt-get install unrar-free unp -y');
    } else {
        passthru('apt-get install unrar rar php-apc -y');
    }
}

function pmssInstallMediaAndNetworkTools(int $distroVersion): void
{
    if ($distroVersion >= 10) {
        passthru('apt-get install libzen0v5 sox tmux tree ncdu weechat php-xml php-zip php-sqlite3 php-mbstring -y; '
            .'apt remove avahi-daemon mediainfo libmediainfo0v5 -y; '
            .'apt install qbittorrent-nox -y; '
            .'wget https://mediaarea.net/repo/deb/repo-mediaarea_1.0-20_all.deb && dpkg -i repo-mediaarea_1.0-20_all.deb && apt-get update; '
            .'apt-get install mediainfo libmediainfo0v5 -y');
    } else {
        passthru('apt-get install sox nzbget tmux tree ncdu weechat -y');
    }

    passthru('apt-get install zsh atop -y');
    passthru('apt-get install php-cgi php-cli -y');
    passthru('apt-get -f install -y');
    passthru('apt-get remove netcat netcat-traditional mercurial -y');
    passthru('apt-get remove netcat6 go -y');
    passthru('apt-get install aria2 htop mtr mktorrent -y');
    passthru('apt-get install genisoimage xorriso -y');
    passthru('apt-get install uidmap -y');
    passthru('apt-get install net-tools nicstat -y');
    passthru('apt-get install restic borgbackup borgmatic borgbackup-doc backupninja -y');

    passthru('apt-get install links elinks lynx ethtool zip p7zip-full smartmontools flac lame lame-doc mp3diags gcc g++ gettext fuse glib-networking libglib2.0-dev libfuse-dev apt-transport-https pigz -y');
    pmssInstallBestEffort([
        ['python3-cheetah', 'python-cheetah'],
    ], 'Python Cheetah templates');

    $backports = pmssBackportSuite($distroVersion);
    if ($backports !== null) {
        passthru(sprintf('apt-get install -t %s curl libcurl4 -y', escapeshellarg($backports)));
    }
    passthru('apt-get install unionfs-fuse sshfs s3fs -y');
    passthru('apt-get install ranger nethack-console -y');

    if ($distroVersion >= 10) {
        passthru('apt-get install libmozjs-52-0 libmozjs-60-0 -y');
    } else {
        passthru('apt-get install libmozjs185-1.0 libmozjs-24-0 -y');
    }

    if ($distroVersion == 10) {
        passthru('apt install -t buster-backports linux-image-amd64 firmware-bnx2 firmware-bnx2x -y');
    }

    passthru('apt-get -y install libarchive-zip-perl libnet-ssleay-perl libhtml-parser-perl libxml-libxml-perl libjson-perl libjson-xs-perl libxml-libxslt-perl');
    passthru('apt-get -y install lftp');
    passthru("apt-get install nginx ntp -y; /etc/init.d/nginx stop");
}
