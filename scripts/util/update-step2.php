#!/usr/bin/php
<?php
/**
 * PMSS Update Script (dynamic portion)
 *
 * Handles the heavy lifting of system updates once the static updater
 * (/scripts/update.php) refreshes itself. Tasks include repository setup,
 * service configuration, user environment maintenance and security tweaks.
 *
 * This file is refreshed from GitHub by /scripts/update.php prior to each run.
 * Keep local changes minimal or contribute them upstream.
 */

require_once __DIR__.'/../lib/update.php';
require_once __DIR__.'/../lib/update/runtime/profile.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/update/runtime/processes.php';
require_once __DIR__.'/../lib/update/environment.php';
require_once __DIR__.'/../lib/update/distro.php';
require_once __DIR__.'/../lib/update/repositories.php';
require_once __DIR__.'/../lib/update/systemPrep.php';
require_once __DIR__.'/../lib/update/webStack.php';
require_once __DIR__.'/../lib/update/services/runtime.php';
require_once __DIR__.'/../lib/update/services/legacy.php';
require_once __DIR__.'/../lib/update/services/mediainfo.php';
require_once __DIR__.'/../lib/update/services/certificates.php';
require_once __DIR__.'/../lib/update/services/security.php';
require_once __DIR__.'/../lib/update/userMaintenance.php';
require_once __DIR__.'/../lib/update/networking.php';

requireRoot();

$distribution  = pmssDetectDistro();
$distroName    = $distribution['name'];
$distroVersion = $distribution['version'];
$lsbCodename   = $distribution['codename'];
$reportedVersion = (int) $distroVersion;
$repoVersion     = pmssVersionFromCodename($lsbCodename);
$repoLogMessage  = '';
if ($repoVersion === 0 && $reportedVersion > 0) {
    $repoVersion    = $reportedVersion;
    $repoLogMessage = sprintf('Repository codename %s unresolved; falling back to VERSION_ID %d', $lsbCodename !== '' ? $lsbCodename : 'unknown', $repoVersion);
} elseif ($repoVersion === 0 && $reportedVersion === 0) {
    $repoLogMessage = sprintf('Repository detection failed for distro=%s codename=%s; skipping repository updates', $distroName, $lsbCodename !== '' ? $lsbCodename : 'unknown');
}
if ($repoVersion !== 0 && $reportedVersion !== 0 && $repoVersion !== $reportedVersion) {
    $repoLogMessage = sprintf('Repository version mapped via codename %s -> %d (reported=%s)', $lsbCodename !== '' ? $lsbCodename : 'unknown', $repoVersion, $distroVersion);
}

putenv('DEBIAN_FRONTEND=noninteractive');
putenv('APT_LISTCHANGES_FRONTEND=none');
putenv('UCF_FORCE_CONFOLD=1');
putenv('UCF_FORCE_CONFNEW=0');
putenv('UCF_FORCE_CONFDEF=1');
putenv('NEEDRESTART_MODE=a');

// logmsg is defined in /scripts/update.php when this file is loaded from there.
// Provide a very small fallback so running this script standalone won't fatal.
if (!function_exists('logmsg')) {
    /**
     * Minimal logger used when update-step2 runs outside update.php.
     */
    function logmsg(string $message): void
    {
        $timestamp = date('[Y-m-d H:i:s] ');
        $primary   = '/var/log/pmss-update.log';
        $fallback  = '/tmp/pmss-update.log';

        @file_put_contents($primary, $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX)
     || @file_put_contents($fallback, $timestamp.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        fwrite(STDERR, $message.PHP_EOL);
    }
}

pmssConfigureAptNonInteractive('logmsg');
pmssCompletePendingDpkg();
$dpkgTargetVersion = $repoVersion > 0 ? $repoVersion : $reportedVersion;
if ($dpkgTargetVersion > 0) {
    pmssApplyDpkgSelections($dpkgTargetVersion);
}
if ($repoLogMessage !== '') {
    logmsg($repoLogMessage);
}

require_once __DIR__.'/../lib/update/users.php';

// Mark the start of this update step in the log.
logmsg('update-step2.php starting');
pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'start']);

// Ensure legacy soft.sh flow self-updates before we continue.
pmssEnsureLatestUpdater();

// Refresh repositories and queue packages up front so later tasks see required binaries.
pmssRefreshRepositories($distroName, $repoVersion, 'logmsg');
pmssAutoremovePackages();
include_once '/scripts/lib/update/apps/packages.php';
pmssFlushPackageQueue();
pmssAutoremovePackages();
pmssMigrateLegacyLocalnet();
pmssApplyRuntimeTemplates();

// --- Basic system preparation ---
pmssEnsureCgroupsConfigured('logmsg');
pmssEnsureSystemdSlices('logmsg');
pmssResetCorePermissions();
pmssEnsureLocaleBaseline();

// Web stack hardening and per-user HTTP refresh.
pmssConfigureWebStack($distroVersion);
pmssReapplyLocaleDefinitions();

// Load application installers automatically (sorted for deterministic order).
$apps = glob('/scripts/lib/update/apps/*.php') ?: [];
sort($apps);
foreach ($apps as $app) {
    include_once $app;
}

pmssEnsureLetsEncryptConfig();
pmssRemoveAutodlConfig();

// Legacy daemons that should never run globally.
$legacyServices = ['btsync', 'rslsync', 'pyload', 'sabnzbdplus', 'lighttpd'];
pmssDisableLegacyServices($legacyServices, $distroVersion);
pmssInstallMediaInfo($lsbCodename, 'logmsg');
pmssAdjustLighttpdSecurity();

// Per-user updates ensure ruTorrent stays consistent.
$rutorrentIndexSha = sha1((string) @file_get_contents('/etc/skel/www/rutorrent/index.html'));
pmssUpdateAllUsers($rutorrentIndexSha);

pmssEnsureAuthorizedKeysDirective();
pmssEnsureTestfile();
pmssRestrictAtopBinary();

pmssPostUpdateWebRefresh();
pmssRefreshSkeletonAndCron();
pmssInstallLogrotatePolicy();
pmssRestoreUserCrontabs();

pmssEnsureNetworkTemplate('logmsg');
pmssApplyNetworkConfig();
pmssApplySecurityHardening();

// Mark the end of phase 2 so log parsing knows we finished cleanly.
pmssProfileSummary();
pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'end']);
logmsg('update-step2.php completed');
