<?php
/**
 * Orchestrates per-user traffic statistics calculations.
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../traffic.php';
require_once __DIR__.'/storage.php';

class TrafficStatsProcessor
{
    private trafficStatistics $stats;
    private string $trafficDir;
    private string $homeDir;
    private string $runtimeDir;
    private string $passwdFile;
    private string $statsRuntimeDir;
    private TrafficStorage $storage;

    public function __construct(trafficStatistics $stats, array $paths = [])
    {
        $this->stats           = $stats;
        $this->trafficDir      = rtrim($paths['traffic_dir'] ?? getenv('PMSS_TRAFFIC_DIR') ?: '/var/log/pmss/traffic', '/');
        $this->homeDir         = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir      = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->passwdFile      = $paths['passwd_file'] ?? getenv('PMSS_PASSWD_FILE') ?: '/etc/passwd';
        $this->statsRuntimeDir = $this->runtimeDir.'/trafficStats';
        $this->storage         = new TrafficStorage([
            'home_dir'   => $this->homeDir,
            'runtime_dir'=> $this->runtimeDir,
            'stats_dir'  => $this->statsRuntimeDir,
        ]);
    }

    /** Ensure runtime directories exist prior to processing. */
    public function ensureRuntime(): void
    {
        $this->storage->ensureRuntime();
    }

    /** Build comparison timestamps for each window. */
    public function buildCompareTimes(): array
    {
        $now = time();
        return [
            'month' => $now - (30 * 24 * 60 * 60),
            'week'  => $now - (7 * 24 * 60 * 60),
            'day'   => $now - (24 * 60 * 60),
            'hour'  => $now - (60 * 60),
            '15min' => $now - (15 * 60),
        ];
    }

    /** Detect whether we are running in worker mode for a specific user. */
    public function detectWorkerUser(array $argv): ?string
    {
        if (isset($argv[1])) {
            return $this->sanitizeUser($argv[1]);
        }
        return null;
    }

    /** Discover users by scanning the traffic log directory. */
    public function discoverUsers(): array
    {
        $pattern = $this->trafficDir.'/*';
        $users = array_filter(glob($pattern), 'is_file');
        $users = array_map('basename', $users);
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        return $users;
    }

    /** Launch detached workers for each user to process in parallel. */
    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $script = escapeshellarg($scriptPath);
        foreach ($users as $user) {
            $userArg = escapeshellarg($user);
            $command = "nohup {$script} {$userArg} >> /var/log/pmss/trafficStats.log 2>&1 &";
            passthru($command);
        }
    }

    /** Sanitize user input by stripping unexpected characters. */
    public function sanitizeUser(string $input): string
    {
        return preg_replace('/[^a-zA-Z0-9-_]/', '', $input);
    }

    /** Validate that a user has traffic data and a home directory. */
    public function validateUser(string $username): bool
    {
        $path = $this->trafficDir.'/'.$username;
        $homePath = $this->homeDir.'/'.$username;
        return file_exists($path)
            && is_readable($path)
            && $this->userExistsInPasswd($username)
            && is_dir($homePath);
    }

    /** Process and persist traffic statistics for a single user. */
    public function processUser(string $user, array $compareTimes): void
    {
        if (!$this->validateUser($user)) {
            logMessage(date('c').": Invalid user {$user}");
            return;
        }

        $dataLines = $this->stats->getData($user, (int)((35 * 24 * 60) / 5));
        if (trim($dataLines) === '') {
            logMessage(date('c').": No data for user {$user}");
            return;
        }

        $trafficData = array_filter(explode("\n", trim($dataLines)));
        if (count($trafficData) < 2) {
            logMessage(date('c').": Too little data for {$user}");
            return;
        }

        $rawTotals = array_fill_keys(array_keys($compareTimes), 0.0);
        $dailyTotals = [];
        $firstDay = '';

        foreach ($trafficData as $line) {
            $parsed = $this->stats->parseLine($line);
            if ($parsed === false) {
                logMessage(date('c').": Parsing line failed for {$user}, line: {$line}");
                continue;
            }

            foreach ($compareTimes as $label => $threshold) {
                if ($parsed['timestamp'] >= $threshold) {
                    $rawTotals[$label] += $parsed['data'];
                }
            }

            $currentDay = date('Y/m/d', $parsed['timestamp']);
            if ($firstDay === '') {
                $firstDay = $currentDay;
            }
            if ($currentDay !== $firstDay) {
                $dailyTotals[$currentDay] = ($dailyTotals[$currentDay] ?? 0) + $parsed['data'];
            }
        }

        $this->stats->saveUserTraffic($user, [
            'raw'     => $rawTotals,
            'display' => $this->formatDataDisplay($rawTotals),
            'daily'   => $dailyTotals,
        ]);
        logMessage(date('c').": Traffic stats for {$user} saved, month data consumption: {$rawTotals['month']}");
    }

    /** Format raw data totals into human readable units. */
    public function formatDataDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            if (($value / 1024 / 1024) > 1) {
                $formatted[$label] = round($value / 1024 / 1024, 2).'TiB';
            } elseif (($value / 1024) > 1) {
                $formatted[$label] = round($value / 1024, 2).'GiB';
            } else {
                $formatted[$label] = round($value, 2).'MiB';
            }
        }
        return $formatted;
    }

    private function userExistsInPasswd(string $username): bool
    {
        $passwd = @file_get_contents($this->passwdFile);
        if ($passwd === false) {
            return false;
        }
        return preg_match("/^".preg_quote($username, '/').":/m", $passwd) === 1;
    }
}
