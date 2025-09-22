<?php
/**
 * Validation utilities for user metadata.
 */

class UserValidator
{
    public const REQUIRED_FIELDS = ['rtorrentRam', 'rtorrentPort', 'quota', 'quotaBurst'];

    public static function isValidUsername($username): bool
    {
        return is_string($username) && preg_match('/^[a-zA-Z0-9._-]+$/', $username) === 1;
    }

    public static function validatePayload(array $data): bool
    {
        foreach (self::REQUIRED_FIELDS as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }
        return true;
    }

    public static function normalisedPayload(array $data): array
    {
        ksort($data);
        return $data;
    }
}
