#!/usr/bin/php
<?php
/**
 * PMSS Bootstrap Updater
 *
 * Usage examples:
 *   # /scripts/update.php
 *   # /scripts/update.php git/main:2025-05-11
 *   # /scripts/update.php git/main:2025-09-21 --dry-run
 *
 * Keep this file tiny and dependency-free. Its sole duties are:
 *   1. Fetch the requested PMSS snapshot (defaults to git/main)
 *   2. Copy the scripts/etc/var trees into place (unless --dry-run)
 *   3. Hand control to scripts/util/update-step2.php for the heavy work
 *
 * Any new behaviour belongs in update-step2.php or later tooling so this
 * bootstrapper almost never needs to change.
 *
 * @author    Aleksi + Codex
 * @copyright Magna Capax Finland Oy 2010-2025
 */
declare(strict_types=1);

const DEFAULT_REPO  = 'https://github.com/MagnaCapax/PMSS';
const CURL_UA       = 'PMSS-Updater (+https://pulsedmedia.com)';
const VERSION_DIR   = '/etc/seedbox/config';
const VERSION_FILE  = VERSION_DIR.'/version';
const VERSION_META  = VERSION_DIR.'/version.meta';
const JSON_LOG      = '/var/log/pmss-update.jsonl';
const EXIT_PARSE    = 11;
const EXIT_FETCH    = 12;
const EXIT_COPY     = 13;

if (!function_exists('logmsg')) {
    /**
     * Minimal logger shared with update-step2.php when it is required later.
     */
    function logmsg(string $message): void
    {
        $ts  = date('[Y-m-d H:i:s] ');
        $log = '/var/log/pmss-update.log';
        $alt = '/tmp/pmss-update.log';

        @file_put_contents($log, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX)
     || @file_put_contents($alt, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        fwrite(STDOUT, $message.PHP_EOL);
    }
}

/**
 * Append a JSON event for programmatic consumers.
 */
function logJson(array $payload): void
{
    $payload['ts'] = $payload['ts'] ?? date('c');
    $dir = dirname(JSON_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }
    @file_put_contents(JSON_LOG, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX);
    if (file_exists(JSON_LOG)) {
        @chmod(JSON_LOG, 0640);
    }
}

/**
 * Emit an error and stop execution.
 */
function fatal(string $message, int $code): void
{
    logmsg('[ERROR] '.$message);
    exit($code);
}

/**
 * Guard against accidental execution as a non-root user.
 */
function ensureRoot(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fatal('This updater must be run as root.', EXIT_COPY);
    }
}

/**
 * Execute a shell command and fail fast on error.
 */
function run(string $command, int $failureCode): void
{
    logmsg('[RUN] '.$command);
    passthru($command, $rc);
    if ($rc !== 0) {
        fatal("Command failed (rc={$rc}): {$command}", $failureCode);
    }
}

/**
 * Execute a best-effort command, logging any failure but continuing.
 */
function runSoft(string $command): void
{
    logmsg('[RUN] '.$command);
    passthru($command, $rc);
    if ($rc !== 0) {
        logmsg("[WARN] Command failed (rc={$rc}): {$command}");
    }
}

/**
 * Create a temporary directory that holds the fetched snapshot.
 */
function tmpdir(): string
{
    $base = sys_get_temp_dir().'/pmss-update-'.bin2hex(random_bytes(4));
    if (!@mkdir($base, 0700, true)) {
        fatal("Unable to create temporary directory {$base}", EXIT_FETCH);
    }
    return $base;
}

/**
 * Default to the main branch when no spec is supplied.
 */
function defaultSpec(): string
{
    return 'git/main';
}

/**
 * Normalise the user-supplied spec into a canonical representation.
 */
function normaliseSpec(string $spec): string
{
    $spec = trim($spec);
    if ($spec === '') {
        return '';
    }
    if (preg_match('/^(git|release)([\/:]).+/i', $spec)) {
        return $spec;
    }
    if (preg_match('/^git\s+(.*)$/i', $spec, $m)) {
        $rest = str_replace(' ', '', $m[1]);
        return $rest === '' ? 'git/main' : 'git/'.$rest;
    }
    if (preg_match('/^release\s*(.*)$/i', $spec, $m)) {
        $rest = trim($m[1]);
        return $rest === '' ? 'release' : 'release:'.$rest;
    }
    if (preg_match('#^(https?|ssh)://#', $spec)) {
        return 'git/'.$spec;
    }
    if (preg_match('/^[a-z0-9._\-]+$/i', $spec)) {
        return 'git/'.$spec;
    }
    return '';
}

/**
 * Read the previously stored spec to preserve continuity.
 */
function storedSpec(): string
{
    if (!file_exists(VERSION_FILE)) {
        return '';
    }
    $raw = trim((string)@file_get_contents(VERSION_FILE));
    if ($raw === '') {
        return '';
    }
    if (strpos($raw, '@') !== false) {
        [$raw,] = explode('@', $raw, 2);
    }
    return trim($raw);
}

/**
 * Break a normalised spec into actionable components.
 */
function parseSpec(string $spec): array
{
    if (!preg_match('/^(git|release)([\/:])(.*)$/i', $spec, $m)) {
        fatal("Unable to parse source spec '{$spec}'", EXIT_PARSE);
    }

    $type = strtolower($m[1]);
    $rest = $m[3];

    if ($type === 'release') {
        $tag = ltrim($rest, ':');
        return [
            'type'   => 'release',
            'repo'   => DEFAULT_REPO,
            'branch' => '',
            'pin'    => $tag,
        ];
    }

    $repo   = DEFAULT_REPO;
    $branch = 'main';
    $pin    = '';

    if (preg_match('/:(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?)$/', $rest, $mm)) {
        $pin  = $mm[1];
        $rest = substr($rest, 0, -strlen($mm[0]));
    }

    if ($rest === '') {
        $branch = 'main';
    } elseif (preg_match('#^(https?|ssh)://#', $rest)) {
        $pos = strrpos($rest, ':');
        if ($pos !== false && $pos > 8) {
            $repo   = substr($rest, 0, $pos);
            $branch = substr($rest, $pos + 1) ?: 'main';
        } else {
            $repo   = rtrim($rest, '/');
            $branch = 'main';
        }
    } elseif (strpos($rest, ':') !== false) {
        [$maybeRepo, $maybeBranch] = explode(':', $rest, 2);
        if ($maybeBranch === '' || $maybeBranch === null) {
            $maybeBranch = 'main';
        }
        if (preg_match('#://|/#', $maybeRepo)) {
            $repo   = $maybeRepo;
            $branch = $maybeBranch;
        } else {
            $branch = $maybeBranch;
        }
    } else {
        $branch = $rest;
    }

    return [
        'type'   => 'git',
        'repo'   => $repo,
        'branch' => $branch,
        'pin'    => $pin,
    ];
}

/**
 * Ask GitHub for the latest release tag.
 */
function resolveLatestRelease(): string
{
    $ctx = stream_context_create([
        'http' => [
            'user_agent' => CURL_UA,
            'timeout'    => 10,
        ],
    ]);
    $json = @file_get_contents('https://api.github.com/repos/MagnaCapax/PMSS/releases/latest', false, $ctx);
    if ($json === false) {
        fatal('Unable to query GitHub for the latest release tag.', EXIT_FETCH);
    }
    $data = json_decode($json, true);
    $tag  = is_array($data) ? (string)($data['tag_name'] ?? '') : '';
    if ($tag === '') {
        fatal('GitHub API did not return a tag_name for the latest release.', EXIT_FETCH);
    }
    return $tag;
}

/**
 * Download the requested snapshot into the temporary workspace.
 */
function fetchSource(array $spec, string $tmp): void
{
    if ($spec['type'] === 'release') {
        $tag = $spec['pin'] !== '' ? $spec['pin'] : resolveLatestRelease();
        $tar = $tmp.'/source.tgz';
        $url = 'https://api.github.com/repos/MagnaCapax/PMSS/tarball/'.rawurlencode($tag);
        $cmd = sprintf(
            'curl -sfL -A %s %s -o %s',
            escapeshellarg(CURL_UA),
            escapeshellarg($url),
            escapeshellarg($tar)
        );
        run($cmd, EXIT_FETCH);
        run('tar -xzf '.escapeshellarg($tar).' -C '.escapeshellarg($tmp).' --strip-components=1', EXIT_FETCH);
        return;
    }

    $clone = sprintf(
        'git clone --quiet --depth=1 --branch %s %s %s',
        escapeshellarg($spec['branch']),
        escapeshellarg($spec['repo']),
        escapeshellarg($tmp)
    );
    run($clone, EXIT_FETCH);

    if ($spec['pin'] !== '') {
        $rev = escapeshellarg($spec['branch'].'@{'.$spec['pin'].'}');
        run('cd '.escapeshellarg($tmp).' && git fetch --quiet && git checkout '.$rev, EXIT_FETCH);
    }
}

/**
 * Ensure the snapshot has the minimum required files before copying.
 */
function validateSnapshot(string $tmp): void
{
    $required = [
        $tmp.'/scripts/update.php',
        $tmp.'/scripts/util/update-step2.php',
    ];
    foreach ($required as $path) {
        if (!file_exists($path)) {
            fatal("Snapshot missing required file: {$path}", EXIT_COPY);
        }
    }
}

/**
 * Return true when a directory contains user-facing content beyond dot entries.
 */
function hasContent(string $directory): bool
{
    $handle = @opendir($directory);
    if ($handle === false) {
        return false;
    }
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        closedir($handle);
        return true;
    }
    closedir($handle);
    return false;
}

/**
 * Copy the fetched payload into place, skipping empty trees to stay fail-safe.
 */
function copyPayload(string $tmp, bool $dryRun): void
{
    validateSnapshot($tmp);

    $trees = [
        'scripts' => function (string $source) {
            if (!is_dir('/scripts')) {
                @mkdir('/scripts', 0755, true);
            }
            run('cp -rp '.escapeshellarg($source.'/.').' /scripts', EXIT_COPY);
        },
        'etc' => function (string $source) {
            run('cp -rpu '.escapeshellarg($source).' /', EXIT_COPY);
        },
        'var' => function (string $source) {
            run('cp -rp '.escapeshellarg($source).' /', EXIT_COPY);
        },
    ];

    foreach ($trees as $name => $copyFn) {
        $source = $tmp.'/'.$name;
        if (!is_dir($source) || !hasContent($source)) {
            logmsg("[WARN] Snapshot {$name} tree missing or empty, skipping copy");
            continue;
        }

        if ($dryRun) {
            logmsg("[DRY RUN] Would copy {$name} from {$source}");
            continue;
        }

        $copyFn($source);
    }

    if (!$dryRun) {
        run('chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox', EXIT_COPY);
        normaliseScriptsLayout();
    }
}

/**
 * Collapse legacy /scripts/scripts layouts introduced by older updaters.
 */
function normaliseScriptsLayout(): void
{
    $nested = '/scripts/scripts';
    if (!is_dir($nested)) {
        return;
    }
    logmsg('Detected nested /scripts/scripts layout, flattening');
    logJson(['event' => 'scripts_flatten', 'action' => 'start']);
    runSoft('cp -rp '.escapeshellarg($nested.'/.').' /scripts');
    runSoft('rm -rf '.escapeshellarg($nested));
    if (!file_exists('/scripts/util/update-step2.php')) {
        logmsg('[WARN] update-step2.php still missing after flattening');
        logJson(['event' => 'scripts_flatten', 'status' => 'update_step2_missing']);
    } else {
        logJson(['event' => 'scripts_flatten', 'status' => 'ok']);
    }
}

/**
 * Persist the chosen spec and metadata so future runs can resume safely.
 */
function recordVersion(string $versionSpec, array $meta, bool $dryRun): void
{
    $timestamp = time();
    $line      = $versionSpec.'@'.date('Y-m-d H:i', $timestamp);
    $meta['recorded_spec'] = $versionSpec;
    $meta['timestamp']     = date('c', $timestamp);

    if ($dryRun) {
        logmsg('[DRY RUN] Not writing version metadata');
        return;
    }

    if (!is_dir(VERSION_DIR)) {
        @mkdir(VERSION_DIR, 0755, true);
    }
    @file_put_contents(VERSION_FILE, $line.PHP_EOL);
    @file_put_contents(VERSION_META, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * Capture the commit hash from the staged git clone.
 */
function collectCommitHash(string $tmp): string
{
    $rev = @shell_exec('cd '.escapeshellarg($tmp).' && git rev-parse HEAD');
    return trim((string)$rev);
}

/**
 * Display a short usage helper.
 */
function usage(string $script): void
{
    echo "Usage: {$script} [<source-spec>] [--dry-run]\n";
    echo "Defaults to git/main when no spec is provided.\n";
}

/**
 * Clean up the temporary workspace without aborting the run.
 */
function cleanupTemp(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    runSoft('rm -rf '.escapeshellarg($path));
}

/**
 * Main control flow for the bootstrapper.
 */
function runUpdater(array $argv): void
{
    ensureRoot();

    $startTime = microtime(true);
    $dryRun    = false;
    $specInput = '';
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            usage($argv[0]);
            exit(0);
        }
        if ($arg === '--dry-run') {
            $dryRun = true;
            continue;
        }
        if ($specInput === '' && $arg !== '') {
            $specInput = $arg;
            continue;
        }
    }

    if ($specInput === '') {
        $specInput = storedSpec();
    }
    if ($specInput === '') {
        $specInput = defaultSpec();
    }

    $normalised = normaliseSpec($specInput);
    if ($normalised === '') {
        fatal("Invalid source spec '{$specInput}'", EXIT_PARSE);
    }

    $spec = parseSpec($normalised);
    logmsg('Source spec → '.json_encode($spec));
    logJson([
        'event'    => 'update_start',
        'spec'     => $normalised,
        'dry_run'  => $dryRun,
    ]);

    $tmp = tmpdir();

    try {
        fetchSource($spec, $tmp);

        $spec['commit'] = $spec['type'] === 'git' ? collectCommitHash($tmp) : '';

        copyPayload($tmp, $dryRun);

        $versionSpec = $spec['type'] === 'release'
            ? 'release'.($spec['pin'] !== '' ? ':'.$spec['pin'] : '')
            : (($spec['repo'] === DEFAULT_REPO ? 'git/'.$spec['branch'] : 'git/'.$spec['repo'].':'.$spec['branch']).($spec['pin'] !== '' ? ':'.$spec['pin'] : ''));

        recordVersion($versionSpec, [
            'spec_input'      => $specInput,
            'spec_normalized' => $normalised,
            'type'            => $spec['type'],
            'repo'            => $spec['repo'],
            'branch'          => $spec['branch'],
            'pin'             => $spec['pin'],
            'commit'          => $spec['commit'] ?? '',
        ], $dryRun);

        logJson([
            'event'        => 'snapshot_applied',
            'version_spec' => $versionSpec,
            'commit'       => $spec['commit'] ?? '',
            'dry_run'      => $dryRun,
        ]);
    } catch (\Throwable $e) {
        cleanupTemp($tmp);
        $code = (int)$e->getCode();
        if ($code === 0) {
            $code = EXIT_COPY;
        }
        logJson([
            'event'   => 'update_failed',
            'message' => $e->getMessage(),
            'code'    => $code,
        ]);
        fatal($e->getMessage(), $code);
    }

    cleanupTemp($tmp);

    putenv('PMSS_JSON_LOG='.JSON_LOG);

    if ($dryRun) {
        logmsg('Skipping update-step2.php (dry run)');
        logJson([
            'event'  => 'update_step2_skipped',
            'reason' => 'dry_run',
        ]);
    } elseif (!file_exists('/scripts/util/update-step2.php')) {
        logmsg('Skipping update-step2.php (file missing after copy)');
        logJson([
            'event'  => 'update_step2_skipped',
            'reason' => 'missing',
        ]);
    } else {
        logmsg('Handing off to update-step2.php');
        logJson(['event' => 'update_step2_start']);
        $step2Start = microtime(true);
        passthru(PHP_BINARY.' /scripts/util/update-step2.php', $rc);
        $step2Duration = round(microtime(true) - $step2Start, 3);
        $step2Status = $rc === 0 ? 'ok' : 'error';
        logJson([
            'event'    => 'update_step2_end',
            'status'   => $step2Status,
            'rc'       => $rc,
            'duration' => $step2Duration,
        ]);
        if ($rc !== 0) {
            fatal('update-step2.php exited with status '.$rc, $rc);
        }
    }

    $totalDuration = round(microtime(true) - $startTime, 3);
    $prefix = $dryRun ? '[DRY RUN] ' : '';
    logmsg($prefix.'Update completed in '.$totalDuration.'s');
    logJson([
        'event'    => 'update_complete',
        'status'   => 'ok',
        'dry_run'  => $dryRun,
        'duration' => $totalDuration,
    ]);
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    runUpdater($argv);
}
