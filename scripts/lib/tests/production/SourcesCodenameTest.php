<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class SourcesCodenameTest extends TestCase
{
    public function testSourcesListContainsCodename(): void
    {
        $os = parse_ini_file('/etc/os-release');
        $codename = strtolower(trim($os['VERSION_CODENAME'] ?? ''));
        if ($codename === '' || !is_file('/etc/apt/sources.list')) {
            $this->assertTrue(true, 'Skipping codename check outside full system');
            return;
        }
        $contents = strtolower((string)file_get_contents('/etc/apt/sources.list'));
        $this->assertTrue(strpos($contents, $codename) !== false, 'sources.list should reference codename');
    }
}
