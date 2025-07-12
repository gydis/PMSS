#!/usr/bin/php
<?php
/**
 * ──────────────────────────────────────────────────────────────────────────────
 *  PMSS STATIC UPDATER  –  CLI SYNTAX & EXAMPLES  (use --help to print)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  update.php  [<source‑spec>]  [--scriptonly]  [--verbose]  [--help]
 *
 *  <source‑spec> (defaults to /etc/seedbox/config/version)
 *    release                      – latest GitHub release
 *    release:2025‑07‑12           – explicit tag
 *
 *    git/main                     – branch “main” from default repo
 *    git/dev:2025‑07‑12           – branch “dev” pinned to 12 Jul 2025
 *
 *    git/https://host/repo.git            → branch “main”
 *    git/https://host/repo.git:staging    → branch “staging”
 *    git/ssh://git@host/repo.git:prod:2025‑07‑12
 *
 *  Options
 *    --scriptonly   skip /scripts/util/update‑step2.php
 *    --verbose      echo every shell command
 *    --help         show this banner then exit
 *
 *  Exit codes
 *    0   success
 *   11   bad source‑spec / parse error
 *   12   download/clone failure
 *   13   verification failure (missing scripts/etc/var)
 *   14   deployment failure (rsync / chmod phase)
 * ──────────────────────────────────────────────────────────────────────────────
 */
if (in_array('--help', $argv, true)) {
    $fh = new SplFileObject(__FILE__);
    while (!$fh->eof()) {
        $line = $fh->fgets();
        echo $line;
        if (str_contains($line, '─────────────────────────────────────────────────────────────────────────────')) {
            // print until bottom border
            break;
        }
    }
    exit(0);
}

declare(strict_types=1);

/* ───────────── constants ───────────── */
const VERSION_FILE   = '/etc/seedbox/config/version';
const LOG_FILE       = '/var/log/pmss-update.log';
const FALLBACK_LOG   = '/tmp/pmss-update.log';          // if /var/log not writable
const REQUIRED_DIRS  = ['scripts', 'etc', 'var'];
const DEFAULT_REPO   = 'https://github.com/MagnaCapax/PMSS';
const CURL_UA        = 'PMSS-Updater (+https://pulsedmedia.com)';
const EXIT_BAD_PARSE = 11;
const EXIT_DOWNLOAD  = 12;
const EXIT_VERIFY    = 13;
const EXIT_DEPLOY    = 14;
const RETRIES        = 3;

/* ───────────── environment ───────────── */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

/* ───────────── runtime flags ───────────── */
$verbose    = in_array('--verbose',   $argv, true);
$scriptonly = in_array('--scriptonly', $argv, true);

/* ───────────── logging helpers ───────────── */
function logmsg(string $msg): void
{
    $ts = date('[Y-m-d H:i:s] ');
    $ok = @file_put_contents(LOG_FILE, $ts . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($ok === false) {                                // fallback if /var/log unwritable
        @file_put_contents(FALLBACK_LOG, $ts . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    fwrite(STDERR, $msg . PHP_EOL);
}
function fatal(string $msg, int $code): never
{
    logmsg("[FATAL] $msg");
    exit($code);
}

/* ───────────── shell helpers ───────────── */
function sh(string $cmd, bool $verbose = false): void
{
    if ($verbose) fwrite(STDERR, "[CMD] $cmd\n");
    passthru($cmd, $rc);
    if ($rc !== 0) fatal("Command failed: $cmd", EXIT_DOWNLOAD);
}
function sh_retry(string $cmd, bool $verbose = false, int $retries = RETRIES): void
{
    $attempt = 0;
    while (true) {
        $attempt++;
        if ($verbose) fwrite(STDERR, "[CMD] $cmd (try $attempt)\n");
        passthru($cmd, $rc);
        if ($rc === 0) return;
        if ($attempt >= $retries) fatal("Failed after $retries retries: $cmd", EXIT_DOWNLOAD);
        sleep($attempt);                      // 1‑2‑3 back‑off
    }
}

/* ───────────── misc helpers ───────────── */
function tmpPath(): string
{
    $dir = sys_get_temp_dir() . '/PMSS_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) fatal("Cannot create tmp dir $dir", EXIT_DOWNLOAD);
    return $dir;
}
function curlJson(string $url): array
{
    $ctx = stream_context_create(['http' => ['user_agent' => CURL_UA]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) fatal("curlJson failed for $url", EXIT_DOWNLOAD);
    return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
}
function tagExists(string $tag): bool               // treat 2xx or 3xx as exists
{
    $url = 'https://api.github.com/repos/MagnaCapax/PMSS/releases/tags/' . rawurlencode($tag);
    $ctx = stream_context_create(['http' => ['user_agent' => CURL_UA]]);
    $hdr = @get_headers($url, false, $ctx);
    return $hdr && preg_match('/^HTTP\/.* (2|3)\d\d/', $hdr[0]);
}

/* ───────────── CLI parsing ───────────── */
$cliFiltered = array_values(array_diff($argv, ['--scriptonly', '--verbose', '--help']));
array_shift($cliFiltered);          // remove script path
$spec = $cliFiltered[0] ?? trim(@file_get_contents(VERSION_FILE) ?: 'release');

if (!preg_match('/^(git|release)([\/:])(.*)$/i', $spec, $m))
    fatal("Invalid spec '$spec'", EXIT_BAD_PARSE);

$type  = strtolower($m[1]);
$rest  = $m[3];
$repo   = DEFAULT_REPO;
$branch = 'main';
$date   = '';

if ($type === 'release') {
    $date = ltrim($rest, ':');
    if ($date === '') {
        $latest = curlJson('https://api.github.com/repos/MagnaCapax/PMSS/releases/latest');
        $date   = $latest['tag_name'] ?? fatal('GitHub API missing tag_name', EXIT_DOWNLOAD);
    } elseif (!tagExists($date)) {
        fatal("Release tag '$date' does not exist", EXIT_DOWNLOAD);
    }
} else {  /* ───────────── git parsing ───────────── */
    // strip trailing date (if present)
    $lastColon = strrpos($rest, ':');
    if ($lastColon !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', substr($rest, $lastColon + 1))) {
        $date = substr($rest, $lastColon + 1);
        $rest = substr($rest, 0, $lastColon);
    }

    if (preg_match('#^(https?|ssh)://#', $rest)) {         // repo with scheme
        $pos = strrpos($rest, ':');
        if ($pos !== false && $pos > 8) {
            $repo   = substr($rest, 0, $pos);
            $branch = substr($rest, $pos + 1) ?: 'main';
        } else {
            $repo   = $rest;
            $branch = 'main';
        }
    } elseif (str_contains($rest, ':')) {                  // repo:branch (no scheme)
        [$repo, $branch] = explode(':', $rest, 2);
        $branch = $branch ?: 'main';
    } else {                                               // simple branch
        $branch = $rest;
    }
}

/* ───────────── acquire source ───────────── */
$tmp = tmpPath();

if ($type === 'release') {
    $tgz = "$tmp/src.tgz";
    $url = "https://api.github.com/repos/MagnaCapax/PMSS/tarball/$date";
    sh_retry(sprintf(
        'curl -L --fail -A %s %s -o %s',
        escapeshellarg(CURL_UA), escapeshellarg($url), escapeshellarg($tgz)
    ), $verbose);
    sh_retry(sprintf(
        'tar -xzf %s -C %s --strip-components=1',
        escapeshellarg($tgz), escapeshellarg($tmp)
    ), $verbose);
} else { /* git */
    $safeRepo   = escapeshellarg($repo);
    $safeBranch = escapeshellarg($branch);
    sh_retry("git clone --depth=1 --branch $safeBranch $safeRepo " . escapeshellarg($tmp), $verbose);

    if ($date !== '') {                                   // date‑pin checkout
        $ref = escapeshellarg("$branch@{$date}");
        sh_retry('cd ' . escapeshellarg($tmp) . " && git fetch --quiet && git checkout $ref", $verbose);
    }
}

/* ───────────── sanity‑check ───────────── */
foreach (REQUIRED_DIRS as $d) {
    if (!is_dir("$tmp/$d")) fatal("Downloaded tree missing '$d'", EXIT_VERIFY);
}

/* ───────────── deploy ───────────── */
foreach (REQUIRED_DIRS as $d) {
    $dst = "/$d";
    sh_retry(sprintf(
        'rsync -a --delete --inplace %s/ %s/',
        escapeshellarg("$tmp/$d"), escapeshellarg($dst)
    ), $verbose);
}
sh('chmod -R o-rwx /scripts /root /etc/skel /etc/seedbox', $verbose);

/* ───────────── record new version ───────────── */
$newLine = ($type === 'release')
         ? "release:$date"
         : "git:$repo:$branch" . ($date ? ":$date" : '');
file_put_contents(VERSION_FILE, $newLine . PHP_EOL);

/* ───────────── cleanup ───────────── */
sh('rm -rf ' . escapeshellarg($tmp), $verbose);

/* ───────────── dynamic phase‑2 ───────────── */
if (!$scriptonly && file_exists('/scripts/util/update-step2.php')) {
    require '/scripts/util/update-step2.php';
}

logmsg("Update complete → $newLine");
echo "Update complete → $newLine\n";
