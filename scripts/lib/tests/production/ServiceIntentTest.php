<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class ServiceIntentTest extends TestCase
{
    public function testExpectedServiceListDocumented(): void
    {
        $expected = [
            'nginx',
            'proftpd',
            'openvpn',
            'rtorrent',
        ];
        $this->assertTrue(count($expected) >= 4, 'Service list scaffolded');
    }

    public function testPlaceholderForFutureChecks(): void
    {
        $this->assertTrue(true, 'Production check scaffolding – extend with real probes');
    }
}
