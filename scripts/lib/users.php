<?php

class users
{
    const USERS_DB_FILE = '/etc/seedbox/runtime/users.json';
    const SCHEMA_VERSION = 1;

    private array $users = [];

    public function __construct()
    {
        $this->getUsers();
    }

    public function __destruct()
    {
        $this->saveUsers();
    }

    public function getUsers(): array
    {
        if (!empty($this->users)) {
            return $this->users;
        }

        $loaded = $this->loadFromJson(self::USERS_DB_FILE);
        $this->users = $loaded ?? [];
        $this->pruneStaleEntries();
        return $this->users;
    }

    public function addUser(string $username, array $data): void
    {
        if ($this->modifyUser($username, $data)) {
            $this->saveUsers();
        }
    }

    public function modifyUser(string $username, array $data): bool
    {
        if (!$this->isValidUsername($username)) {
            return false;
        }
        if (!$this->validateUserPayload($data)) {
            return false;
        }

        $this->users[$username] = $data;
        return true;
    }

    public function removeUser(string $username): void
    {
        if (isset($this->users[$username])) {
            unset($this->users[$username]);
            $this->saveUsers();
        }
    }

    public function saveUsers(): bool
    {
        if (!is_array($this->users)) {
            $this->users = [];
        }

        $payload = [
            'schema'       => self::SCHEMA_VERSION,
            'generated_at' => date('c'),
            'users'        => $this->users,
        ];
        $payload['checksum'] = $this->checksum($payload['users']);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            error_log('users.php: Failed to encode user database to JSON.');
            return false;
        }
        $dir = dirname(self::USERS_DB_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (@file_put_contents(self::USERS_DB_FILE, $encoded) === false) {
            error_log('users.php: Failed to write user database file.');
            return false;
        }
        @chmod(self::USERS_DB_FILE, 0640);
        return true;
    }

    protected function loadFromJson(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('users.php: Invalid JSON user database.');
            return [];
        }

        if (!isset($data['users']) || !is_array($data['users'])) {
            error_log('users.php: JSON user database missing users key.');
            return [];
        }

        if (isset($data['checksum'])) {
            $expected = $this->checksum($data['users']);
            if (!hash_equals($expected, (string)$data['checksum'])) {
                error_log('users.php: User database checksum mismatch.');
            }
        }

        return $data['users'];
    }

    protected function pruneStaleEntries(): void
    {
        if (empty($this->users)) {
            return;
        }
        $homeUsers = self::listHomeUsers();
        $changed = false;
        foreach (array_keys($this->users) as $username) {
            if (!in_array($username, $homeUsers, true)) {
                unset($this->users[$username]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveUsers();
        }
    }

    protected function checksum(array $users): string
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

    protected function isValidUsername($username): bool
    {
        return is_string($username) && preg_match('/^[a-zA-Z0-9._-]+$/', $username);
    }

    protected function validateUserPayload($data): bool
    {
        if (!is_array($data)) return false;
        $required = ['rtorrentRam', 'rtorrentPort', 'quota', 'quotaBurst'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }
        return true;
    }

    public static function listHomeUsers(): array
    {
        $users = [];
        $filterList = self::homeFilterList();

        if ($directory = @opendir('/home')) {
            while (false !== ($entry = readdir($directory))) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (strpos($entry, 'backup-') === 0) {
                    continue;
                }
                if (in_array($entry, $filterList, true)) {
                    continue;
                }
                $path = '/home/'.$entry;
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

    protected static function passwdUsers(): array
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
            if (!isset($names[$name])) {
                $names[$name] = true;
            }
        }
        return array_keys($names);
    }

    protected static function homeFilterList(): array
    {
        return [
            'aquota.user',
            'aquota.group',
            'lost+found',
            'ftp',
            'srvadmin',
            'srvapi',
            'pmcseed',
            'pmcdn',
            'srvmgmt',
        ];
    }
}
