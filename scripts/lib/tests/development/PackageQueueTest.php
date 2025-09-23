<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/packages/helpers.php';

class PackageQueueTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['PMSS_PACKAGE_QUEUE'] = [];
    }

    public function testQueuePackagesAddsEntries(): void
    {
        pmssQueuePackages(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }

    public function testQueuePackagesIgnoresDuplicates(): void
    {
        pmssQueuePackages(['foo']);
        pmssQueuePackages(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }

    public function testQueuePackagesMaintainsOrder(): void
    {
        pmssQueuePackages(['a']);
        pmssQueuePackages(['c', 'b']);
        $this->assertEquals(['a', 'c', 'b'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }

    public function testFlushPackageQueueClearsQueue(): void
    {
        pmssQueuePackages(['foo']);
        putenv('PMSS_DRY_RUN=1');
        pmssFlushPackageQueue();
        putenv('PMSS_DRY_RUN');
        $this->assertEquals([], $GLOBALS['PMSS_PACKAGE_QUEUE']);
    }
}
