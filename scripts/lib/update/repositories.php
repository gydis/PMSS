<?php
/**
 * Repository management helpers for update orchestration.
 */

require_once __DIR__.'/apt.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssRefreshRepositories')) {
    /**
     * Apply the appropriate sources.list template and refresh indices.
     */
    function pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        $sourcesPath = '/etc/apt/sources.list';
        $currentData = @file_get_contents($sourcesPath);
        $currentHash = $currentData !== false ? sha1($currentData) : '';

        $templates = [
            'jessie'   => loadRepoTemplate('jessie', $log),
            'buster'   => loadRepoTemplate('buster', $log),
            'bullseye' => loadRepoTemplate('bullseye', $log),
            'bookworm' => loadRepoTemplate('bookworm', $log),
            'trixie'   => loadRepoTemplate('trixie', $log),
        ];

        updateAptSources($distroName, (int)$distroVersion, $currentHash, $templates, $log);
        runStep('Refreshing apt package index', aptCmd('update'));
    }
}

if (!function_exists('pmssAutoremovePackages')) {
    /**
     * Remove packages that are no longer required.
     */
    function pmssAutoremovePackages(): void
    {
        runStep('Removing packages no longer required', aptCmd('autoremove -y'));
    }
}
