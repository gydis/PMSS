<?php
namespace PMSS\Tests;

// Additional coverage for pmssWriteVersionFiles behaviour: dry-run and timestamps
require_once __DIR__.'/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';

class VersionFilesEdgeTest extends TestCase
{
    public function testDryRunDoesNotWriteFiles(): void
    {
        \date_default_timezone_set('UTC');
        $dir = $this->tmpDir();
        $spec = 'git/main:2025-03-04';
        $out = \pmssWriteVersionFiles($spec, ['commit' => 'deadbeef'], 1700000000, true, $dir);

        // Files must not appear for dry-run
        $this->assertTrue(!file_exists($dir.'/version'), 'version file should not be written in dry-run');
        $this->assertTrue(!file_exists($dir.'/version.meta'), 'version.meta should not be written in dry-run');
        $this->assertEquals('git/main:2025-03-04@2023-11-14 22:13', $out['line']);
    }

    public function testWritesToCustomBaseDirAndCreatesDir(): void
    {
        \date_default_timezone_set('UTC');
        $dir = $this->tmpDir().'/nested';
        $spec = 'git/dev:2025-01-02';
        \pmssWriteVersionFiles($spec, ['commit' => 'cafebabe'], 1735689600, false, $dir);
        $this->assertTrue(is_dir($dir), 'base dir should be created');
        $this->assertEquals('git/dev:2025-01-02@2025-01-01 00:00', trim(file_get_contents($dir.'/version')));
        $meta = json_decode(file_get_contents($dir.'/version.meta'), true);
        $this->assertEquals('git/dev:2025-01-02', $meta['recorded_spec'] ?? '');
        $this->assertEquals('cafebabe', $meta['commit'] ?? '');
    }

    public function testTimestampFormattingAndMetaDefaults(): void
    {
        \date_default_timezone_set('UTC');
        // Without timestamp in meta, function supplies ISO8601 timestamp and line timestamp
        $dir = $this->tmpDir();
        $spec = 'git/main:2024-12-31';
        $ts = 1735600000; // fixed timestamp
        $out = \pmssWriteVersionFiles($spec, [], $ts, false, $dir);
        $expected = 'git/main:2024-12-31@'.date('Y-m-d H:i', $ts);
        $this->assertEquals($expected, $out['line']);
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $out['meta']['timestamp'] ?? '');
    }

    public function testRespectsProvidedMetaTimestamp(): void
    {
        $dir = $this->tmpDir();
        $spec = 'git/feat:2025-05-05';
        $meta = ['timestamp' => '2025-05-05T05:05:05Z'];
        $out = \pmssWriteVersionFiles($spec, $meta, 1746420000, false, $dir);
        $stored = json_decode(file_get_contents($dir.'/version.meta'), true);
        $this->assertEquals('2025-05-05T05:05:05Z', $stored['timestamp'] ?? '');
        $this->assertEquals($stored['timestamp'], $out['meta']['timestamp']);
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/pmss-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }
}
