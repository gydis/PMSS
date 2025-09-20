<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';

class VersionMetaTest extends TestCase
{
    public function testWritesVersionFiles(): void
    {
        $dir = $this->createTempDir();
        putenv('PMSS_VERSION_DIR='.$dir);

        $versionSpec = 'git/main:2025-01-01';
        $meta = [
            'spec_input' => 'git main',
            'spec_normalized' => 'git/main',
            'type' => 'git',
            'repo' => DEFAULT_REPO,
            'branch' => 'main',
            'pin' => '2025-01-01',
            'commit' => 'abc123',
        ];

        $result = \pmssWriteVersionFiles($versionSpec, $meta, strtotime('2025-01-02 03:04:00'), false, $dir);

        $versionFile = $dir.'/version';
        $metaFile = $dir.'/version.meta';

        $this->assertTrue(file_exists($versionFile), 'version file not written');
        $this->assertTrue(file_exists($metaFile), 'version meta file not written');

        $expectedLine = 'git/main:2025-01-01@2025-01-02 03:04';
        $this->assertEquals($expectedLine, trim(file_get_contents($versionFile)));

        $storedMeta = json_decode(file_get_contents($metaFile), true);
        $this->assertEquals('git/main:2025-01-01', $storedMeta['recorded_spec'] ?? '');
        $this->assertEquals('abc123', $storedMeta['commit'] ?? '');
        $this->assertMatches('/2025-01-02T03:04:00/', $storedMeta['timestamp'] ?? '');
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/pmss-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }
}
