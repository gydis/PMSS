<?php
/**
 * Library for PMSS Updates
 * /scripts/lib/update.php
 *
 * Contains various functions, settings, etc. for use in /scripts/util/update-step2.php.
 */

// rTorrent class required
require_once '/scripts/lib/rtorrentConfig.php';

// Global variables
$rtorrentConfig = new rtorrentConfig();
$users          = shell_exec('/scripts/listUsers.php');
$users          = explode("\n", trim($users));
$distroName     = getDistroName();          // Returns the distribution ID (e.g. "debian", "ubuntu")
$distroVersion  = getDistroVersion();       // Returns the distribution version number (numeric part)
$serverHostname = trim(file_get_contents('/etc/hostname')); // Hostname of the server as set in /etc/hostname
$lsbrelease     = trim(shell_exec('/usr/bin/lsb_release -cs'));  // LSB Release codename; may be the best selector for packages

/**
 * Update a user's file from /etc/skel.
 *
 * This function copies a source file from /etc/skel to a user's home directory
 * if it doesn't exist there, or updates it if the contents differ.
 *
 * @param string $file The filename relative to /etc/skel and the user's home.
 * @param string $user The username whose file should be updated.
 *
 * @return void
 */
function updateUserFile($file, $user) {
    if (empty($file) || empty($user) || !file_exists("/home/{$user}")) {
        echo "Invalid parameters, file: {$file} user: {$user}\n";
        return;
    }
    
    $sourceFile = '/etc/skel/' . $file;
    $targetFile = "/home/{$user}/" . $file;
        
    if (!file_exists($sourceFile)) {
        echo "Source file: {$file} is missing\n";
        return;
    }
    
    if (!file_exists($targetFile)) {
        copyToUserSpace($sourceFile, $targetFile, $user);
        echo "Added: {$file} for {$user}\n";
    } else {
        $sourceContent = file_get_contents($sourceFile);
        $targetContent = file_get_contents($targetFile);
        if ($sourceContent === false || $targetContent === false) {
            echo "Error reading file contents for comparison.\n";
            return;
        }
        $sourceChecksum = sha1($sourceContent);
        $targetChecksum = sha1($targetContent);
        if ($sourceChecksum !== $targetChecksum) {
            if (!unlink($targetFile)) {
                echo "Failed to remove old file: {$targetFile}\n";
                return;
            }
            copyToUserSpace($sourceFile, $targetFile, $user);
            echo "Updated: {$file} for {$user}\n";
        }
    }
}

/**
 * Copy a file to a user's home directory and adjust its permissions and ownership.
 *
 * @param string $sourceFile The source file path.
 * @param string $targetFile The target file path in the user's home directory.
 * @param string $user       The username for setting file ownership.
 *
 * @return void
 */
function copyToUserSpace($sourceFile, $targetFile, $user) {
    if (!copy($sourceFile, $targetFile)) {
        echo "Failed to copy {$sourceFile} to {$targetFile}\n";
        return;
    }
    // Set file permissions to 755.
    passthru("chmod 755 " . escapeshellarg($targetFile));
    // Change owner and group to the specified user.
    passthru("chown " . escapeshellarg($user) . ":" . escapeshellarg($user) . " " . escapeshellarg($targetFile));
}

/**
 * Update ruTorrent configuration for a given user.
 *
 * This function reads ruTorrent configuration template files,
 * replaces placeholders with user-specific paths, and writes the updated
 * configuration to the user's ruTorrent directory.
 *
 * @param string $username The username for which to update the configuration.
 * @param int    $scgiPort The SCGI port for ruTorrent configuration (currently not used).
 *
 * @return void
 */
function updateRutorrentConfig($username, $scgiPort) {
    $templateConfigPath = '/etc/seedbox/config/template.rutorrent.config';
    $templateAccessPath = '/etc/seedbox/config/template.rutorrent.access';
    
    $rutorrentConfig = file_get_contents($templateConfigPath);
    $accessIni       = file_get_contents($templateAccessPath);
    
    if ($rutorrentConfig === false || $accessIni === false) {
        echo "Failed to read ruTorrent template files.\n";
        return;
    }
    
    // Update ruTorrent configuration with user-specific values.
    $rutorrentConfig = str_replace(
        '$scgi_host = "";',
        '$scgi_host = "unix:///home/' . $username . '/.rtorrent.socket";',
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$tempDirectory = null;',
        "\$tempDirectory = '/home/{$username}/.tmp/';",
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$topDirectory = \'/\';',
        "\$topDirectory = '/home/{$username}/';",
        $rutorrentConfig
    );
    $rutorrentConfig = str_replace(
        '$log_file = \'/tmp/errors.log\';',
        "\$log_file = '/home/{$username}/www/rutorrent/errors.log';",
        $rutorrentConfig
    );
    
    $configPath = "/home/{$username}/www/rutorrent/conf/config.php";
    $accessPath = "/home/{$username}/www/rutorrent/conf/access.ini";
    
    if (file_put_contents($configPath, $rutorrentConfig) === false) {
        echo "Failed to write ruTorrent config to {$configPath}\n";
        return;
    }
    if (file_put_contents($accessPath, $accessIni) === false) {
        echo "Failed to write ruTorrent access config to {$accessPath}\n";
        return;
    }
}

/**
 * Retrieve and cache OS release data from /etc/os-release.
 *
 * @return array Parsed key-value pairs from /etc/os-release.
 */
function getOsReleaseData() {
    static $data = null;
    if ($data === null) {
        $data = parse_ini_file('/etc/os-release');
    }
    return $data;
}

/**
 * Get the distribution name from /etc/os-release.
 *
 * @return string The distribution ID (e.g., "ubuntu", "debian"), or an empty string if not found.
 */
function getDistroName() {
    $data = getOsReleaseData();
    return isset($data['ID']) ? $data['ID'] : '';
}

/**
 * Get the distribution version from /etc/os-release.
 *
 * Extracts and returns the numeric part of VERSION_ID.
 *
 * @return string The distribution version number, or an empty string if not found.
 */
function getDistroVersion() {
    $data = getOsReleaseData();
    if (isset($data['VERSION_ID'])) {
        if (preg_match('/^([0-9]+)/', $data['VERSION_ID'], $matches)) {
            return $matches[1];
        }
        return $data['VERSION_ID'];
    }
    return '';
}

/**
 * Retrieve current PMSS version from the configured version file.
 *
 * @param string $versionFile Path to the version file.
 *
 * @return string The version string or "unknown" if not found.
 */
function getPmssVersion($versionFile = '/etc/seedbox/config/version') {
    if (file_exists($versionFile) && filesize($versionFile) > 0) {
        return trim(file_get_contents($versionFile));
    }
    return 'unknown';
}

// ----- Utility helpers -----
const PMSS_LOG_FILE = '/var/log/pmss-update.log';

/** Log a message to the common update log and stdout */
function logMessage(string $m): void {
    $ts = date('[Y-m-d H:i:s] ');
    @file_put_contents(PMSS_LOG_FILE, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX);
    echo $m.PHP_EOL;
}

/** Execute a shell command with optional verbose logging */
function runCommand(string $cmd, bool $verbose = false): int {
    if ($verbose) logMessage("[CMD] $cmd");
    passthru($cmd, $rc);
    return $rc;
}

/** Ensure the current process has root privileges */
function requireRoot(): void {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "This script must be run as root.\n");
        exit(1);
    }
}

/** Generate /etc/motd using the template and system details */
function generateMotd(): void {
    $motdTemplatePath = '/etc/seedbox/config/template.motd';
    $motdOutputPath   = '/etc/motd';
    $motdTemplate     = @file_get_contents($motdTemplatePath);
    if ($motdTemplate === false) return;

    $serverHostname = trim(file_get_contents('/etc/hostname'));
    $serverIp       = gethostbyname($serverHostname);
    $cpuInfo        = trim(shell_exec("lscpu | grep 'Model name:' | sed 's/Model name:\\s*//'"));
    $ramInfo        = trim(shell_exec("free -h | awk '/^Mem:/ { print \$2 }'"));
    $storageInfo    = trim(shell_exec("df -h /home | awk 'NR==2 {print \$2}'"));

    $pmssVersion = getPmssVersion();
    if (!is_dir('/var/run/pmss')) {
        mkdir('/var/run/pmss', 0770, true);
    }
    $versionCache = '/var/run/pmss/version';
    file_put_contents($versionCache, $pmssVersion);
    $runtimeVersion = trim(@file_get_contents($versionCache));
    $updateDate = file_exists('/var/run/pmss/updated') ? trim(file_get_contents('/var/run/pmss/updated')) : 'not set';
    $aptStampFile = '/var/lib/apt/periodic/update-success-stamp';
    $aptLastUpdate = file_exists($aptStampFile) ? trim(shell_exec("stat -c '%y' ".escapeshellarg($aptStampFile))) : 'Not available';
    $uptime = trim(shell_exec('uptime -p'));
    $kernelVersion = trim(shell_exec('uname -r'));
    $netSpeedRaw = shell_exec("ethtool eth0 2>/dev/null | grep 'Speed:'");
    if ($netSpeedRaw && preg_match('/Speed:\s+(\S+)/', $netSpeedRaw, $m)) {
        $networkSpeed = $m[1];
    } else {
        $networkSpeed = 'N/A';
    }

    $replacements = [
        '%HOSTNAME%'        => $serverHostname,
        '%SERVER_IP%'       => $serverIp,
        '%SERVER_CPU%'      => $cpuInfo,
        '%SERVER_RAM%'      => $ramInfo,
        '%SERVER_STORAGE%'  => $storageInfo,
        '%PMSS_VERSION%'    => $pmssVersion,
        '%RUN_VERSION%'     => $runtimeVersion,
        '%UPDATE_DATE%'     => $updateDate,
        '%APT_LAST_UPDATE%' => $aptLastUpdate,
        '%UPTIME%'          => $uptime,
        '%KERNEL_VERSION%'  => $kernelVersion,
        '%NETWORK_SPEED%'   => $networkSpeed,
    ];

    foreach ($replacements as $p => $v) {
        $motdTemplate = str_replace($p, $v, $motdTemplate);
    }
    file_put_contents($motdOutputPath, $motdTemplate);
}
