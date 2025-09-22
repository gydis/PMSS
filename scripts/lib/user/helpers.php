<?php
/**
 * Shared helpers for user configuration routines.
 */

require_once __DIR__.'/../runtime.php';

/**
 * Run a shell command with optional logging while keeping failures non-fatal.
 */
function userRunCommand(string $description, string $command): int
{
    if ($description !== '') {
        echo $description."\n";
    }
    return runCommand($command, false, 'logMessage');
}
