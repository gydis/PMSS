<?php
/** Simple logging helper shared across cron scripts. */
class Logger {
    private string $log;
    private string $fallback;

    public function __construct(string $script, string $dir = '/var/log/pmss') {
        $base = basename($script, '.php');
        $this->log = rtrim($dir, '/') . '/' . $base . '.log';
        $this->fallback = '/tmp/' . $base . '.log';
    }

    public function msg(string $m): void {
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents($this->log, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX)
        || @file_put_contents($this->fallback, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX);
        echo $m.PHP_EOL;
    }
}

/**
 * Legacy wrapper for older scripts.
 * Usage: require_once '/scripts/lib/logger.php';
 *   $log = new Logger(__FILE__);
 *   $log->msg('text');
 */
if (!function_exists('logmsg')) {
    function logmsg(string $m): void {
        global $logmsg_default_logger;
        if (!isset($logmsg_default_logger)) {
            $logmsg_default_logger = new Logger($_SERVER['SCRIPT_NAME'] ?? __FILE__);
        }

        // Dev: check if systemctl --user is available and break the install script when it is broken    if (!is_dir($logFiles[''])) {
        $user = 'alice';

        // --- passthru debug wrapper ---
        ob_start();
        passthru(<<<"BASH"
        u="$user"
        uid=\$(id -u "\$u" 2>/dev/null || true)

        echo "== can talk to user manager? =="

        sudo -u "\$u" \
        XDG_RUNTIME_DIR="/run/user/\$uid" \
        DBUS_SESSION_BUS_ADDRESS="unix:path=/run/user/\$uid/bus" \
        systemctl --user is-system-running 2>&1
        BASH
        , $exitCode);
        $output = trim(ob_get_clean());

        // Print the output
        echo $output . PHP_EOL;

        // --- decide what to do ---
        if (stripos($output, 'running') === false) {
            // Not "running" → stop script
            fwrite(STDERR, "❌ systemd user manager not running for $user.\n");
        }

        echo "✅ systemd user manager is running — continuing...\n";

        $logmsg_default_logger->msg($m);
    }
}
