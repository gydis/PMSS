<?php
/**
 * Provides checksum generation for user datasets.
 */

class UserChecksum
{
    public static function checksum(array $users): string
    {
        ksort($users);
        foreach ($users as &$details) {
            if (is_array($details)) {
                ksort($details);
            }
        }
        unset($details);
        return sha1(json_encode($users, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
