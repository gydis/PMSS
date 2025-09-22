<?php
/**
 * Command execution helpers for update workflows.
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/profile.php';
require_once __DIR__.'/../../runtime.php';

if (!function_exists('runStep')) {
    /**
     * Execute a shell command, keeping failures soft.
     */
    function runStep(string $description, string $command): int
    {
        pmssInitProfileStore();
        $dryRun  = getenv('PMSS_DRY_RUN') === '1';
        $started = microtime(true);
        $rc      = $dryRun ? 0 : runCommand($command, false);

        $duration    = microtime(true) - $started;
        $status      = $dryRun ? 'SKIP' : ($rc === 0 ? 'OK' : 'ERR');
        $lastOutput  = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? ['stdout' => '', 'stderr' => ''];
        $stdout      = $dryRun ? '' : ($lastOutput['stdout'] ?? '');
        $stderr      = $dryRun ? '' : ($lastOutput['stderr'] ?? '');
        $stderrShort = $stderr !== '' ? preg_replace('/\s+/', ' ', trim(substr($stderr, 0, 300))) : '';
        $stdoutShort = $stdout !== '' ? preg_replace('/\s+/', ' ', trim(substr($stdout, 0, 300))) : '';

        $message = sprintf('[%s %.3fs rc=%d] %s :: %s', $status, $duration, $rc, $description, $command);
        if ($status === 'ERR' && $stderrShort !== '') {
            $message .= ' :: '.$stderrShort;
        }
        logmsg($message);
        pmssRecordProfile([
            'description'    => $description,
            'command'        => $command,
            'status'         => $status,
            'rc'             => $rc,
            'duration'       => round($duration, 4),
            'dry_run'        => $dryRun,
            'stdout_excerpt' => $stdoutShort,
            'stderr_excerpt' => $stderrShort,
        ]);
        return $rc;
    }
}

if (!function_exists('runUserStep')) {
    /**
     * Run a command while tagging the associated user.
     */
    function runUserStep(string $user, string $description, string $command): int
    {
        return runStep("[user:$user] {$description}", $command);
    }
}

if (!function_exists('runStepSequence')) {
    /**
     * Execute multiple commands under a shared description banner.
     */
    function runStepSequence(string $description, array $commands): void
    {
        logmsg($description);
        foreach ($commands as $cmd) {
            runStep($description, $cmd);
        }
    }
}

if (!function_exists('aptCmd')) {
    /**
     * Compose a reusable apt-get command prefix.
     */
    function aptCmd(string $args): string
    {
        return 'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none '
            .'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold '
            .$args;
    }
}
