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
pmssApplyDpkgSelections();

$distribution  = pmssDetectDistro();
$distroName    = $distribution['name'];
$distroVersion = $distribution['version'];
$lsbCodename   = $distribution['codename'];

require_once __DIR__.'/../lib/update/users.php';

// Mark the start of this update step in the log.
logmsg('update-step2.php starting');
pmssLogJson(['event' => 'phase', 'name' => 'update-step2', 'status' => 'start']);

// Ensure legacy soft.sh flow self-updates before we continue.
pmssEnsureLatestUpdater();

// --- Basic system preparation ---
pmssEnsureCgroupsConfigured('logmsg');
pmssEnsureSystemdSlices('logmsg');
pmssResetCorePermissions();
pmssEnsureLocaleBaseline();

// Repository management and APT maintenance.
pmssRefreshRepositories($distroName, $distroVersion, 'logmsg');
pmssMigrateLegacyLocalnet();
pmssApplyRuntimeTemplates();

// Install APT packages and related tooling.
include_once '/scripts/lib/update/apps/packages.php';
pmssFlushPackageQueue();
pmssAutoremovePackages();

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
