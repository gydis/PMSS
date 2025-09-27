<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';

class RepositoryPrerequisitesTest extends TestCase
{
    public function testMediaareaBootstrapSkipsWhenKeyPresent(): void
    {
        $previousDryRun = getenv('PMSS_DRY_RUN');
        putenv('PMSS_DRY_RUN=1');

        $tempKey = tempnam(sys_get_temp_dir(), 'pmss-mediaarea-key-');
        if ($tempKey === false) {
            $tempKey = sys_get_temp_dir().'/pmss-mediaarea-key-'.bin2hex(random_bytes(4));
            touch($tempKey);
        }
        file_put_contents($tempKey, 'placeholder');

        $before = $this->listBootstrapDirs();
        putenv('PMSS_MEDIAAREA_KEY_PATHS='.$tempKey);
        try {
            \pmssEnsureMediaareaRepository();
        } finally {
            putenv('PMSS_MEDIAAREA_KEY_PATHS');
            if ($previousDryRun === false) {
                putenv('PMSS_DRY_RUN');
            } else {
                putenv('PMSS_DRY_RUN='.$previousDryRun);
            }
            @unlink($tempKey);
        }
        $after = $this->listBootstrapDirs();
        $this->assertEquals($before, $after, 'MediaArea bootstrap should skip when key already present');
    }

    private function listBootstrapDirs(): array
    {
        $pattern = sys_get_temp_dir().'/pmss-mediaarea-*';
        $entries = glob($pattern) ?: [];
        $dirs = array_values(array_filter($entries, static fn($path) => is_dir($path)));
        sort($dirs);
        return $dirs;
    }
}
