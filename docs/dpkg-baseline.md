# Capturing a New DPKG Baseline

When onboarding a new distro (e.g. Debian 13, Ubuntu derivatives), follow this
process to generate the immutable `scripts/lib/update/dpkg/selections-<distro>.txt`
manifest.

1. **Provision a clean host** with the target OS and run the current PMSS
   updater (`install.sh` + `/scripts/update.php git/main`). Make sure the run
   completes without package queue warnings.
2. **Refresh package metadata** and clean strays:
   ```bash
   apt-get update
   apt-get -y autoremove
   apt-get -y --fix-broken install
   ```
3. **Export the selections** and strip `deinstall` rows:
   ```bash
   dpkg --get-selections \
     | awk '$2 == "install" { print $1 }' \
     | sort -u > /tmp/selections-new.txt
   ```
4. **Review the list** for transitional/meta packages (e.g. `proftpd-basic`).
   Replace them with the real package names before committing.
5. **Verify availability** by replaying the list on a staging host:
   ```bash
   dpkg --set-selections < /tmp/selections-new.txt
   apt-get dselect-upgrade -y
   ```
   The command must complete without missing-package warnings.
6. **Commit under `scripts/lib/update/dpkg/`** using the naming scheme
   `selections-debianXX.txt` (or similar) and update `AGENTS.md` if the support
   matrix changes.

> **Always regenerate from a live host.** Never hand-edit the manifests: capture
> a new list, keep it sorted, and land it with platform sign-off.
