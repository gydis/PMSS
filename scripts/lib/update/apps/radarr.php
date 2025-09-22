<?php
/**
 * Radarr installer/maintainer.
 *
 * Downloads the latest available linux build when a newer version than the
 * recorded one is published. Skips reinstalling if the existing installation
 * already matches.
 */

require_once dirname(__DIR__).'/runtime.php';

const RADARR_VERSION_RECORD = '/etc/seedbox/config/app-versions/radarr';
const RADARR_INSTALL_PATH   = '/opt/Radarr';
const RADARR_RELEASES_URL   = 'https://api.github.com/repos/Radarr/Radarr/releases';

/**
 * Emit a concise status line so updater logs stay readable.
 */
function radarrLog(string $message): void
{
    logMessage('Radarr: '.$message);
}

/**
 * Download release metadata from GitHub and select the first linux archive.
 */
function radarrResolveLatestAsset(): ?array
{
    $options = [
        'http' => [
            'header' => [
                'Accept: application/vnd.github+json',
                'User-Agent: PMSS-Radarr'
            ],
            'timeout' => 15,
        ],
    ];
    $context = stream_context_create($options);
    $payload = @file_get_contents(RADARR_RELEASES_URL, false, $context);
    if ($payload === false) {
        radarrLog('Unable to fetch release metadata (network issue?)');
        return null;
    }
    $releases = json_decode($payload, true);
    if (!is_array($releases)) {
        radarrLog('Invalid release metadata payload');
        return null;
    }

    foreach ($releases as $release) {
        if (empty($release['assets']) || !is_array($release['assets'])) {
            continue;
        }
        foreach ($release['assets'] as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (!preg_match('/Radarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i', $name, $match)) {
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

    radarrLog('No suitable linux release asset found');
    return null;
}

/**
 * Record the installed version for future comparisons.
 */
function radarrPersistVersion(string $version): void
{
    $dir = dirname(RADARR_VERSION_RECORD);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents(RADARR_VERSION_RECORD, $version.PHP_EOL);
}

$asset = radarrResolveLatestAsset();
if ($asset === null) {
    return;    // graceful exit; existing install remains untouched
}
[$latestVersion, $downloadUrl, $assetName] = $asset;

$currentVersion = trim((string)@file_get_contents(RADARR_VERSION_RECORD));
if ($currentVersion === $latestVersion && is_dir(RADARR_INSTALL_PATH)) {
    radarrLog("Already at {$latestVersion}, skipping update");
    return;
}

$workDir = sys_get_temp_dir().'/radarr-'.bin2hex(random_bytes(4));
if (!@mkdir($workDir, 0755, true)) {
    radarrLog('Failed to create temporary workspace');
    return;
}

$archivePath = $workDir.'/'.$assetName;
$curlCmd = sprintf('curl -sSL --fail -o %s %s', escapeshellarg($archivePath), escapeshellarg($downloadUrl));
if (runCommand($curlCmd) !== 0 || !is_file($archivePath)) {
    radarrLog('Download failed; keeping existing installation');
    runCommand('rm -rf '.escapeshellarg($workDir));
    return;
}

$extractCmd = sprintf('tar -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir));
if (runCommand($extractCmd) !== 0 || !is_dir($workDir.'/Radarr')) {
    radarrLog('Extraction failed; keeping existing installation');
    runCommand('rm -rf '.escapeshellarg($workDir));
    return;
}

runCommand('rm -rf '.escapeshellarg(RADARR_INSTALL_PATH));
runCommand(sprintf('mv %s %s', escapeshellarg($workDir.'/Radarr'), escapeshellarg(RADARR_INSTALL_PATH)));
runCommand('rm -rf '.escapeshellarg($workDir));

radarrPersistVersion($latestVersion);
radarrLog("Installed version {$latestVersion}");
