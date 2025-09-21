<?php
/**
 * Install / manage apt packages required by PMSS deployments.
 */

putenv('DEBIAN_FRONTEND=noninteractive');
putenv('APT_LISTCHANGES_FRONTEND=none');

#TODO dpkg get sel set sel | ... 
#TODO Move out of this directory so we can just glob everything here.

passthru('apt-get clean; apt-get update;');

passthru('apt --fix-broken install -y; dpkg --configure -a');  // Sometimes a bit broken ...
passthru('apt-get full-upgrade -y;');

passthru('apt-get install lighttpd lighttpd-mod-webdav -y;');

if ($distroVersion >= 10) passthru('apt-get install proftpd-core proftpd-basic proftpd-mod-crypto proftpd-mod-wrap -y; apt-get install nftables -y;');
    else passthru('apt-get install proftpd-basic -y');

passthru('apt-get install screen mc wget gawk subversion libtool libncurses5 sqlite locate ntpdate -y');
passthru('apt-get install python-pycurl python-crypto python-cheetah -y');
passthru('apt-get install zip unzip bwm-ng sysstat apache2-utils irssi iotop ethtool -y');
if ($distroVersion >= 8) passthru('apt-get install unrar-free unp -y');   # Deb8, Deb10
    else passthru('apt-get install unrar rar php-apc -y');

if ($distroVersion >= 10) passthru('apt-get install libzen0v5 sox tmux tree ncdu weechat php-xml php-zip php-sqlite3 php-mbstring -y; apt remove avahi-daemon mediainfo libmediainfo0v5 -y; apt install qbittorrent-nox -y; wget https://mediaarea.net/repo/deb/repo-mediaarea_1.0-20_all.deb && dpkg -i repo-mediaarea_1.0-20_all.deb && apt-get update; apt-get install mediainfo libmediainfo0v5 -y');
    else `apt-get install sox nzbget tmux tree ncdu weechat -y`;

passthru('apt-get install zsh atop -y');

// 2025-02-19: Sigh, issues of not using dpkg get/set sel; some upgraded systems now are missing php-cgi ... what?
passthru('apt-get install php-cgi php-cli -y');

// Following from update-step2 prior 29/04/2019
passthru('apt-get -f install -y');  # To fix potentially broken dependencies
passthru('apt-get remove netcat netcat-traditional mercurial -y');
passthru('apt-get remove netcat6 go -y');
passthru('apt-get install aria2 htop mtr mktorrent -y');
passthru('apt-get install genisoimage xorriso -y');
passthru('apt-get install uidmap -y');  // no fuse-overlayfs, supplied by docker. This is for docker rootless to function

passthru('apt-get install net-tools nicstat -y');

passthru('apt-get install restic borgbackup borgmatic borgbackup-doc backupninja -y');

passthru('apt-get install links elinks lynx ethtool zip p7zip-full smartmontools flac lame lame-doc mp3diags gcc g++ gettext python-cheetah fuse glib-networking libglib2.0-dev libfuse-dev apt-transport-https pigz -y');
passthru('apt-get install -t buster-backports curl libcurl4 -y');	// Fixes rtorrent crashes circa 12/2022
passthru('apt-get install unionfs-fuse sshfs s3fs -y');
passthru('apt-get install ranger nethack-console -y');
// Spidermonkey
if ($distroVersion >= 10) passthru('apt-get install libmozjs-52-0 libmozjs-60-0 -y');
    else passthru('apt-get install libmozjs185-1.0 libmozjs-24-0 -y');

// Kernel backport for buster
if ($distroVersion == 10) {
   echo `apt install -t buster-backports linux-image-amd64 firmware-bnx2 firmware-bnx2x -y;`;
}


// Following is for autodl-irssi required packages
passthru('apt-get -y install libarchive-zip-perl libnet-ssleay-perl libhtml-parser-perl libxml-libxml-perl libjson-perl libjson-xs-perl libxml-libxslt-perl');

// New additional packages, do not remove 03/2016
passthru('apt-get -y install lftp');

passthru("apt-get install nginx ntp -y; /etc/init.d/nginx stop");

// Let's fix python-pip
//passthru('apt-get install python-pip libffi-dev python-dev -y');
passthru('apt-get install libffi-dev python-dev python3-venv -y');
if ($distroVersion < 10) passthru('apt-get remove python-pip -y');
	else passthru('apt-get install python-pip -y');

#Install Sabnzbdplus
if (!file_exists('/usr/bin/sabnzbdplus')) {
    echo "## Installing Sabnzbdplus\n";
    //passthru('echo "deb http://ppa.launchpad.net/jcfp/ppa/ubuntu precise main" | tee -a /etc/apt/sources.list; apt-key adv --keyserver hkp://pool.sks-keyservers.net:11371 --recv-keys 0x98703123E0F52B2BE16D586EF13930B14BB9F05F');
    if ($distroVersion <= 1) passthru('apt-key adv --keyserver hkp://pool.sks-keyservers.net:11371 --recv-keys 0x98703123E0F52B2BE16D586EF13930B14BB9F05F');
    passthru('apt-get install sabnzbdplus -y;');
}


if ($distroVersion >= 8) {    // Deb8, 9 or 10
    passthru('apt-get install znc znc-perl znc-tcl znc-python git -y;');
    passthru('apt-get install git -y'); // For unknown reason git won't install on above line, but rest of the packages do
    #Let's install pythont3+acd cli
    passthru('apt-get install python3 python3-pip python-virtualenv -y;');
    passthru('pip3 install --upgrade git+https://github.com/yadayada/acd_cli.git;');

    passthru('apt-get install -y python python-twisted python-openssl python-setuptools intltool python-xdg python-chardet geoip-database python-libtorrent python-notify python-pygame python-glade2 librsvg2-common xdg-utils python-mako python-setproctitle python3-setproctitle');


    if (!file_exists('/usr/bin/ffmpeg') ) {
        passthru('apt-get install ffmpeg -y');
    }

    passthru('systemctl disable lighttpd'); // Stop lighttpd starting

}


#Install mkvtoolnix
if (!file_exists('/usr/bin/mkvextract'))
    passthru('apt-get install mkvtoolnix -y');

# Install openvpn
if (!file_exists('/usr/sbin/openvpn'))
    passthru('apt-get install openvpn easy-rsa -y ');

// Veeeery old legacy probably no need for this
passthru('apt-get remove munin -y');
passthru('apt-get install sudo -y');
passthru('apt-get remove consolekit -y');	// remove consolekit

#Install Expect for the migrations code
passthru('apt-get install expect -y');

if (!file_exists('/sbin/ipset'))
    passthru('apt-get install ipset -y');   #IPSet is required for Firehol

