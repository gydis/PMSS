#!/usr/bin/php
<?php
/**
 * Nightly cleanup for the PMSS user database.
 */

require_once __DIR__.'/../lib/users.php';

$db = new users();
$before = count($db->getUsers());
$removed = $db->prune();
$after = count($db->getUsers());

$timestamp = date('c');
if ($removed > 0) {
    echo "{$timestamp}: removed {$removed} stale user(s); {$after} remain.\n";
} else {
    echo "{$timestamp}: database already in sync.\n";
}
