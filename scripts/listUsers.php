#!/usr/bin/php
<?php
require_once __DIR__.'/lib/users.php';

/**
 * Fallback to enumerating system accounts when the runtime DB is empty.
 */
function fallbackSystemUsers(): never
{
    passthru('/scripts/systemUsers.php');
    exit(0);
}

$db = new users();
$records = $db->getUsers();
if (empty($records)) {
    fallbackSystemUsers();
}

$usernames = array_keys($records);
sort($usernames, SORT_NATURAL);

foreach ($usernames as $name) {
    echo $name . "\n";
}
