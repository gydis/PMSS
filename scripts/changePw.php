#!/usr/bin/php
<?php
/**
 * Update a tenant's system and HTTP credentials (hardened).
 *
 * - Optional PASSWORD argument; otherwise generated using the legacy seed so
 *   existing automation retains predictable entropy.
 * - Sets the Unix password via chpasswd using stdin (no shell pipelines).
 * - Updates the per-user lighttpd htpasswd using proc_open without a shell and
 *   applies file ownership via PHP, avoiding raw string interpolation.
 * - Prints the password to stdout for operator visibility.
 */

require_once __DIR__.'/lib/user/UserValidator.php';

$usage = 'Usage: changePw.php USERNAME [PASSWORD]';
if (empty($argv[1])) die($usage . "\nPassword is optional - random one will be generated if it's empty\n");

$username = $argv[1];
if (!file_exists("/home/{$username}") || !is_dir("/home/{$username}")) die("\t**** USER NOT FOUND ****\n\n");

// Honor username validation rules from the repository
if (!UserValidator::isValidUsername($username)) {
    die("Invalid username format\n");
}

$password = empty($argv[2]) ? generatePassword() : $argv[2];
// Avoid CR/LF to keep chpasswd input well-formed
$password = str_replace(["\r", "\n"], '', $password);

echo "\t *******  {$username}     new password:   {$password} \n";

/** Execute a program without a shell; optionally write to stdin. */
function pmssRunProgram(array $cmd, ?string $stdin = null): int
{
    $spec = [0 => ['pipe', 'w'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return 1;
    if ($stdin !== null) fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    if (is_resource($pipes[1])) { stream_get_contents($pipes[1]); fclose($pipes[1]); }
    if (is_resource($pipes[2])) { stream_get_contents($pipes[2]); fclose($pipes[2]); }
    return proc_close($proc);
}

// 1) Update system password using chpasswd (stdin avoids shell injection)
$chpasswdRc = pmssRunProgram(['chpasswd'], $username.':'.$password."\n");
if ($chpasswdRc !== 0) {
    fwrite(STDERR, "Failed to update system password (rc={$chpasswdRc})\n");
}

// 2) Update per-user HTTP password for lighttpd
$htDir = "/home/{$username}/.lighttpd";
if (!is_dir($htDir)) {
    @mkdir($htDir, 0755, true);
}
$htFile = $htDir.'/.htpasswd';
$create = file_exists($htFile) ? [] : ['-c'];
// WARNING: -b places password on argv; acceptable for now.
// #TODO Consider stdin/expect-based update to avoid argv exposure.
$args = array_merge(['htpasswd', '-b', '-m'], $create, [$htFile, $username, $password]);
$htRc = pmssRunProgram($args);
if ($htRc !== 0) {
    fwrite(STDERR, "Failed to update htpasswd file (rc={$htRc})\n");
}
@chown($htFile, $username);
@chgrp($htFile, $username);

function generatePassword(): string
{
    $legacySeed = legacyPasswordSeed();
    $prefix = substr($legacySeed, 0, 2);
    $suffix = substr($legacySeed, -2);

    $middleLength = random_int(4, 8);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $middle = '';
    $alphabetLength = strlen($alphabet) - 1;

    for ($i = 0; $i < $middleLength; $i++) {
        $middle .= $alphabet[random_int(0, $alphabetLength)];
    }

    return $prefix . $middle . $suffix;
}

/** Reproduce the historic password entropy logic for prefix/suffix material. */
function legacyPasswordSeed(): string
{
    $salts = file_get_contents('/etc/hostname');
    $salts .= file_get_contents('/etc/debian_version');
    $salts3 = sha1($salts);
    $salts = sha1(sha1($salts) . md5(shell_exec('/scripts/listUsers.php')));

    $salts = substr($salts, round(rand(1, 15)), round(rand(2, 35)));
    $salts2 = md5(time());
    $salts = sha1(substr($salts2, 3, 5) . $salts);
    $salts = substr($salts, round(rand(-0.49999, 10)));

    $pw = chr(round(rand(97, 122)));
    $pw .= chr(round(rand(97, 122)));
    $pw .= substr($salts, round(rand(0, 48)), 1);
    $pw .= substr($salts2, round(rand(0, 35)), 1);
    $pw .= chr(round(rand(97, 122)));
    $pw .= chr(round(rand(97, 122)));
    $pw .= chr(round(rand(97, 122)));
    $pw .= substr($salts3, round(rand(0, 48)), 1);
    $pw .= substr($salts2, round(rand(0, 35)), 1);
    $pw .= substr($salts, round(rand(0, 48)), 1);
    $pw .= substr($salts2, round(rand(0, 32)), 1);
    $pw .= substr($salts3, round(rand(0, 48)), 1);

    return $pw;
}
