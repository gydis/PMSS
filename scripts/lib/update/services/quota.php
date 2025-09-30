<?php
/**
 * Quota configuration helpers.
 */

require_once __DIR__.'/../logging.php';

if (!function_exists('pmssEnsureQuotaOptions')) {
    /**
     * Ensure the given mount point in /etc/fstab contains the quota options.
     */
function pmssEnsureQuotaOptions(string $mountPoint, array $requiredOptions = null, ?callable $logger = null): void
    {
        // #TODO Add hermetic tests that verify fstab line parsing and option
        //       insertion behavior for common edge cases.
        $log = pmssSelectLogger($logger);
        if ($mountPoint === '') {
            return;
        }
        $fstab = '/etc/fstab';
        if (!is_readable($fstab)) {
            $log('[WARN] /etc/fstab not readable; skipping quota configuration.');
            return;
        }

        $lines = file($fstab, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $log('[WARN] Unable to read /etc/fstab; skipping quota configuration.');
            return;
        }

        $requiredOptions = $requiredOptions ?? ['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1'];
        $found = false;
        $changed = false;

        foreach ($lines as $idx => $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            $columns = preg_split('/\s+/', $trim);
            if (count($columns) < 4) {
                continue;
            }
            if ($columns[1] !== $mountPoint) {
                continue;
            }

            $found = true;
            $current = $columns[3] === 'defaults' ? ['defaults'] : explode(',', $columns[3]);
            $current = array_filter($current, 'strlen');
            $missing = false;
            foreach ($requiredOptions as $opt) {
                if (!in_array($opt, $current, true)) {
                    $missing = true;
                    if ($current === ['defaults']) {
                        $current = [];
                    }
                    $current[] = $opt;
                }
            }
            if (!$missing) {
                $log('[SKIP] Quota options already present for '.$mountPoint);
                break;
            }
            $columns[3] = implode(',', array_unique($current));
            $lines[$idx] = implode("\t", $columns);
            $changed = true;
            $log('Updated quota options for '.$mountPoint);
            break;
        }

        if (!$found) {
            $log('[WARN] Mount point '.$mountPoint.' not found in /etc/fstab; skipping quota updates.');
            return;
        }

        if ($changed) {
            $backup = $fstab.'.pmss-backup-'.date('YmdHis');
            @copy($fstab, $backup);
            file_put_contents($fstab, implode(PHP_EOL, $lines).PHP_EOL);
        }
    }
}

if (!function_exists('pmssEnsureDefaultQuotaMount')) {
    /**
     * Apply quota options to the default mount (env override PMSS_QUOTA_MOUNT).
     */
    function pmssEnsureDefaultQuotaMount(): void
    {
        $mount = getenv('PMSS_QUOTA_MOUNT');
        if ($mount === false || trim($mount) === '') {
            $mount = '/home';
        }
        pmssEnsureQuotaOptions($mount, null, 'logMessage');
    }
}
