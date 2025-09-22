<?php
require_once __DIR__.'/user/UserRepository.php';

class users extends UserRepository
{
    private array $users = [];

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->all();
        $this->pruneStaleEntries();
    }

    public function __destruct()
    {
        parent::persist();
    }

    public function getUsers(): array
    {
        return $this->users;
    }

    public function addUser(string $username, array $data): void
    {
        if ($this->set($username, $data)) {
            $this->users = $this->all();
            parent::persist();
        }
    }

    public function modifyUser(string $username, array $data): bool
    {
        $result = $this->set($username, $data);
        if ($result) {
            $this->users = $this->all();
        }
        return $result;
    }

    public function removeUser(string $username): void
    {
        parent::remove($username);
        $this->users = $this->all();
        parent::persist();
    }

    private function pruneStaleEntries(): void
    {
        parent::pruneStaleEntries();
        $this->users = $this->all();
    }
}
