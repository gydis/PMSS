#!/usr/bin/php
<?php
/**
 * Thin wrapper that triggers the Debian dist-upgrade helpers.
 */

require_once __DIR__.'/../lib/update/distUpgrade.php';

exit(pmssRunDistUpgrade());
