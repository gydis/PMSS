<?php
/**
 * Environment bootstrap helpers for update-step2.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssConfigureAptNonInteractive')) {
    /**
     * Ensure apt operates in fully non-interactive mode.
     */
    function pmssConfigureAptNonInteractive(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        $path = '/etc/apt/apt.conf.d/90pmss-noninteractive';
        $contents = <<<CONF
Dpkg::Options {
    "--force-confdef";
    "--force-confold";
}
APT::Get::Assume-Yes "true";
APT::Color "0";
DPkg::Use-Pty "0";
CONF;

        $existing = @file_get_contents($path);
        if ($existing === false || trim($existing) !== trim($contents)) {
            if (@file_put_contents($path, $contents) === false) {
                $log('[WARN] Unable to write apt non-interactive configuration at '.$path);
                return;
            }
            @chmod($path, 0644);
            $log('Updated apt non-interactive configuration ('.$path.')');
            return;
        }

        $log('[SKIP] apt non-interactive configuration already up to date');
    }
}

if (!function_exists('pmssCompletePendingDpkg')) {
    /**
     * Finish any interrupted dpkg configuration runs.
     */
    function pmssCompletePendingDpkg(): void
    {
        // #TODO replace special-casing with a generic unit-unmask helper when more services require it.
        if (is_dir('/run/systemd/system')) {
            $state = trim((string) @shell_exec('systemctl is-enabled proftpd.service 2>/dev/null'));
            if ($state === 'masked') {
                runCommand('systemctl unmask proftpd.service');
            }
        }

        $rc = runStep('Completing pending dpkg configuration', 'dpkg --configure -a');
        if ($rc !== 0) {
            if (is_dir('/run/systemd/system')) {
                runStep('Unmasking proftpd for dpkg retry', 'systemctl unmask proftpd.service || true');
            }
            runStep('Retrying proftpd configure', 'dpkg --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic || true');
        }
    }
}

if (!function_exists('pmssApplyDpkgSelections')) {
    /**
     * Apply the baseline dpkg selection snapshot so required packages stay present.
     */
    function pmssApplyDpkgSelections(?int $distroVersion = null): void
    {
        $baseDir = __DIR__.'/dpkg';
        $candidates = [];
        if ($distroVersion !== null) {
            $candidates[] = sprintf('%s/selections-debian%d.txt', $baseDir, $distroVersion);
        }
        $candidates[] = $baseDir.'/selections-debian11.txt';
        $candidates[] = $baseDir.'/selections.txt';

        $selections = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_readable($candidate)) {
                $selections = $candidate;
                break;
            }
        }
        if ($selections === null) {
            return;
        }

        $cmd = sprintf('dpkg --set-selections < %s', escapeshellarg($selections));
        runStep('Applying dpkg selection baseline', $cmd);
        runStep('Installing packages from selection baseline', 'apt-get dselect-upgrade -y');
    }
}

if (!function_exists('pmssMigrateLegacyLocalnet')) {
    /**
     * Move the legacy localnet file into the configuration directory.
     */
    function pmssMigrateLegacyLocalnet(): void
    {
        if (file_exists('/etc/seedbox/localnet') && !file_exists('/etc/seedbox/config/localnet')) {
            runStep('Migrating legacy localnet configuration', 'mv /etc/seedbox/localnet /etc/seedbox/config/localnet');
        }
    }
}
