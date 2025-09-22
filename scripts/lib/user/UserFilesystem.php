<?php
/**
 * Helpers for enumerating users from filesystem and passwd database.
 */

class UserFilesystem
{
    public static function listHomeUsers(): array
    {
        $users = [];
        $filterList = self::homeFilterList();

        $homeDir = '/home';
        if ($directory = @opendir($homeDir)) {
            while (false !== ($entry = readdir($directory))) {
                if ($entry === '.' || $entry === '..' || strpos($entry, 'backup-') === 0) {
                    continue;
                }
                if (in_array($entry, $filterList, true)) {
                    continue;
                }
                $path = $homeDir.'/'.$entry;
                if (is_dir($path)) {
                    $users[$entry] = true;
                }
            }
            closedir($directory);
        }

        foreach (self::passwdUsers() as $user) {
            $users[$user] = true;
        }

        $names = array_keys($users);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    private static function passwdUsers(): array
    {
        $names = [];
        $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $names;
        }
        $filterList = self::homeFilterList();
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) < 7) {
                continue;
            }
            $name = $parts[0];
            $home = $parts[5];
            if (strpos($home, '/home/') !== 0) {
                continue;
            }
            if (in_array($name, $filterList, true)) {
                continue;
            }
            $names[$name] = true;
        }
        return array_keys($names);
    }

    private static function homeFilterList(): array
    {
        return ['aquota.user','aquota.group','lost+found','ftp','srvadmin','srvapi','pmcseed','pmcdn','srvmgmt'];
    }
}
