<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update/repositories.php';
require_once dirname(__DIR__, 3).'/update/apt.php';

class RepoTemplateTest extends TestCase
{
    private string $tmpSources;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpSources = tempnam(sys_get_temp_dir(), 'pmss-sources-');
        putenv('PMSS_APT_SOURCES_PATH='.$this->tmpSources);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('PMSS_APT_SOURCES_PATH');
        @unlink($this->tmpSources);
    }

    public function testRefreshRepositoriesSkipsWhenTemplatesMissing(): void
    {
        $logs = [];
        pmssRefreshRepositories('debian', 99, function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'Skipping repository refresh')));
    }
}
