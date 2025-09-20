#!/usr/bin/php
<?php
/**
 * PMSS Static Updater  –  safe copy‑based edition (Debian‑live friendly)
 *
 *   /scripts/update.php [<source-spec>] [--scriptonly] [--verbose] [--help]
 *
 *   source-spec (defaults to /etc/seedbox/config/version):
 *     release                  – latest GitHub release
 *     release:2025-07-12       – explicit tag
 *     git/main                 – branch “main” from default repo
 *     git/dev:2024-12-05       – branch “dev” pinned to past date
 *     git/https://url/repo.git:beta[:2025-01-01] – custom repo
 *
 * Exit codes: 0 OK · 11 parse · 12 download · 13 verify · 14 deploy
 */
declare(strict_types=1);

/* ───── PHP 7 polyfills ───── */
if (!function_exists('str_contains')) {
    function str_contains(string $hay, string $nee): bool {
        return $nee === '' || strpos($hay, $nee) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $hay, string $nee): bool {
        return $nee !== '' && strpos($hay, $nee) === 0;
    }
}

/* ───── quick help ───── */
if (in_array('--help', $argv, true)) {
    echo "Usage: {$argv[0]} [<source-spec>] [--scriptonly] [--verbose]\n";
    echo "Run with --verbose for debug output. See script header for examples.\n";
    exit(0);
}

/* ───── constants ───── */
$pmssVersionDir = getenv('PMSS_VERSION_DIR') ?: '/etc/seedbox/config';
define('PMSS_VERSION_DIR', $pmssVersionDir);
const VERSION_FILE = PMSS_VERSION_DIR.'/version';
const VERSION_META_FILE = PMSS_VERSION_DIR.'/version.meta';
require_once __DIR__.'/lib/logger.php';
$logger = new Logger(__FILE__);
$GLOBALS['logger'] = $logger;

const DEFAULT_REPO = 'https://github.com/MagnaCapax/PMSS';
const CURL_UA      = 'PMSS-Updater (+https://pulsedmedia.com)';
const RETRIES      = 3;

const EXIT_PARSE  = 11;
const EXIT_DL     = 12;
const EXIT_VERIFY = 13;
const EXIT_DEPLOY = 14;

/* ───── self update ───── */
function selfUpdate(): void {
    if (getenv('PMSS_SELFUPDATED') === '1') return;
    $url = 'https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php';
    $latest = @file_get_contents($url);
    if ($latest === false) return;                   // network issue, ignore
    if (sha1($latest) === sha1_file(__FILE__)) return; // already current
    file_put_contents(__FILE__, $latest);
    chmod(__FILE__, 0755);
    $args = array_map('escapeshellarg', array_slice($GLOBALS['argv'], 1));
    $cmd = 'PMSS_SELFUPDATED=1 '.escapeshellcmd(PHP_BINARY).' '.__FILE__.' '.implode(' ', $args);
    passthru($cmd, $rc);
    exit($rc);
}

/* ───── distro helpers ───── */
function defaultSpec(): string {
    return 'git/main:' . date('Y-m-d');
}

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

function pmssWriteVersionFiles(string $versionSpec, array $meta, ?int $timestamp = null, bool $dryRun = false, ?string $baseDir = null): array
{
    $timestamp = $timestamp ?? time();
    $line = $versionSpec.'@'.date('Y-m-d H:i', $timestamp);
    $meta['recorded_spec'] = $versionSpec;
    $meta['timestamp'] = $meta['timestamp'] ?? date('c', $timestamp);

    if (!$dryRun) {
        $baseDir = $baseDir ?? PMSS_VERSION_DIR;
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0755, true);
        }
        file_put_contents($baseDir.'/version', $line.PHP_EOL);
        file_put_contents($baseDir.'/version.meta', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    return [
        'line' => $line,
        'meta' => $meta,
    ];
}

function pmssRunUpdate(array $argv): int
{
    global $logger;

    require_once __DIR__.'/lib/update.php';

    if (!defined('PMSS_TEST_MODE')) {
        requireRoot();
        selfUpdate();
    }

    $verbose = false;
    $scriptonly = false;
    $updatedistro = false;
    $dryRun = false;
    $jsonLog = false;
    $profileOutput = null;

    $cli = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--verbose') { $verbose = true; continue; }
        if ($arg === '--scriptonly') { $scriptonly = true; continue; }
        if ($arg === '--updatedistro') { $updatedistro = true; continue; }
        if ($arg === '--dry-run') { $dryRun = true; continue; }
        if ($arg === '--jsonlog') { $jsonLog = true; continue; }
        if (str_starts_with($arg, '--profile-output=')) {
            $profileOutput = substr($arg, strlen('--profile-output='));
            $profileOutput = trim($profileOutput) === '' ? null : $profileOutput;
            continue;
        }
        $cli[] = $arg;
    }

    $specInput = $cli[0] ?? '';
    if ($specInput === '') {
        $raw = trim(@file_get_contents(VERSION_FILE) ?: '');
        if ($raw !== '' && strpos($raw, '@') !== false) {
            [$rawSpec,] = explode('@', $raw, 2);
            $raw = trim($rawSpec);
        } elseif ($raw !== '' && preg_match('/\d{2}:\d{2}$/', $raw)) {
            $raw = preg_replace('/\s*\d{2}:\d{2}$/', '', $raw);
        }
        $specInput = $raw !== '' ? $raw : '';
    }

    $spec = normaliseSpec($specInput);
    if ($spec === '') {
        $logger->msg("Source spec '{$specInput}' empty, defaulting to git/main");
        $spec = defaultSpec();
    }

    if (!preg_match('/^(git|release)([\/:])(.*)$/i', $spec, $m)) {
        $logger->msg("Unrecognised source spec '{$specInput}', forcing git/main");
        $spec = defaultSpec();
        if (!preg_match('/^(git|release)([\/:])(.*)$/i', $spec, $m)) {
            fatal("bad spec '$spec'", EXIT_PARSE);
        }
    }

    $type = strtolower($m[1]);
    $rest = $m[3];
    $repo = DEFAULT_REPO;
    $branch = 'main';
    $date = '';

    if ($type === 'release') {
        $date = ltrim($rest, ':');
        if ($date === '') {
            $json = json_decode(
                file_get_contents(
                    'https://api.github.com/repos/MagnaCapax/PMSS/releases/latest',
                    false,
                    stream_context_create(['http'=>['user_agent'=>CURL_UA]])
                ), true
            );
            $date = $json['tag_name'] ?? fatal('GitHub API tag missing', EXIT_PARSE);
        } elseif (!tagExists($date)) {
            fatal("Release tag '$date' not found", EXIT_PARSE);
        }
    } else {
        if (preg_match('/:(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?)$/', $rest, $mm)) {
            $date = $mm[1];
            $rest = substr($rest, 0, -strlen($mm[0]));
        }
        if (preg_match('#^(https?|ssh)://#', $rest)) {
            $pos = strrpos($rest, ':');
            if ($pos !== false && $pos > 8) {
                $repo   = substr($rest, 0, $pos);
                $branch = substr($rest, $pos + 1) ?: 'main';
            } else {
                $repo   = $rest;
                $branch = 'main';
            }
        } elseif (str_contains($rest, ':')) {
            [$repo, $branch] = explode(':', $rest, 2);
            $branch = $branch ?: 'main';
        } else {
            $branch = $rest;
        }
        if (!preg_match('#://|/#', $repo)) {
            $branch = $repo ?: $branch;
            $repo   = DEFAULT_REPO;
        }
    }
    $logger->msg("Source → $type repo=$repo branch=$branch date='$date'");

    $jsonLogPath = null;
    if ($jsonLog) {
        $jsonLogPath = '/var/log/pmss-update.jsonl';
        @mkdir(dirname($jsonLogPath), 0755, true);
        @touch($jsonLogPath);
        @chmod($jsonLogPath, 0640);
        putenv('PMSS_JSON_LOG='.$jsonLogPath);
    } else {
        putenv('PMSS_JSON_LOG');
    }

    if ($profileOutput === null && $jsonLogPath !== null) {
        $profileOutput = $jsonLogPath.'.profile.json';
    }
    if ($profileOutput !== null && $profileOutput !== '') {
        @mkdir(dirname($profileOutput), 0755, true);
        putenv('PMSS_PROFILE_OUTPUT='.$profileOutput);
    } else {
        putenv('PMSS_PROFILE_OUTPUT');
    }

    $tmp = tmpd();
    if ($type === 'release') {
        $tar = "$tmp/src.tgz";
        sh_retry("curl -L --fail -A ".escapeshellarg(CURL_UA)." https://api.github.com/repos/MagnaCapax/PMSS/tarball/$date -o ".escapeshellarg($tar), $verbose);
        sh("tar -xzf ".escapeshellarg($tar)." -C ".escapeshellarg($tmp)." --strip-components=1", $verbose);
    } else {
        sh_retry("git clone --depth=1 --branch ".escapeshellarg($branch).' '.escapeshellarg($repo).' '.escapeshellarg($tmp), $verbose);
        if ($date !== '') {
            $ref = escapeshellarg("$branch@{{$date}}");
            sh_retry('cd '.escapeshellarg($tmp).' && git fetch --quiet && git checkout '.$ref, $verbose);
        }
    }

    foreach (['scripts','etc','var'] as $d) {
        if (!is_dir("$tmp/$d")) fatal("missing $d", EXIT_VERIFY);
    }

    sh("cp -rp ".escapeshellarg("$tmp/scripts/")." /scripts", $verbose);
    sh("cp -rpu ".escapeshellarg("$tmp/etc")." /", $verbose);
    sh("cp -rp ".escapeshellarg("$tmp/var")." /", $verbose);
    sh("chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox", $verbose);

    $recordedDate = $date !== '' ? $date : date('Y-m-d');
    if ($type === 'release') {
        $versionSpec = 'release' . ($recordedDate !== '' ? ':' . $recordedDate : '');
    } else {
        $versionSpec = ($repo === DEFAULT_REPO)
            ? 'git/'.$branch
            : 'git/'.$repo.':'.$branch;
        if ($recordedDate !== '') {
            $versionSpec .= ':' . $recordedDate;
        }
    }

    $commit = '';
    if ($type === 'release') {
        $commit = $date ?: '';
    } else {
        $commit = trim(@shell_exec('cd '.escapeshellarg($tmp).' && git rev-parse HEAD')) ?: '';
    }

    $meta = [
        'spec_input'      => $specInput,
        'spec_normalized' => $spec,
        'type'            => $type,
        'repo'            => $repo,
        'branch'          => $branch,
        'pin'             => $date,
        'commit'          => $commit,
    ];
    if ($jsonLogPath !== null) {
        $meta['json_log'] = $jsonLogPath;
    }
    if ($profileOutput !== null) {
        $meta['profile_output'] = $profileOutput;
    }

    $versionData = pmssWriteVersionFiles($versionSpec, $meta, null, $dryRun);
    $versionLine = $versionData['line'];

    sh("rm -rf ".escapeshellarg($tmp), $verbose);

    if ($dryRun) {
        putenv('PMSS_DRY_RUN=1');
    } else {
        putenv('PMSS_DRY_RUN');
    }

    if (!$scriptonly && file_exists('/scripts/util/update-step2.php') && file_exists('/etc/hostname')) {
        require '/scripts/util/update-step2.php';
    } else {
        $logger->msg('Skipped update‑step2');
    }

    if (!$scriptonly && $updatedistro && file_exists('/scripts/util/update-distro.php')) {
        require '/scripts/util/update-distro.php';
    }

    $prefix = $dryRun ? '[DRY RUN] ' : '';
    logmsg($prefix."Update OK → $versionSpec");
    echo $prefix."Update OK → $versionSpec\n";

    return 0;
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(pmssRunUpdate($argv));
}
