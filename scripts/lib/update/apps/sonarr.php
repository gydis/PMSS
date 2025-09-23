<?php
/**
 * Sonarr installer/maintainer.
 *
 * Downloads the newest Sonarr linux build from GitHub and installs it under
 * /opt/Sonarr. This replaces the fragile apt repository flow so Debian upgrades
 * stay dependable.
 */

require_once dirname(__DIR__, 2).'/runtime.php';

const SONARR_VERSION_RECORD = '/etc/seedbox/config/app-versions/sonarr';
const SONARR_INSTALL_PATH   = '/opt/Sonarr';
const SONARR_RELEASES_URL   = 'https://api.github.com/repos/Sonarr/Sonarr/releases';
const SONARR_LEGACY_REPO    = '/etc/apt/sources.list.d/sonarr.list';

// Remove legacy repo fragments to avoid apt warnings during upgrades.
if (file_exists(SONARR_LEGACY_REPO)) {
    @unlink(SONARR_LEGACY_REPO);
}
@passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null');

$asset = pmssSonarrResolveAsset();
if ($asset === null) {
    logMessage('Sonarr: Unable to resolve release asset, leaving install untouched');
    return;
}

[$latestVersion, $downloadUrl, $assetName] = $asset;
$currentVersion = trim((string)@file_get_contents(SONARR_VERSION_RECORD));
if ($currentVersion === $latestVersion && is_dir(SONARR_INSTALL_PATH)) {
    logMessage("Sonarr: Already at {$latestVersion}, skipping update");
    return;
}

$workDir = sys_get_temp_dir().'/sonarr-'.bin2hex(random_bytes(4));
if (!@mkdir($workDir, 0755, true)) {
    logMessage('Sonarr: Failed to create temporary workspace');
    return;
}

$archivePath = $workDir.'/'.$assetName;
$downloadCmd = sprintf('curl -sSL --fail -o %s %s', escapeshellarg($archivePath), escapeshellarg($downloadUrl));
if (runCommand($downloadCmd) !== 0 || !is_file($archivePath)) {
    logMessage('Sonarr: Download failed; keeping existing installation');
    runCommand('rm -rf '.escapeshellarg($workDir));
    return;
}

$extractCmd = sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir));
if (runCommand($extractCmd) !== 0 || !is_dir($workDir.'/Sonarr')) {
    logMessage('Sonarr: Extraction failed; keeping existing installation');
    runCommand('rm -rf '.escapeshellarg($workDir));
    return;
}

runCommand('rm -rf '.escapeshellarg(SONARR_INSTALL_PATH));
runCommand(sprintf('mv %s %s', escapeshellarg($workDir.'/Sonarr'), escapeshellarg(SONARR_INSTALL_PATH)));
runCommand('rm -rf '.escapeshellarg($workDir));

pmssSonarrPersistVersion($latestVersion);
logMessage("Sonarr: Installed version {$latestVersion}");

/**
 * Identify the newest linux release asset from GitHub.
 */
function pmssSonarrResolveAsset(): ?array
{
    $context = stream_context_create([
        'http' => [
            'header' => [
                'Accept: application/vnd.github+json',
                'User-Agent: PMSS-Sonarr',
            ],
            'timeout' => 15,
        ],
    ]);

    $payload = @file_get_contents(SONARR_RELEASES_URL, false, $context);
    if ($payload === false) {
        return null;
    }

    $releases = json_decode($payload, true);
    if (!is_array($releases)) {
        return null;
    }

    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            continue;
        }
        foreach ($release['assets'] as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (!preg_match('/Sonarr\.(?:main|develop)\.([0-9.]+).*linux.*tar\.gz/i', $name, $match)) {
                continue;
            }
            $version = $match[1];
            $url = (string)($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            return [$version, $url, $name];
        }
    }

    return null;
}

/**
 * Persist version metadata for future runs.
 */
function pmssSonarrPersistVersion(string $version): void
{
    $dir = dirname(SONARR_VERSION_RECORD);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true); // #TODO share with Radarr persist helper to avoid duplication
    }
    @file_put_contents(SONARR_VERSION_RECORD, $version.PHP_EOL);
}
