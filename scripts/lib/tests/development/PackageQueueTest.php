<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update/runtime/commands.php';
require_once dirname(__DIR__, 3).'/update/apps/packages.php';

class PackageQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['PMSS_PACKAGE_QUEUE'] = [];
    }

    public function testQueuePackagesAddsEntries(): void
    {
        pmssQueuePackages(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], $GLOBALS['PMSS_PACKAGE_QUEUE']);
    }

    public function testQueuePackagesIgnoresDuplicates(): void
    {
        pmssQueuePackages(['foo']);
        pmssQueuePackages(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], $GLOBALS['PMSS_PACKAGE_QUEUE']);
    }

    public function testFlushPackageQueueClearsQueue(): void
    {
        pmssQueuePackages(['foo']);
        pmssFlushPackageQueue();
        $this->assertEquals([], $GLOBALS['PMSS_PACKAGE_QUEUE']);
    }
}
