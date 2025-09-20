<?php

class users {
    const USERS_DB_FILE = '/etc/seedbox/runtime/users.json';
    const LEGACY_DB_FILE = '/etc/seedbox/runtime/users';
    const SCHEMA_VERSION = 1;

    var $users;
    
    public function __construct() {
        $this->getUsers();  // sets directly to $this->users as well, so no need to set by return value here
    }
    
    public function __deconstruct() {
        $this->saveUsers();
    }
    
    public function getUsers() {
        if (is_array($this->users)) return $this->users;
        
        $loaded = $this->loadFromJson(self::USERS_DB_FILE);
        if ($loaded === null) {
            $loaded = $this->migrateLegacyDatabase();
        }
        $this->users = $loaded ?? [];
        
        return $this->users;
    }
    
    public function addUser($username, $data) {
        if ($this->modifyUser($username, $data)) {
            $this->saveUsers();
        }
    }
    
    // Modify or add an user, works for both
    public function modifyUser($username, $data) {
        if (!$this->isValidUsername($username)) return false;
        if (!$this->validateUserPayload($data)) return false;

        $this->users[$username] = $data;
        return true;
    }
    
    public function saveUsers() {
        if (!is_array($this->users)) return false;

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

    /**
     * Read the structured JSON user database from disk.
     */
    protected function loadFromJson(string $path): ?array {
        if (!file_exists($path)) return null;
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') return [];

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

    /**
     * Import legacy serialized data and rewrite it using the JSON schema.
     */
    protected function migrateLegacyDatabase(): array
    {
        if (!file_exists(self::LEGACY_DB_FILE)) {
            return [];
        }
        $legacy = @unserialize(file_get_contents(self::LEGACY_DB_FILE));
        if (!is_array($legacy)) {
            error_log('users.php: Legacy user database corrupted.');
            return [];
        }
        $this->users = $legacy;
        $this->saveUsers();
        return $legacy;
    }

    /**
     * Stable checksum of the user list for corruption detection.
     */
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

    /**
     * Accept only safe account identifiers for persistence.
     */
    protected function isValidUsername($username): bool
    {
        return is_string($username) && preg_match('/^[a-zA-Z0-9._-]+$/', $username);
    }

    /**
     * Confirm the minimum attribute set is present for each entry.
     */
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


    public static function systemUsers() {  // Get the users from the system rather than "db"
        $filterList = array(
            'aquota.user',
            'aquota.group',
            'lost+found',
            'ftp',
            'srvadmin',
            'srvapi',
            'pmcseed',
            'pmcdn',
            'srvmgmt'
        );
        $directory = opendir('/home');
        if (!$directory) die('Fatal error with /home');

        $users = array();
        while(false !== ($file = readdir($directory))) {
            if ($file[0] == '.') continue;
            if (strpos($file, 'backup-') === 0) continue;   // skip backup directories
            
            if (!in_array($file, $filterList) &&
                is_dir( '/home/' . $file) ) $users[] = $file;
        }
        return $users;
    }
    
    
    
}
