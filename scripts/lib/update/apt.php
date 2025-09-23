<?php
/**
 * Helpers for managing APT sources during updates.
 */

require_once __DIR__.'/logging.php';

/**
 * Return the target path for the primary apt sources file (testable override).
 */
function pmssAptSourcesPath(): string
{
    $override = getenv('PMSS_APT_SOURCES_PATH');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return '/etc/apt/sources.list';
}

/**
 * Load an APT sources template from the config directory.
 */
function pmssLoadRepoTemplate(string $codename, ?callable $logger = null): string
{
    $log = pmssSelectLogger($logger);
    $path = "/etc/seedbox/config/template.sources.$codename";

    if (!file_exists($path)) {
        $log("Repository template missing: $path");
        return '';
    }

    $data = trim((string)@file_get_contents($path));
    if ($data === '') {
        $log("Repository template empty: $path");
        return '';
    }

    return $data."\n";
}

/**
 * Safely write /etc/apt/sources.list with a backup in case of failure.
 */
function pmssSafeWriteSources(string $content, string $label, ?callable $logger = null): bool
{
    $log = pmssSelectLogger($logger);
    $target = pmssAptSourcesPath();
    $backup = $target.'.pmss-backup';

    if ($content === '') {
        $log("[WARN] Empty repository content for $label, skipping");
        return false;
    }

    $current = @file_get_contents($target);
    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if ($current !== false && @file_put_contents($backup, $current, LOCK_EX) === false) {
        $log("[WARN] Unable to create backup $backup before updating $label");
    } elseif ($current !== false) {
        $log("Backup for sources.list written to $backup");
    }

    if (@file_put_contents($target, $content, LOCK_EX) === false) {
        $log("[ERROR] Failed to write sources.list for $label, attempting restore");
        if ($current !== false) {
            @file_put_contents($target, $current, LOCK_EX);
        }
        return false;
    }

    return true;
}

/**
 * Ensure /etc/apt/sources.list matches the recommended repository layout.
 */
function pmssUpdateAptSources(string $distroName, int $distroVersion, string $currentHash,
    array $repos, ?callable $logger = null): void
{
    $log = pmssSelectLogger($logger);

    switch ($distroName) {
        case 'debian':
            pmssUpdateAptSourcesDebian($distroVersion, $currentHash, $repos, $log);
            return;
        case 'ubuntu':
            $log('Ubuntu is not supported yet.');
            return;
        default:
            $log("Unsupported distro: $distroName");
            return;
    }
}

/**
 * Handle Debian release specific updates.
 */
function pmssUpdateAptSourcesDebian(int $version, string $currentHash, array $repos, callable $log): void
{
    switch ($version) {
        case 8:
            pmssApplyAptTemplate('Jessie', $repos['jessie'] ?? '', $currentHash, $log, function () {
                passthru("echo 'Acquire::Check-Valid-Until \"false\";' >/etc/apt/apt.conf.d/90ignore-release-date");
                passthru('apt-get clean;');
            });
            return;
        case 10:
            pmssApplyAptTemplate('Buster', $repos['buster'] ?? '', $currentHash, $log);
            return;
        case 11:
            pmssApplyAptTemplate('Bullseye', $repos['bullseye'] ?? '', $currentHash, $log);
            return;
        case 12:
            pmssApplyAptTemplate('Bookworm', $repos['bookworm'] ?? '', $currentHash, $log);
            return;
        case 13:
            pmssApplyAptTemplate('Trixie', $repos['trixie'] ?? '', $currentHash, $log);
            return;
        default:
            $log("Unsupported Debian version: $version");
            return;
    }
}

/**
 * Shared routine that compares hash, writes the template, and executes optional callbacks.
 */
function pmssApplyAptTemplate(string $label, string $template, string $currentHash, callable $log, ?callable $post = null): void
{
    if ($template === '') {
        $log("{$label} template missing, leaving sources.list untouched");
        return;
    }
    $hash = sha1($template);
    if ($currentHash !== $hash && pmssSafeWriteSources($template, $label, $log)) {
        if ($post) {
            $post();
        }
        $log("Applied Debian {$label} repository config");
    } else {
        $log("Debian {$label} repositories already correct");
    }
}
