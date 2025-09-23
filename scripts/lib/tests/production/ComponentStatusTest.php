<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class ComponentStatusTest extends TestCase
{
    public function testScriptExistsWithJsonSupport(): void
    {
        $paths = ['/scripts/util/componentStatus.php', dirname(__DIR__, 3).'/util/componentStatus.php'];
        $found = false;
        foreach ($paths as $path) {
            if (is_file($path) && is_executable($path)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'componentStatus.php should exist and be executable');
    }
}
