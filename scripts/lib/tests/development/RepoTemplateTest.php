<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class RepoTemplateTest extends TestCase
{
    private string $tmpSources;

    protected function setUp(): void
    {
        $this->tmpSources = tempnam(sys_get_temp_dir(), 'pmss-sources-');
        putenv('PMSS_APT_SOURCES_PATH='.$this->tmpSources);
    }

    protected function tearDown(): void
    {
        putenv('PMSS_APT_SOURCES_PATH');
        @unlink($this->tmpSources);
    }

    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $logs = [];
        pmssRefreshRepositories('debian', 0, function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'Skipping repository refresh')));
    }
}
