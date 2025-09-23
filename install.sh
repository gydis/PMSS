#!/bin/bash
# PMSS bootstrap installer.
#
# - Installs minimal prerequisites on a fresh Debian host (PHP CLI, unzip, vim, git, curl, wget, ca-certificates, rsync).
# - Downloads or clones the requested PMSS snapshot and hands off to
#   update.php with any additional arguments provided.
# - Performs initial hostname/quota prompts to keep the legacy workflow intact.
#
# This script has been the entry point for well over a decade—treat it gently.
# Only adjust behaviour when absolutely necessary, and coordinate changes with
# the platform team.
#
# Author: Aleksi Ursin <aleksi@magnacapax.fi>
# Copyright 2010-2025 Magna Capax Finland Oy

DEFAULT_REPOSITORY="https://github.com/MagnaCapax/PMSS"
date=
type=
url=
repository=
branch=

# Simple colour-aware logging helpers.
if [ -t 1 ]; then
    COLOR_BLUE="$(tput setaf 4)"
    COLOR_GREEN="$(tput setaf 2)"
    COLOR_YELLOW="$(tput setaf 3)"
    COLOR_RED="$(tput setaf 1)"
    COLOR_RESET="$(tput sgr0)"
else
    COLOR_BLUE=""
    COLOR_GREEN=""
    COLOR_YELLOW=""
    COLOR_RED=""
    COLOR_RESET=""
fi

log_step() { echo -e "${COLOR_BLUE}==>${COLOR_RESET} $*"; }
log_info() { echo -e "${COLOR_GREEN}-->${COLOR_RESET} $*"; }
log_warn() { echo -e "${COLOR_YELLOW}WARN${COLOR_RESET} $*"; }
log_error() { echo -e "${COLOR_RED}ERR ${COLOR_RESET} $*"; }

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
		log_info "Usage: bash install.sh [update-source] [options...]"
		log_info "  --hostname=<name>      set system hostname non-interactively"
		log_info "  --skip-hostname        skip hostname confirmation"
		log_info "  --quota-mount=<path>   add quota options to specified fstab mount"
		log_info "  --skip-quota           skip quota guidance section"
		exit 0
		;;
	--*)
		log_error "Unknown option: $1"
		exit 1
		;;
	*)
		POSITIONAL+=("$1")
		shift
		;;
	esac
done

set -- "${POSITIONAL[@]}"

if [ $# -gt 0 ]; then
    SOURCE_SPEC="$1"
    shift
else
    SOURCE_SPEC=""
fi
if [ -n "$SOURCE_SPEC" ]; then
    UPDATE_ARGS=("$SOURCE_SPEC" "$@")
else
    UPDATE_ARGS=("$@")
fi

parse_version_string() {
	local input_string="$1"

	if [[ $input_string =~ (^git|^release)\/(.*)[:]?([0-9]{4}-[0-9]{2}-[0-9]{2}([ ]?[0-9]{2}[:][0-9]{2})?)$ ]]; then
		type="${BASH_REMATCH[1]}"
		url="${BASH_REMATCH[2]}"
		date="${BASH_REMATCH[3]}"
		log_info "Spec type: $type"
		log_info "Spec URL: $url"
		log_info "Spec date: $date"
		if [[ $url =~ (.*[^:])[:](.*[^:])[:]?$ ]]; then
			repository="${BASH_REMATCH[1]}"
			branch="${BASH_REMATCH[2]}"
			log_info "Repository: $repository"
			log_info "Branch: $branch"

		elif [[ $url =~ (^main)[:]$ ]]; then
			repository=$DEFAULT_REPOSITORY
			branch="${BASH_REMATCH[1]}"
			log_info "Repository: $repository"
			log_info "Branch: $branch"
		else
			log_warn "Spec URL didn't match expected format, using defaults"
			repository=$DEFAULT_REPOSITORY
			branch="main"
		fi
	else
		log_warn "Invalid version spec, using defaults"
	fi
}

# Install packages only if missing to avoid accidental removals
# Wrapper predates the new package pipeline; keep it lean.
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
	log_warn "Package $pkg not found in repositories, skipping"
		fi
	done

	if [ ${#missing[@]} -eq 0 ]; then
		return
	fi

log_step "Installing missing packages: ${missing[*]}"
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
log_step "Updating package lists"
apt update
# Perform the full-upgrade
log_step "Running apt full-upgrade"
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
			log_info "Hostname set via hostnamectl"
	fi
	echo "$new_host" >/etc/hostname
	log_info "/etc/hostname updated"
}

if [[ -n "$hostname_override" ]]; then
	update_hostname "$hostname_override"
elif [[ "$skip_hostname_edit" == true ]]; then
	log_info "Skipping hostname confirmation"
else
	log_step "Review hostname (press Ctrl+X to exit nano)"
	nano /etc/hostname
fi

# Setup fstab for quota and /home array
log_step "Rechecking kernel quota support (legacy helper)"
log_warn "Remember to add usrjquota/grpjquota options to the quota mount (manual step)"
echo "
proc            /proc           proc    defaults,hidepid=2        0       0

# You need to add to the wanted device(s):
#usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1
" >>/etc/fstab

quota_options="usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1"

# Attach quota options to a specific mount while keeping /etc/fstab intact.
# Legacy helper to append quota options; kept for compatibility.
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
			log_warn "Quota mount $mount_point not found in /etc/fstab"
			return 2
		fi
		log_warn "Failed to adjust /etc/fstab for $mount_point"
		return $rc
	fi

	if mv "$tmpfile" /etc/fstab; then
		if grep -Eq "^[[:space:]]*[^#]+[[:space:]]+$mount_point[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]*${opts}" /etc/fstab; then
			log_info "Quota options confirmed for $mount_point"
		else
			log_warn "Unable to confirm quota options for $mount_point"
		fi
	else
		rm -f "$tmpfile"
		log_warn "Failed to move updated /etc/fstab back"
		return 1
	fi
}

if [[ -n "$quota_mountpoint" ]]; then
	ensure_quota_options "$quota_mountpoint" "$quota_options" || true
elif [[ "$skip_quota_edit" == true ]]; then
	log_info "Skipping quota configuration as requested"
else
	log_step "Review /etc/fstab quota options (Ctrl+X to exit nano)"
	nano /etc/fstab
fi

mount -o remount /home

## #TODO: Update to dselections / dpkg set sel, automate
# TODO this whole follow up section is yucky
# Package selections, and remove some defaults etc.

#apt-get remove samba-common exim4-base exim4 netcat netcat-traditonal netcat6 -yq

# Minimal prerequisites; remaining packages arrive via update-step2/pmssApplyDpkgSelections.
ensure_packages git rsync curl wget ca-certificates unzip php php-cli php-xml zip unzip vim tzdata

# Script installs from release by default and uses a specific git branch as the source if given string of "git/branch" format
log_step "Setting up base software"
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
log_step "Deploying legacy BFQ/sysctl tuning (ensure rc.local unchanged)"
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
log_step "Configuring root shell defaults"
echo "alias ls='ls --color=auto'" >>/root/.bashrc
echo "PATH=\$PATH:/scripts" >>/root/.bashrc

log_step "Adjusting /home permissions"
chmod o-rw /home

log_step "Finalising daemon configuration"
/scripts/update.php "${UPDATE_ARGS[@]}"
/scripts/util/setupRootCron.php
/scripts/util/setupSkelPermissions.php
/scripts/util/quotaFix.php
/scripts/util/ftpConfig.php

#TODO Ancient API, but it may yet prove to be useful so not quite yet removed -AU
#/scripts/util/setupApiKey.php
