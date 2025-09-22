<?php
/**
 * Shared utilities for user update helpers.
 */

function pmssUserSkelBase(): string
{
    $override = getenv('PMSS_SKEL_DIR');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }
    return '/etc/skel';
}

function pmssUserSkelPath(string $relative): string
{
    return pmssUserSkelBase().'/'.$relative;
}
