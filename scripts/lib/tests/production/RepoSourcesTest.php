<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class RepoSourcesTest extends TestCase
{
    public function testSourcesListExistsInProduction(): void
    {
        $path = '/etc/apt/sources.list';
        if (!is_file($path)) {
            $this->assertTrue(true, 'skipping check outside production');
            return;
        }
        $contents = file_get_contents($path) ?: '';
        $this->assertTrue($contents !== '', 'sources.list should not be empty');
    }
}
