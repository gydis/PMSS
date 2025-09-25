<?php
/**
 * Repository management helpers for update orchestration.
 */

require_once __DIR__.'/apt.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssRepositoryUpdatePlan')) {
    /**
     * Build a dry-run friendly plan describing how repository configuration should evolve.
     *
     * Callers may inspect the returned structure to assert behaviour without mutating the
     * filesystem (e.g. tests verifying log flow or template selection).
     */
    function pmssRepositoryUpdatePlan(string $distroName, int $distroVersion, ?callable $logger = null): array
    {
        $log = pmssSelectLogger($logger);
        $sourcesPath = pmssAptSourcesPath();
        $currentData = @file_get_contents($sourcesPath);
        $currentHash = $currentData !== false ? sha1($currentData) : '';

        if ($distroVersion <= 0) {
            $log(sprintf('Repository version unresolved for %s; reusing existing sources', $distroName));
            return [
                'mode'          => 'reuse',
                'current_hash'  => $currentHash,
                'templates'     => [],
            ];
        }

        return [
            'mode'         => 'update',
            'current_hash' => $currentHash,
            'templates'    => [
                'jessie'   => loadRepoTemplate('jessie', $log),
                'buster'   => loadRepoTemplate('buster', $log),
                'bullseye' => loadRepoTemplate('bullseye', $log),
                'bookworm' => loadRepoTemplate('bookworm', $log),
                'trixie'   => loadRepoTemplate('trixie', $log),
            ],
        ];
    }
}

if (!function_exists('pmssRefreshRepositories')) {
    /**
     * Apply the appropriate sources.list template and refresh indices.
     */
    function pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger = null): void
    {
        $plan = pmssRepositoryUpdatePlan($distroName, $distroVersion, $logger);
        if ($plan['mode'] === 'reuse') {
            runStep('Refreshing apt package index (existing sources)', aptCmd('update'));
            return;
        }

        $log = pmssSelectLogger($logger);
        updateAptSources($distroName, (int)$distroVersion, $plan['current_hash'], $plan['templates'], $log);
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
