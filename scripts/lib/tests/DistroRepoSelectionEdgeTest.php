<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';
require_once dirname(__DIR__).'/update.php';
require_once dirname(__DIR__).'/update/distro.php';

class DistroRepoSelectionEdgeTest extends TestCase
{
    public function testDetectDistroLowercasesId(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=Debian',
            'VERSION_ID="11"',
            'VERSION_CODENAME=BULLSEYE',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('debian', $info['name']);
        $this->assertEquals(11, $info['version']);
        $this->assertEquals('bullseye', $info['codename']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroNormalisesUppercaseCodename(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=12',
            'VERSION_CODENAME=BOOKWORM',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('bookworm', $info['codename']);
        $this->assertEquals(12, $info['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroUnknownCodenameKeepsVersion(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID="77"',
            'VERSION_CODENAME=aurora',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals(77, $info['version']);
        $this->assertEquals('aurora', $info['codename']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroWhitespaceInCodename(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=13',
            'VERSION_CODENAME="  trixie  "',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('trixie', $info['codename']);
        $this->assertEquals(13, $info['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroResetCacheSwitchesFiles(): void
    {
        $first = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=11',
            'VERSION_CODENAME=bullseye',
        ]);
        $second = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=12',
            'VERSION_CODENAME=bookworm',
        ]);

        putenv('PMSS_OS_RELEASE_PATH='.$first);
        \pmssResetOsReleaseCache();
        $firstInfo = \pmssDetectDistro();
        $this->assertEquals(11, $firstInfo['version']);

        putenv('PMSS_OS_RELEASE_PATH='.$second);
        \pmssResetOsReleaseCache();
        $secondInfo = \pmssDetectDistro();
        $this->assertEquals(12, $secondInfo['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testUpdateAptSourcesCreatesParentDirectory(): void
    {
        $dir = sys_get_temp_dir().'/pmss-apt-'.bin2hex(random_bytes(4));
        $target = $dir.'/sources.list';
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $template = "deb http://mirror.example bullseye main\n";
        $logs = [];
        $logger = function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        };

        \updateAptSources('debian', 11, '', [
            'bullseye' => $template,
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], $logger);

        $this->assertTrue(is_dir($dir));
        $this->assertEquals($template, file_get_contents($target));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesLeavesFileWhenTemplateEmpty(): void
    {
        $initial = "deb http://existing bullseye main\n";
        $target = $this->tempSources($initial);
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \updateAptSources('debian', 11, sha1($initial), [
            'bullseye' => '',
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($initial, file_get_contents($target));
        $this->assertTrue(!file_exists($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesWithoutExistingFileSkipsBackup(): void
    {
        $target = sys_get_temp_dir().'/pmss-apt-'.bin2hex(random_bytes(4));
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $template = "deb http://mirror.example bookworm main\n";
        \updateAptSources('debian', 12, '', [
            'bookworm' => $template,
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($template, file_get_contents($target));
        $this->assertTrue(!file_exists($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testPmssVersionFromCodenameCoversStretch(): void
    {
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
    }

    private function writeOsRelease(array $lines): string
    {
        return $this->tempFile('os-release', implode("\n", $lines)."\n");
    }

    private function tempSources(string $content): string
    {
        return $this->tempFile('sources', $content);
    }

    private function tempFile(string $prefix, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-'.$prefix.'-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-'.$prefix.'-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}
