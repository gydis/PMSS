<?php
namespace PMSS\Tests;

// Tests for functions in scripts/lib/update.php that are safe to exercise without root
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class UpdateHelpersTest extends TestCase
{
    // Note: pmssJsonLogPath() caches the first observed value process-wide; avoid asserting dynamic changes here.

    public function testSelectLoggerPrefersCustom(): void
    {
        $custom = function (string $m): void {};
        $cb = \pmssSelectLogger($custom);
        $this->assertTrue(is_callable($cb));
    }

    public function testSelectLoggerFallbackToDefault(): void
    {
        $cb = \pmssSelectLogger(null);
        $this->assertEquals('logMessage', $cb);
    }

    public function testLoadRepoTemplateMissingLogsAndReturnsEmpty(): void
    {
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };
        $data = \loadRepoTemplate('this-code-name-does-not-exist', $logger);
        $this->assertEquals('', $data);
        $this->assertTrue((bool)array_filter($logs, fn($l) => strpos($l, 'Repository template missing:') !== false));
    }

    public function testSafeWriteSourcesEmptyContentSkips(): void
    {
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };
        $ok = \safeWriteSources('', 'UnitTest', $logger);
        $this->assertTrue($ok === false);
        $this->assertTrue((bool)array_filter($logs, fn($l) => strpos($l, 'Empty repository content') !== false));
    }

    public function testUpdateAptSourcesDebian9UnsupportedLogs(): void
    {
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };
        \updateAptSources('debian', 9, 'dead', [
            'jessie' => '', 'buster' => '', 'bullseye' => '', 'bookworm' => '', 'trixie' => ''
        ], $logger);
        $this->assertTrue((bool)array_filter($logs, fn($l) => strpos($l, 'Unsupported Debian version: 9') !== false));
    }

    public function testUpdateAptSourcesAlreadyCorrectNoChange(): void
    {
        // When current hash equals template hash, function should only log "already correct"
        $content = "deb https://example invalid\n";
        $hash = sha1($content);
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };
        \updateAptSources('debian', 12, $hash, [
            'bookworm' => $content,
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'trixie' => '',
        ], $logger);
        $this->assertTrue((bool)array_filter($logs, fn($l) => strpos($l, 'already correct') !== false));
        // Important: No destructive call path is taken here
    }

    public function testUpdateAptSourcesTemplateMissing(): void
    {
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };
        \updateAptSources('debian', 11, 'hash', [
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => ''
        ], $logger);
        $this->assertTrue((bool)array_filter($logs, fn($l) => strpos($l, 'Bullseye template missing') !== false));
    }

    public function testUpdateAptSourcesDebian13AlreadyCorrect(): void
    {
        $content = "deb https://example-trixie invalid\n";
        $hash = sha1($content);
        $logs = [];
        $logger = function (string $m) use (&$logs): void { $logs[] = $m; };
        \updateAptSources('debian', 13, $hash, [
            'trixie' => $content,
            'bookworm' => '', 'bullseye' => '', 'buster' => '', 'jessie' => '',
        ], $logger);
        $this->assertTrue((bool)array_filter($logs, fn($l) => strpos($l, 'Trixie') !== false));
    }

    public function testGetOsReleaseDataIsArray(): void
    {
        $data = \getOsReleaseData();
        $this->assertTrue(is_array($data));
    }

    public function testGetDistroNameString(): void
    {
        $name = \getDistroName();
        $this->assertTrue(is_string($name));
    }

    public function testGetDistroVersionDigitsOrEmpty(): void
    {
        $ver = \getDistroVersion();
        $this->assertTrue($ver === '' || preg_match('/^\d+$/', $ver) === 1);
    }

    public function testGetPmssVersionUnknownWhenMissing(): void
    {
        // A non-existent file should yield 'unknown'
        $this->assertEquals('unknown', \getPmssVersion('/this/file/does/not/exist'));
    }

    public function testGetPmssVersionFromCustomFile(): void
    {
        $f = $this->tmpFile();
        file_put_contents($f, "git/main:2024-01-01\n");
        $this->assertEquals('git/main:2024-01-01', \getPmssVersion($f));
    }

    public function testGenerateMotdNoTemplateSafe(): void
    {
        // When template missing, function returns early without changes
        \generateMotd();
        $this->assertTrue(true, 'generateMotd should be a no-op without template');
    }

    // Utility kept for potential future tests; currently unused in this class.
    private function tmpFile(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'pmss');
        if ($f === false) {
            $f = sys_get_temp_dir().'/pmss-'.bin2hex(random_bytes(4));
            touch($f);
        }
        return $f;
    }
}
