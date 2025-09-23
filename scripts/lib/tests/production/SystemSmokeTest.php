<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class SystemSmokeTest extends TestCase
{
    private function candidatePaths(): array
    {
        return [
            '/scripts/util/systemTest.php',
            dirname(__DIR__, 3).'/util/systemTest.php',
        ];
    }

    public function testSystemTestScriptExists(): void
    {
        $exists = false;
        foreach ($this->candidatePaths() as $path) {
            if (is_file($path)) {
                $exists = true;
                break;
            }
        }
        $this->assertTrue($exists, 'systemTest.php should exist in /scripts/util');
    }

    public function testSystemTestIsExecutable(): void
    {
        $executable = false;
        foreach ($this->candidatePaths() as $path) {
            if (is_executable($path)) {
                $executable = true;
                break;
            }
        }
        $this->assertTrue($executable, 'systemTest.php must be executable');
    }
}
