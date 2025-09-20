#!/bin/bash
#Pulsed Media Seedbox Management Software package AKA Pulsed Media Software Stack "PMSS"
#
# LICENSE
# This work is licensed under the Creative Commons Attribution-ShareAlike 3.0 Unported License.
# To view a copy of this license, visit http://creativecommons.org/licenses/by-sa/3.0/ or send a letter to Creative Commons, 171 Second Street, Suite 300, San Francisco, California, 94105, USA.

# Copyright 2010-2024 Magna Capax Finland Oy
#
# USE AT YOUR OWN RISK! ABSOLUTELY NO GUARANTEES GIVEN!
# Pulsed Media does not take any responsibility over usage of this package, and will not be responsible for any damages, injuries, losses, or anything else for that matter due to the
# usage of this package.
#
# Please note: Install this on a fresh, minimal install Debian 64bit.
#
# For help, see http://wiki.pulsedmedia.com
# Github: https://github.com/MagnaCapax/PMSS

# Usage for special branch: bash ./install.sh "git/http://github.com/MagnaCapax/PMSS:update2-distro-support:2023-07-22"

DEFAULT_REPOSITORY="https://github.com/MagnaCapax/PMSS"
date=
type=
url=
repository=
branch=

# Installer runtime flags, populated from CLI switches.
hostname_override=
skip_hostname_edit=false
quota_mountpoint=
skip_quota_edit=false
POSITIONAL=()

# Parse CLI options for non-interactive installs and behavioural tweaks.
while [[ $# -gt 0 ]]; do
	case "$1" in
	--hostname)
		hostname_override="$2"
		shift 2
		continue
		;;
	--hostname=*)
		hostname_override="${1#*=}"
		shift
		continue
		;;
	--skip-hostname)
		skip_hostname_edit=true
		shift
		continue
		;;
	--quota-mount)
		quota_mountpoint="$2"
		shift 2
		continue
		;;
	--quota-mount=*)
		quota_mountpoint="${1#*=}"
		shift
		continue
		;;
	--skip-quota)
		skip_quota_edit=true
		shift
		continue
		;;
	--skip-quota=*)
		skip_quota_edit=true
		shift
		continue
		;;
	--help)
		echo "Usage: bash install.sh [update-source] [options...]"
		echo "  --hostname=<name>      set system hostname non-interactively"
		echo "  --skip-hostname        skip hostname confirmation"
		echo "  --quota-mount=<path>   add quota options to specified fstab mount"
		echo "  --skip-quota           skip quota guidance section"
		exit 0
		;;
	--*)
		echo "Unknown option: $1"
		exit 1
		;;
	*)
		POSITIONAL+=("$1")
		shift
		;;
	esac
done

set -- "${POSITIONAL[@]}"

SOURCE_SPEC="$1"

parse_version_string() {
	local input_string="$1"

	if [[ $input_string =~ (^git|^release)\/(.*)[:]?([0-9]{4}-[0-9]{2}-[0-9]{2}([ ]?[0-9]{2}[:][0-9]{2})?)$ ]]; then
		type="${BASH_REMATCH[1]}"
		url="${BASH_REMATCH[2]}"
		date="${BASH_REMATCH[3]}"
		echo "Type: $type"
		echo "URL: $url"
		echo "Date: $date"
		if [[ $url =~ (.*[^:])[:](.*[^:])[:]?$ ]]; then
			repository="${BASH_REMATCH[1]}"
			branch="${BASH_REMATCH[2]}"
			echo "Repository: $repository"
			echo "Branch: $branch"

		elif [[ $url =~ (^main)[:]$ ]]; then
			repository=$DEFAULT_REPOSITORY
			branch="${BASH_REMATCH[1]}"
			echo "Repository: $repository"
			echo "Branch: $branch"
		else
			echo "Url doesn't match, using defaults"
			repository=$DEFAULT_REPOSITORY
			branch="main"
		fi
	else
		echo "Invalid input format."
	fi
}

# Install packages only if missing to avoid accidental removals
ensure_packages() {
	local pkg
	local missing=()
	# Collect packages that are not installed but available in repositories
	for pkg in "$@"; do
		if dpkg -s "$pkg" >/dev/null 2>&1; then
			continue
		fi
		if apt-cache show "$pkg" >/dev/null 2>&1; then
			missing+=("$pkg")
		else
			echo "## Package $pkg not found in repositories, skipping"
		fi
	done

	if [ ${#missing[@]} -eq 0 ]; then
		return
	fi

	echo "## Installing missing packages: ${missing[*]}"
	local chunk=()
	local len=0
	local max_len=30000
	for pkg in "${missing[@]}"; do
		chunk+=("$pkg")
		len=$((len + ${#pkg} + 1))
		if [ $len -ge $max_len ]; then
			apt-get install -yq "${chunk[@]}"
			chunk=()
			len=0
		fi
	done

	if [ ${#chunk[@]} -gt 0 ]; then
		apt-get install -yq "${chunk[@]}"
	fi
}

export DEBIAN_FRONTEND=noninteractive
echo "## Updating package lists"
apt update
# Perform the full-upgrade
echo "## Running full-upgrade"
apt-get full-upgrade -yqq

# First Let's verify hostname
ensure_packages nano vim quota

# Update the hostname file and apply it via hostnamectl when available.
update_hostname() {
	local new_host="$1"
	if [[ -z "$new_host" ]]; then
		return
	fi

	if hostnamectl >/dev/null 2>&1; then
		hostnamectl set-hostname "$new_host" >/dev/null 2>&1 &&
			echo "## Hostname set via hostnamectl"
	fi
	echo "$new_host" >/etc/hostname
	echo "## /etc/hostname updated"
}

if [[ -n "$hostname_override" ]]; then
	update_hostname "$hostname_override"
elif [[ "$skip_hostname_edit" == true ]]; then
	echo "## Skipping hostname confirmation as requested"
else
	echo "## Review hostname (press Ctrl+X to exit nano)"
	nano /etc/hostname
fi

# Setup fstab for quota and /home array
echo "#### Setting up quota"
echo "You need to add ' usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1 ' to the device for which you want quota"
echo "
proc            /proc           proc    defaults,hidepid=2        0       0

# You need to add to the wanted device(s):
#usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1
" >>/etc/fstab

quota_options="usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1"

# Attach quota options to a specific mount while keeping /etc/fstab intact.
ensure_quota_options() {
	local mount_point="$1"
	local opts="$2"

	if [[ -z "$mount_point" ]]; then
		return 1
	fi

	local tmpfile
	tmpfile=$(mktemp)

	if ! awk -v mp="$mount_point" -v quota_opts="$opts" '
        BEGIN { updated = 0 }
        /^[ \t]*#/ { print; next }
        NF < 2 { print; next }
        $2 == mp {
            split($4, current, ",")
            present = 0
            for (i in current) {
                if (current[i] == quota_opts) {
                    present = 1
                    break
                }
            }
            if (!present) {
                if ($4 == "-" || $4 == "defaults") {
                    $4 = quota_opts
                } else {
                    $4 = $4","quota_opts
                }
                updated = 1
            } else {
                updated = 2
            }
        }
        { print }
        END {
            if (updated == 0) {
                exit 2
            }
        }
    ' /etc/fstab >"$tmpfile"; then
		local rc=$?
		rm -f "$tmpfile"
		if [[ $rc -eq 2 ]]; then
			echo "## Quota mount $mount_point not found in /etc/fstab"
			return 2
		fi
		echo "## Failed to adjust /etc/fstab for $mount_point"
		return $rc
	fi

	if mv "$tmpfile" /etc/fstab; then
		if grep -Eq "^[[:space:]]*[^#]+[[:space:]]+$mount_point[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]*${opts}" /etc/fstab; then
			echo "## Quota options confirmed for $mount_point"
		else
			echo "## Unable to confirm quota options for $mount_point"
		fi
	else
		rm -f "$tmpfile"
		echo "## Failed to move updated /etc/fstab back"
		return 1
	fi
}

if [[ -n "$quota_mountpoint" ]]; then
	ensure_quota_options "$quota_mountpoint" "$quota_options" || true
elif [[ "$skip_quota_edit" == true ]]; then
	echo "## Skipping quota configuration as requested"
else
	echo "## Review /etc/fstab quota options (Ctrl+X to exit nano)"
	nano /etc/fstab
fi

mount -o remount /home

## #TODO: Update to dselections / dpkg set sel, automate
# TODO this whole follow up section is yucky
# Package selections, and remove some defaults etc.

#apt-get remove samba-common exim4-base exim4 netcat netcat-traditonal netcat6 -yq

ensure_packages libncurses5-dev less
ensure_packages libxmlrpc-c++4-dev libxmlrpc-c++4 #Not found on deb8
ensure_packages libxmlrpc-c++8v5
ensure_packages automake autogen build-essential libwww-curl-perl libcurl4-openssl-dev libsigc++-2.0-dev libwww-perl sysfsutils libcppunit-dev
ensure_packages gcc g++ gettext glib-networking libglib2.0-dev libfuse-dev apt-transport-https

ensure_packages php php-cli php-geoip php-gd php-apcu php-cgi php-sqlite3 php-common php-xmlrpc php-curl

ensure_packages psmisc rsync subversion mktorrent
#apt-get -t testing install mktorrent rsync -y

ensure_packages libncurses5 libncurses5-dev links elinks lynx sudo
ensure_packages pkg-config make openssl

#From update-step2 l:72, remove them from there circa 03/2016
ensure_packages znc znc-perl znc-python znc-tcl
ensure_packages gcc g++ gettext python-cheetah curl fuse glib-networking libglib2.0-dev libfuse-dev apt-transport-https #Everythin installed already on deb8
ensure_packages links elinks lynx ethtool p7zip-full smartmontools                                                      #all but smart + p7zip already installed...
ensure_packages flac lame lame-doc mp3diags lftp
#Following are for autodl-irssi
ensure_packages libarchive-zip-perl libnet-ssleay-perl libhtml-parser-perl libxml-libxml-perl libjson-perl libjson-xs-perl libxml-libxslt-perl

ensure_packages libssl-dev libssl1.1 mediainfo libmediainfo0v5 ##TODO Yuck distro version dependant

ensure_packages git
ensure_packages php-xml php8.2-cgi php8.2-cli php8.2-readline php8.2-opcache php8.2-common
ensure_packages iptables curl libssl-dev python3-pip cgroup-tools libtool
touch /usr/share/pyload ## XXX: This is a hack to disable pyload-cli install

# Script installs from release by default and uses a specific git branch as the source if given string of "git/branch" format
echo "### Setting up software"
mkdir ~/compile
cd /tmp || exit
rm -rf PMSS*
echo
parse_version_string "$SOURCE_SPEC"

if [ "$type" = "git" ]; then
	git clone "$repository" PMSS
	(
		cd PMSS || exit
		git checkout "$branch"
	)
	rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /
	rm -rf PMSS
	SOURCE="$type/$repository:$branch"
	VERSION=$(date)
else
	VERSION=$(wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tag_name/ {print $(NF-1)}')
	wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/${VERSION}" -O PMSS.tar.gz
	mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1
	rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /
	rm -rf PMSS
	SOURCE="release"
fi

mkdir -p /etc/seedbox/config/
echo "$SOURCE $VERSION" >/etc/seedbox/config/version

#TODO Transfer over the proper rc.local + sysctl configs
echo "### Setting up HDD schedulers"
echo "
#Pulsed Media Config
block/sda/queue/scheduler = bfq
block/sdb/queue/scheduler = bfq
block/sdc/queue/scheduler = bfq
block/sdd/queue/scheduler = bfq
block/sde/queue/scheduler = bfq
block/sdf/queue/scheduler = bfq

block/sda/queue/read_ahead_kb = 1024
block/sdb/queue/read_ahead_kb = 1024
block/sdc/queue/read_ahead_kb = 1024
block/sdd/queue/read_ahead_kb = 1024
block/sde/queue/read_ahead_kb = 1024
block/sdf/queue/read_ahead_kb = 1024

net.ipv4.ip_forward = 1
" >>/etc/sysctl.d/1-pmss-defaults.conf

#TODO Transfer over the proper root .bashrc
echo "### Setting up basic shell env"
echo "alias ls='ls --color=auto'" >>/root/.bashrc
echo "PATH=\$PATH:/scripts" >>/root/.bashrc

echo "### setting /home permissions"
chmod o-rw /home

echo "### Daemon configurations, quota checkup"
/scripts/update.php "$1"
/scripts/util/setupRootCron.php
/scripts/util/setupSkelPermissions.php
/scripts/util/quotaFix.php
/scripts/util/ftpConfig.php

#TODO Ancient API, but it may yet prove to be useful so not quite yet removed -AU
#/scripts/util/setupApiKey.php
