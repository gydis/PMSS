# PMSS Installer Notes

The legacy `install.sh` script remains intentionally minimal:

1. Ensure core tooling (`bash`, `php` CLI, `git`, `curl`, `wget`, `ca-certificates`, `rsync`).
2. Pull the repository into `/scripts`, `/etc`, and `/var`.
3. Hand off to `/scripts/update.php` with any operator-supplied arguments.

The script has been stable in production for over a decade—avoid adding new logic or altering the workflow without explicit coordination.
