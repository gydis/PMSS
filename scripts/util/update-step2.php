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

// Include required libraries
require_once __DIR__.'/../lib/update.php';

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
     * Minimal logger used when update-step2 runs outside update.php
     *
     * Mirrors the behaviour of the main updater by writing to
     * /var/log/pmss-update.log and falling back to /tmp if needed.
     */
    function logmsg(string $m): void {
        $ts = date('[Y-m-d H:i:s] ');
        $log = '/var/log/pmss-update.log';
        $alt = '/tmp/pmss-update.log';

        @file_put_contents($log, $ts.$m.PHP_EOL, FILE_APPEND | LOCK_EX)
     || @file_put_contents($alt, $ts.$m.PHP_EOL, FILE_APPEND | LOCK_EX);
        fwrite(STDERR, $m.PHP_EOL);
    }
}

/**
 * Wrapper around runCommand that logs intent and keeps failures non-fatal.
 */
if (!isset($GLOBALS['PMSS_PROFILE'])) {
    $GLOBALS['PMSS_PROFILE'] = [];
}

// Detect distro information once so helpers and package installers stay in sync.
$distroName = strtolower((string) getDistroName());
if ($distroName === '') {
    $fallbackName = strtolower(trim((string) @shell_exec('lsb_release -is 2>/dev/null')));
    if ($fallbackName !== '') {
        $distroName = $fallbackName;
    } else {
        logmsg('Could not detect distro name; defaulting to debian');
        $distroName = 'debian';
    }
}

$distroVersionRaw = (string) getDistroVersion();
if ($distroVersionRaw === '') {
    $fallbackVersion = trim((string) @shell_exec('lsb_release -rs 2>/dev/null'));
    if ($fallbackVersion !== '') {
        $distroVersionRaw = $fallbackVersion;
    }
}

if ($distroVersionRaw === '') {
    logmsg('Could not detect distro version; defaulting to 0');
}
$distroVersion = (int) filter_var($distroVersionRaw, FILTER_SANITIZE_NUMBER_INT) ?: 0;

// Ensure apt consistently operates without interactive prompts.
$aptNonInteractivePath = '/etc/apt/apt.conf.d/90pmss-noninteractive';
$aptNonInteractiveContents = <<<APTCONF
Dpkg::Options {
    "--force-confdef";
    "--force-confold";
}
APT::Get::Assume-Yes "true";
APT::Color "0";
DPkg::Use-Pty "0";
APTCONF;

$existingAptConfig = @file_get_contents($aptNonInteractivePath);
if ($existingAptConfig === false || trim($existingAptConfig) !== trim($aptNonInteractiveContents)) {
    if (@file_put_contents($aptNonInteractivePath, $aptNonInteractiveContents) === false) {
        logmsg('[WARN] Unable to write apt non-interactive configuration at '.$aptNonInteractivePath);
    } else {
        @chmod($aptNonInteractivePath, 0644);
        logmsg('Updated apt non-interactive configuration ('.$aptNonInteractivePath.')');
    }
} else {
    logmsg('[SKIP] apt non-interactive configuration already up to date');
}

// Finish any interrupted package configuration before continuing.
runStep('Completing pending dpkg configuration', 'dpkg --configure -a');

function pmssRecordProfile(array $entry): void
{
    $GLOBALS['PMSS_PROFILE'][] = $entry;
    pmssLogJson(['event' => 'step', 'data' => $entry]);
}

function runStep(string $description, string $command): int
{
    $dryRun = getenv('PMSS_DRY_RUN') === '1';
    $started = microtime(true);
    $rc = 0;
    if (!$dryRun) {
        $rc = runCommand($command, false);
    }
    $duration = microtime(true) - $started;
    $status = $dryRun ? 'SKIP' : ($rc === 0 ? 'OK' : 'ERR');
    $lastOutput = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? ['stdout' => '', 'stderr' => ''];
    $stdout = $dryRun ? '' : ($lastOutput['stdout'] ?? '');
    $stderr = $dryRun ? '' : ($lastOutput['stderr'] ?? '');
    $stderrExcerpt = $stderr !== '' ? preg_replace('/\s+/', ' ', trim(substr($stderr, 0, 300))) : '';
    $stdoutExcerpt = $stdout !== '' ? preg_replace('/\s+/', ' ', trim(substr($stdout, 0, 300))) : '';

    $message = sprintf('[%s %.3fs rc=%d] %s :: %s', $status, $duration, $rc, $description, $command);
    if ($status === 'ERR' && $stderrExcerpt !== '') {
        $message .= ' :: '.$stderrExcerpt;
    }
    logmsg($message);
    pmssRecordProfile([
        'description' => $description,
        'command'     => $command,
        'status'      => $status,
        'rc'          => $rc,
        'duration'    => round($duration, 4),
        'dry_run'     => $dryRun,
        'stdout_excerpt' => $stdoutExcerpt,
        'stderr_excerpt' => $stderrExcerpt,
    ]);
    return $rc;
}

/**
 * Convenience helper for user-scoped commands.
 */
function runUserStep(string $user, string $description, string $command): int
{
    return runStep("[user:$user] $description", $command);
}

function aptCmd(string $args): string
{
    return 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none '
        .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
        .$args;
}

function killProcess(string $name, string $description): void
{
    exec('pgrep -x '.escapeshellarg($name).' >/dev/null 2>&1', $_, $status);
    if ($status !== 0) {
        logmsg("[SKIP] {$description} (no {$name} processes)");
        return;
    }
    runStep($description, 'killall -9 '.escapeshellarg($name));
}

function disableUnitIfPresent(string $unit, string $description): void
{
    if (!is_dir('/run/systemd/system')) {
        logmsg("[SKIP] {$description} (systemd unavailable)");
        return;
    }
    exec('systemctl list-unit-files '.escapeshellarg($unit).' 2>/dev/null', $output, $status);
    $found = false;
    if ($status === 0) {
        foreach ($output as $line) {
            if (stripos($line, $unit) === 0) {
                $found = true;
                break;
            }
        }
    }
    if (!$found) {
        logmsg("[SKIP] {$description} (unit {$unit} missing)");
        return;
    }
    runStep($description, 'systemctl disable '.escapeshellarg($unit));
}

/**
 * Execute a series of commands under a shared description.
 */
function runStepSequence(string $description, array $commands): void
{
    logmsg($description);
    foreach ($commands as $cmd) {
        runStep($description, $cmd);
    }
}

function pmssProfileSummary(): void
{
    $profile = $GLOBALS['PMSS_PROFILE'] ?? [];
    if (empty($profile)) {
        return;
    }
    usort($profile, static function ($a, $b) {
        return $b['duration'] <=> $a['duration'];
    });
    $top = array_slice($profile, 0, 5);
    $lines = array_map(static function ($entry) {
        return sprintf('%s (%s %.3fs rc=%d)', $entry['description'], $entry['status'], $entry['duration'], $entry['rc']);
    }, $top);
    logmsg('Step duration summary (top 5): '.implode(' | ', $lines));
    pmssLogJson(['event' => 'profile_summary', 'steps' => $top]);

    $profileOutput = getenv('PMSS_PROFILE_OUTPUT') ?: '';
    if ($profileOutput === '') {
        $jsonLogPath = getenv('PMSS_JSON_LOG') ?: '';
        if ($jsonLogPath !== '') {
            $profileOutput = $jsonLogPath.'.profile.json';
        }
    }
    if ($profileOutput !== '') {
        $dir = dirname($profileOutput);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($profileOutput, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

require_once __DIR__.'/../lib/update/users.php';

// Mark the start of this update step in the log
logmsg('update-step2.php starting');

//Hacky thing due to a bug in github version not getting updated when refactored.
//In essence this makes update.php kinda dynamic too...
#TODO Remove around 05/2024
$updateSource = file_get_contents('/scripts/update.php');
// If the source still contains a call to soft.sh it's the old non-dynamic updater.
// Use the latest updater from GitHub and run it once to update this script.
if (strpos($updateSource, 'soft.sh') !== false) {
    runStep('Fetching latest update.php from GitHub', 'wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php');
    runStep('Executing refreshed update.php', '/scripts/update.php');
    die();   // Avoid infinite loop :)
}






// --- Basic system preparation ---
// Ensure cgroups are configured before doing anything heavy. Some older
// nodes come with broken defaults which can prevent spawning new processes.
$fstab = file_get_contents('/etc/fstab');
if (strpos($fstab, 'cgroup') === false) {   // Cgroups not installed
runStep('Ensuring cgroup-bin package present', aptCmd('install -y -q cgroup-bin'));
    $mount = "\ncgroup  /sys/fs/cgroup  cgroup  defaults  0   0\n";
    file_put_contents('/etc/fstab', $mount, FILE_APPEND);
    runStep('Mounting /sys/fs/cgroup', 'mount /sys/fs/cgroup');
}

// Increase pids max; skip gracefully on systems using unified cgroups without this path.
$rootPidSlice = '/sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max';
if (file_exists($rootPidSlice)) {
    runStep('Raising PID limit for root user slice', "sh -c 'echo 100000 > {$rootPidSlice}'");
} else {
    logmsg('[SKIP] Raising PID limit for root user slice (pids controller path missing)');
}

// Systemd slice configuration ensures proper process limits for users
if (file_exists('/usr/lib/systemd/user-.slice.d/99-pmss.conf')) unlink('/usr/lib/systemd/user-.slice.d/99-pmss.conf');  // Remove obsolete defaults
if (!file_exists('/usr/lib/systemd/user-.slice.d/15-pmss.conf')) {
    // Install our tuned slice limits and reload systemd
    runStep('Installing user slice override template', 'cp -p /etc/seedbox/config/template.user-slices-pmss.conf /usr/lib/systemd/system/user-.slice.d/15-pmss.conf');
    runStep('Setting permissions on user slice override', 'chmod 644 /usr/lib/systemd/system/user-.slice.d/15-pmss.conf');
    runStep('Reloading systemd manager configuration', 'systemctl daemon-reload');
}



// Ensure default permissions on config directories. Git does not preserve them
// reliably so we enforce them each update.
runStep('Resetting /etc/seedbox permissions', 'chmod -R 755 /etc/seedbox');
runStep('Resetting /scripts permissions', 'chmod -R 750 /scripts');

// Update Locale, some servers sometimes have just en_US or something else.
runStep('Generating en_US.UTF-8 locale', 'locale-gen en_US.UTF-8');
runStep('Setting default system locale', 'update-locale LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8');

// Generate MOTD using library helper
generateMotd();



$currentRepos = sha1(file_get_contents('/etc/apt/sources.list'));

// Retrieve repository definitions via shared helpers for reuse across installers.
$repoTemplates = [
    'jessie'   => loadRepoTemplate('jessie', 'logmsg'),
    'buster'   => loadRepoTemplate('buster', 'logmsg'),
    'bullseye' => loadRepoTemplate('bullseye', 'logmsg'),
    'bookworm' => loadRepoTemplate('bookworm', 'logmsg'),
    'trixie'   => loadRepoTemplate('trixie', 'logmsg'),
];

updateAptSources(
    $distroName,
    (int)$distroVersion,
    $currentRepos,
    $repoTemplates,
    'logmsg'
);

runStep('Refreshing apt package index', aptCmd('update'));

// Localnet file location fix -- this is very old TODO Remove say 09/2025
if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
    runStep('Migrating legacy localnet configuration', 'mv /etc/seedbox/localnet /etc/seedbox/config/localnet');
}

//Install latest rc.local file and execute it
runStep('Updating rc.local template', 'cp /etc/seedbox/config/template.rc.local /etc/rc.local');
runStep('Setting rc.local ownership', 'chown root.root /etc/rc.local');
runStep('Setting rc.local permissions', 'chmod 750 /etc/rc.local');
runStep('Executing rc.local to apply runtime tweaks', 'nohup /etc/rc.local >> /dev/null 2>&1');

//Install latest systemd/system.conf
runStep('Installing systemd system.conf template', 'cp /etc/seedbox/config/template.systemd.system.conf /etc/systemd/system.conf');
runStep('Setting permissions on systemd system.conf', 'chmod 644 /etc/systemd/system.conf');
runStep('Reexecuting systemd to pick up configuration', '/usr/bin/systemctl daemon-reexec');

//Install latest sshd_config
runStep('Installing sshd configuration template', 'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config');
runStep('Setting sshd_config permissions', 'chmod 644 /etc/ssh/sshd_config');
runStep('Restarting sshd to load updated configuration', '/usr/bin/systemctl restart sshd');



// Install APT Packages etc.
include_once '/scripts/lib/update/apps/packages.php';

// Clean up packages that are no longer required after upgrades
runStep('Removing packages no longer required', aptCmd('autoremove -y'));


if ($distroVersion < 10) {
    runStep('Stopping lighttpd (init.d)', '/etc/init.d/lighttpd stop');
    runStep('Disabling lighttpd from sysvinit runlevels', 'update-rc.d lighttpd stop 2 3 4 5');
    runStep('Removing lighttpd sysvinit hooks', 'update-rc.d lighttpd remove');
    killProcess('lighttpd', 'Terminating lingering lighttpd processes');
    killProcess('php-cgi', 'Terminating lingering php-cgi processes');
    runStep('Ensuring nginx defaults set in sysvinit', 'update-rc.d nginx defaults');
} else {
    runStep('Stopping lighttpd (systemd)', '/etc/init.d/lighttpd stop');
    disableUnitIfPresent('lighttpd', 'Disabling lighttpd systemd service');
    killProcess('lighttpd', 'Terminating lingering lighttpd processes');
    killProcess('php-cgi', 'Terminating lingering php-cgi processes');
    runStep('Enabling nginx systemd service', 'systemctl enable nginx');
}

// Web server configuration and restart
runStep('Refreshing lighttpd configuration', '/scripts/util/configureLighttpd.php');
runStep('Regenerating nginx configuration', '/scripts/util/createNginxConfig.php');
runStep('Verifying user HTTP authentication files', '/scripts/util/checkUserHtpasswd.php');
runStep('Restarting nginx service', '/etc/init.d/nginx restart');
runStep('Checking lighttpd per-user instances', '/scripts/cron/checkLighttpdInstances.php');
runStep('Setting /home directory permissions', 'chmod 751 /home');
runStep('Setting user home directory permissions', 'chmod 740 /home/*');

// Set locales
runStep('Ensuring en_US.UTF-8 locale is enabled', "sed -i 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/g' /etc/locale.gen");
runStep('Regenerating locales', 'locale-gen');
runStep('Setting default LANG in /etc/default/locale', "sed -i 's/LANG=en_US\\n/LANG=en_US.UTF-8/g' /etc/default/locale");


// Load application installers automatically (sorted for deterministic order)
$apps = glob('/scripts/lib/update/apps/*.php');
sort($apps);
foreach ($apps as $app) {
    include_once $app;
}


runStep('Updating Let\'s Encrypt configuration', '/scripts/util/setupLetsEncrypt.php noreplies@pulsedmedia.com');


// Autodl irssi cfg
/*if (!file_exists('/etc/autodl.cfg')) {
    $autodlConfig = <<<EOF
[options]
allowed = watchdir
EOF;
    file_put_contents('/etc/autodl.cfg', $autodlConfig);
}*/
if (file_exists('/etc/autodl.cfg')) {
    unlink('/etc/autodl.cfg');
}

#Don't run certain programs as "server wide" daemons, as we always need per user for these
$servicesToCheck = array(
    'btsync',
    'rslsync',
    'pyload',
    'sabnzbdplus',
    'lighttpd'
);
foreach ($servicesToCheck AS $thisService) {
    if (file_exists('/etc/init.d/' . $thisService)) {
        runStep("Stopping legacy service {$thisService}", "/etc/init.d/{$thisService} stop");
    }
    if ($distroVersion < 10) {
        runStep("Disabling {$thisService} in sysvinit", "update-rc.d {$thisService} disable");
    } else {
        disableUnitIfPresent($thisService, "Disabling {$thisService} systemd unit");
    }
}




// Install mediainfo (used by some optional features). The packages are
// distributed per-distro and require the LSB codename in the filename.
if (!file_exists('/usr/bin/mediainfo')) {
    $current = getcwd();
    mkdir('/tmp/mediainfo');
    chdir('/tmp/mediainfo');

    // Use LSB release codename when building package URLs
    $mediaVersion = $lsbrelease;
    if (!empty($mediaVersion)) {
        runStep('Downloading libzen package', "wget http://pulsedmedia.com/remote/pkg/libzen0_0.4.24-1_amd64.Debian_{$mediaVersion}.deb");
        runStep('Downloading libmediainfo package', "wget http://pulsedmedia.com/remote/pkg/libmediainfo0_0.7.53-1_amd64.Debian_{$mediaVersion}.deb");
        runStep('Downloading mediainfo package', "wget http://pulsedmedia.com/remote/pkg/mediainfo_0.7.52-1_amd64.Debian_{$mediaVersion}.deb");
        runStep('Installing libzen', "dpkg -i libzen0_0.4.24-1_amd64.Debian_{$mediaVersion}.deb");
        runStep('Installing libmediainfo', "dpkg -i libmediainfo0_0.7.53-1_amd64.Debian_{$mediaVersion}.deb");
        runStep('Installing mediainfo', "dpkg -i mediainfo_0.7.52-1_amd64.Debian_{$mediaVersion}.deb");
    }
    chdir($current);
}




// Lighttpd config security update
runStep('Adjusting /etc/lighttpd/lighttpd.conf permissions', 'chmod 750 /etc/lighttpd/lighttpd.conf');
runStep('Setting ownership on /etc/lighttpd/lighttpd.conf', 'chown www-data.www-data /etc/lighttpd/lighttpd.conf');
runStep('Setting ownership on /etc/lighttpd/.htpasswd', 'chown www-data.www-data /etc/lighttpd/.htpasswd');
runStep('Adjusting /etc/lighttpd/.htpasswd permissions', 'chmod 750 /etc/lighttpd/.htpasswd');


// Per user updates
$rutorrentIndexSha = sha1(file_get_contents('/etc/skel/www/rutorrent/index.html'));
foreach ($users as $thisUser) {
    if ($thisUser === '') {
        continue;
    }
    pmssUpdateUserEnvironment($thisUser, [
        'rutorrent_index_sha' => $rutorrentIndexSha,
    ]);
}

/*
// Let's setup quota vfsv1
$fstabFile = file_get_contents('/etc/fstab');
if (!empty($fstabFile)) {
	$newFstab = str_replace('vfsv0', 'vfsv1', $fstabFile);
        if ($fstabFile != $newFstab && !empty($newFstab)) {
		file_put_contents('/etc/fstab', $newFstab);
		passthru('quotaoff -a; mount -o remount /home; quotaon -a; /scripts/util/quotaFix.php');

	}
}
*/

// Allow keybased auth
// Need to change line:
// #AuthorizedKeysFile     %h/.ssh/authorized_keys
$sshdConfig = file_get_contents('/etc/ssh/sshd_config');
$sshdConfigChanged = str_replace('#AuthorizedKeysFile', 'AuthorizedKeysFile', $sshdConfig);
if ($sshdConfig != $sshdConfigChanged) {
    echo "# Allowing SSH Key based authentication.\n";
    copy('/etc/ssh/sshd_config', '/etc/ssh/pmss.sshd_config');  // Backup original
    file_put_contents('/etc/ssh/sshd_config', $sshdConfigChanged);
    runStep('Restarting sshd service after config update', '/etc/init.d/ssh restart');
}




/**** Setup srvmgmt if this is a .pulsedmedia.com server
currently not in use
if (strpos($serverHostname, '.pulsedmedia.com') !== false) {
    // Let's see if we need to create srvmgmt account
    $passwd = file_get_contents('/etc/passwd');
    if (strpos($passwd, 'srvmgmt:') === false) {    // Check if the srvmgmt account doesn't exist
        // Yes we do!
        echo "# Adding srvmgmt account\n";
        passthru('useradd --skel /etc/seedbox/skel/srvmgmt -m srvmgmt');
        passthru('chsh -s /bin/secureShell.php srvmgmt');
        
        `chattr +a /home/srvmgmt/.bashrc`;
        `chattr +a /home/srvmgmt/.bash_history`;
        
    }
    
    if (file_exists('/bin/secureShell.php')) {
        #TODO Validate against SHA1 from remote server
        `chattr +a /bin/secureShell.php`;
    }
    
}
****/



// Create testfile :)
if (!file_exists('/var/www/testfile') or
    filesize('/var/www/testfile') != 104857600 ) {
        
    runStep('Generating /var/www/testfile sample', 'dd if=/dev/urandom of=/var/www/testfile bs=1M count=100 status=none');
}


// Disallow atop for regular users
chmod('/usr/bin/atop', 0750);

// Root maintenance cron jobs will be triggered later in this script. A
// duplicate invocation used to live here, which caused the tasks to run twice.
// Removing that extra call keeps update output concise.

/* Not using this method currently - afaik no one is
TODO Remove all references to this from all places.
if (!file_exists('/etc/seedbox/config/api.remoteKey')) {
    unlink('/etc/seedbox/config/api.localKey');
    passthru('/scripts/util/setupApiKey.php');
}
*/




runStep('Post-update lighttpd configuration refresh', '/scripts/util/configureLighttpd.php');
runStep('Post-update nginx configuration refresh', '/scripts/util/createNginxConfig.php');
runStep('Post-update htpasswd verification', '/scripts/util/checkUserHtpasswd.php');
runStep('Restarting nginx after configuration refresh', '/etc/init.d/nginx restart');
runStep('Checking lighttpd instances after update', '/scripts/cron/checkLighttpdInstances.php');

// Ensure skeleton permissions and misc configs are up to date
runStep('Refreshing skeleton permissions', '/scripts/util/setupSkelPermissions.php');
runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');
runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');
//passthru('/scripts/util/setupApiKey.php');


$logrotateTemplate = '/etc/seedbox/config/template.logrotate.pmss';
if (file_exists($logrotateTemplate)) {
    runStep('Installing logrotate policy for PMSS update logs', sprintf('cp %s /etc/logrotate.d/pmss-update', escapeshellarg($logrotateTemplate)));
    runStep('Setting permissions on PMSS logrotate policy', 'chmod 644 /etc/logrotate.d/pmss-update');
}


// Restore the default crontab for all users
$crontabRestore = sprintf(
    'bash -lc %s',
    escapeshellarg('/scripts/listUsers.php | xargs -r -I{} crontab -u {} /etc/seedbox/config/user.crontab.default')
);
runStep(
    'Restoring default crontab for all users',
    $crontabRestore
);



$networkConfigFile = '/etc/seedbox/config/network';
if (!file_exists($networkConfigFile)) {

    // Initial network configuration template
    $networkConfig = <<<EOF
<?php
#Default settings, change these to suit your system. Speeds are in mbits
return array(
    'interface' => 'eth0',
    'speed' => '1000',
    'throttle' => array(
      'min' => 50,
      'max' => 100,
      'soft' => 250,          // Near limit so soft limit
      'limitSoft' => 80,      // % where soft limit is enabled
      'limitExceedMax' => 20  // % before going to minimum limit

    )

);
EOF;

    file_put_contents($networkConfigFile, $networkConfig);
    logmsg('Created default network configuration');

}
runStep('Reapplying network configuration', '/scripts/util/setupNetwork.php');

// Temporary security hardening until dedicated script exists
runStep('Hardening access to session and network binaries', 'chmod o-r /var/log/wtmp /var/run/utmp /usr/bin/netstat /usr/bin/who /usr/bin/w');

// Mark the end of phase 2 so log parsing knows we finished cleanly
pmssProfileSummary();
logmsg('update-step2.php completed');
