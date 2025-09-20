<?php
namespace PMSS\Tests;

// Sanity tests for scripts/lib/networkInfo.php helpers
require_once __DIR__.'/TestCase.php';
require_once dirname(__DIR__).'/networkInfo.php';

class NetworkInfoTest extends TestCase
{
    public function testDetectPrimaryInterfaceReturnsString(): void
    {
        $iface = \detectPrimaryInterface();
        $this->assertTrue(is_string($iface));
        $this->assertTrue($iface !== '');
    }

    public function testGetLinkSpeedReturnsInt(): void
    {
        $iface = \detectPrimaryInterface();
        $speed = \getLinkSpeed($iface);
        $this->assertTrue(is_int($speed));
        $this->assertTrue($speed > 0);
    }
}

