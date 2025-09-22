<?php
/**
 * Logging helpers shared by PMSS update routines.
 */

if (!defined('PMSS_LOG_FILE')) {
    define('PMSS_LOG_FILE', '/var/log/pmss-update.log');
}

if (!function_exists('pmssJsonLogPath')) {
    function pmssJsonLogPath(): string
    {
        static $path = null;
        if ($path === null) {
            $candidate = getenv('PMSS_JSON_LOG') ?: '';
            $path = $candidate !== '' ? $candidate : '';
        }
        return $path;
    }
}

if (!function_exists('pmssLogJson')) {
    function pmssLogJson(array $payload): void
    {
        $path = pmssJsonLogPath();
        if ($path === '') {
            return;
        }
        $payload['ts'] = $payload['ts'] ?? date('c');
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('logMessage')) {
    function logMessage(string $message, array $context = []): void
    {
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents(PMSS_LOG_FILE, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        echo $message.PHP_EOL;
        pmssLogJson([
            'event'   => 'log',
            'message' => $message,
            'context' => $context,
        ]);
    }
}

if (!function_exists('pmssSelectLogger')) {
    function pmssSelectLogger(?callable $logger = null): callable
    {
        if ($logger !== null && is_callable($logger)) {
            return $logger;
        }
        return 'logMessage';
    }
}
