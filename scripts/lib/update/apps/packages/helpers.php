<?php
/**
 * Helper utilities for package installation routines.
 */

require_once __DIR__.'/../../runtime/commands.php';

putenv('DEBIAN_FRONTEND=noninteractive');
putenv('APT_LISTCHANGES_FRONTEND=none');

const PMSS_PACKAGE_QUEUE_DEFAULT = '__default__';

if (!isset($GLOBALS['PMSS_PACKAGE_QUEUE'])) {
    $GLOBALS['PMSS_PACKAGE_QUEUE'] = [];
}

if (!isset($GLOBALS['PMSS_POST_INSTALL_COMMANDS'])) {
    $GLOBALS['PMSS_POST_INSTALL_COMMANDS'] = [];
}

function pmssQueuePackages(array $packages, ?string $target = null): void
{
    global $PMSS_PACKAGE_QUEUE;
    $key = $target ?? PMSS_PACKAGE_QUEUE_DEFAULT;
    if (!isset($PMSS_PACKAGE_QUEUE[$key])) {
        $PMSS_PACKAGE_QUEUE[$key] = [];
    }
    foreach ($packages as $pkg) {
        $pkg = trim($pkg);
        if ($pkg !== '') {
            $PMSS_PACKAGE_QUEUE[$key][] = $pkg;
        }
    }
}

function pmssQueuePackage(string $package, ?string $target = null): void
{
    pmssQueuePackages([$package], $target);
}

function pmssFlushPackageQueue(): void
{
    global $PMSS_PACKAGE_QUEUE;
    global $PMSS_POST_INSTALL_COMMANDS;
    if (empty($PMSS_PACKAGE_QUEUE)) {
        return;
    }

    foreach ($PMSS_PACKAGE_QUEUE as $target => $packages) {
        $packages = array_values(array_unique(array_filter($packages)));
        if (empty($packages)) {
            continue;
        }

        [$installable, $missing] = pmssFilterAvailablePackages($packages);
        if (!empty($missing)) {
            pmssLogPackageNotice('Skipping unavailable packages: '.implode(', ', $missing));
        }
        if (empty($installable)) {
            continue;
        }

        $pkgArgs = implode(' ', array_map('escapeshellarg', $installable));
        if ($target === PMSS_PACKAGE_QUEUE_DEFAULT) {
            $cmd = 'apt-get install -y '.$pkgArgs;
            $label = 'Installing packages';
        } else {
            $cmd = sprintf('apt-get install -y -t %s %s', escapeshellarg($target), $pkgArgs);
            $label = 'Installing packages ('.$target.')';
        }

        $rc = runStep($label, $cmd);
        if ($rc !== 0) {
            $context = $target === PMSS_PACKAGE_QUEUE_DEFAULT ? 'package queue' : 'package queue '.$target;
            runStep('Attempting apt fix-broken install ('.$context.')', 'apt-get --fix-broken install -y');
            runStep($label.' retry', $cmd);
        }
    }

    $PMSS_PACKAGE_QUEUE = [];

    if (!empty($PMSS_POST_INSTALL_COMMANDS)) {
        foreach ($PMSS_POST_INSTALL_COMMANDS as [$description, $command]) {
            runStep($description, $command);
        }
        $PMSS_POST_INSTALL_COMMANDS = [];
    }
}

function pmssQueuePostInstallCommand(string $description, string $command): void
{
    global $PMSS_POST_INSTALL_COMMANDS;
    $PMSS_POST_INSTALL_COMMANDS[] = [$description, $command];
}

function pmssLogPackageNotice(string $message): void
{
    if (function_exists('logmsg')) {
        logmsg($message);
    } else {
        echo $message."\n";
    }
}

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
    pmssQueuePackages($selection);
}

/**
 * Filter a package list into installable vs. missing entries.
 */
function pmssFilterAvailablePackages(array $packages): array
{
    $installable = [];
    $missing     = [];

    foreach ($packages as $pkg) {
        if ($pkg === '') {
            continue;
        }
        if (pmssPackageAvailable($pkg)) {
            $installable[] = $pkg;
        } else {
            $missing[] = $pkg;
        }
    }

    return [$installable, $missing];
}

/**
 * Install the ProFTPD stack with recovery for half-installed states.
 */
function pmssInstallProftpdStack(int $distroVersion): void
{
    $proftpdPackages = ['proftpd-core', 'proftpd-basic', 'proftpd-mod-crypto', 'proftpd-mod-wrap'];

    if ($distroVersion >= 10) {
        if (is_dir('/run/systemd/system')) {
            runStep('Ensuring proftpd unit is not masked', 'systemctl unmask proftpd || true');
        }
        pmssQueuePackages(array_merge($proftpdPackages, ['nftables']));
    } else {
        pmssQueuePackage('proftpd-basic');
    }

    pmssQueuePostInstallCommand(
        'Reconfiguring proftpd packages',
        'dpkg --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic || true'
    );
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
