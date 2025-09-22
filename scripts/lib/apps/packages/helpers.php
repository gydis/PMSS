<?php
/**
 * Helper utilities for package installation routines.
 */

putenv('DEBIAN_FRONTEND=noninteractive');
putenv('APT_LISTCHANGES_FRONTEND=none');

/**
 * Return the dpkg status string for a package or an empty string when missing.
 */
function pmssPackageStatus(string $package): string
{
    $cmd = 'dpkg-query -W -f=\'${Status}\' '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    return $rc === 0 && isset($output[0]) ? trim($output[0]) : '';
}

/**
 * True when a package exists but is not fully configured.
 */
function pmssPackagesNeedCleanup(array $packages): bool
{
    foreach ($packages as $pkg) {
        $status = pmssPackageStatus($pkg);
        if ($status !== '' && $status !== 'install ok installed') {
            return true;
        }
    }
    return false;
}

/**
 * Confirm that every package is cleanly installed.
 */
function pmssPackagesInstalled(array $packages): bool
{
    foreach ($packages as $pkg) {
        if (pmssPackageStatus($pkg) !== 'install ok installed') {
            return false;
        }
    }
    return true;
}

/**
 * Determine if a package is available in the current apt cache.
 */
function pmssPackageAvailable(string $package): bool
{
    static $cache = [];
    if (array_key_exists($package, $cache)) {
        return $cache[$package];
    }
    $cmd = 'apt-cache policy '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    if ($rc !== 0 || empty($output)) {
        return $cache[$package] = false;
    }
    foreach ($output as $line) {
        if (stripos($line, 'Candidate:') !== false) {
            return $cache[$package] = (stripos($line, '(none)') === false);
        }
    }
    return $cache[$package] = true;
}

/**
 * Install packages, allowing each entry to specify fallback candidates.
 */
function pmssInstallBestEffort(array $items, string $label = ''): void
{
    $selection = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            foreach ($item as $candidate) {
                if (pmssPackageAvailable($candidate)) {
                    $selection[] = $candidate;
                    break;
                }
            }
        } elseif (pmssPackageAvailable($item)) {
            $selection[] = $item;
        }
    }
    $selection = array_values(array_unique($selection));
    if (empty($selection)) {
        if ($label !== '') {
            echo "Notice: No packages available for {$label}\n";
        }
        return;
    }
    passthru('apt-get install -y '.implode(' ', $selection));
}

/**
 * Install the ProFTPD stack with recovery for half-installed states.
 */
function pmssInstallProftpdStack(int $distroVersion): void
{
    $proftpdPackages = ['proftpd-core', 'proftpd-basic', 'proftpd-mod-crypto', 'proftpd-mod-wrap'];

    if ($distroVersion >= 10) {
        if (pmssPackagesNeedCleanup($proftpdPackages)) {
            passthru('apt-get remove -y proftpd-core proftpd-basic proftpd-mod-crypto proftpd-mod-wrap');
        }

        $installCommand = 'apt-get install -y '.implode(' ', $proftpdPackages);
        $installRc = 0;
        passthru($installCommand, $installRc);
        if ($installRc !== 0 && !pmssPackagesInstalled($proftpdPackages)) {
            echo "Warning: ProFTPD packages failed to configure, attempting recovery\n";
            passthru('apt-get -f install -y');
            passthru('dpkg --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic');
        }

        if (!pmssPackagesInstalled($proftpdPackages)) {
            echo "Warning: ProFTPD packages remain unconfigured; proceeding without FTP daemon\n";
        }

        passthru('apt-get install nftables -y;');
    } else {
        passthru('apt-get install proftpd-basic -y');
    }
}

/**
 * Resolve the correct backports suite for the given Debian major version.
 */
function pmssBackportSuite(int $distroVersion): ?string
{
    switch ($distroVersion) {
        case 10:
            return 'buster-backports';
        case 11:
            return 'bullseye-backports';
        case 12:
            return 'bookworm-backports';
        default:
            return null;
    }
}
