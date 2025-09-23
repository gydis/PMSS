<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';
require_once dirname(__DIR__).'/update.php';
require_once dirname(__DIR__).'/update/distro.php';

class UpdateHelpersEnvTest extends TestCase
{
    public function testOsReleasePathDefaultsToEtc(): void
    {
        $this->assertEquals('/etc/os-release', \pmssOsReleasePath());
    }

    public function testOsReleasePathOverrideTakesPrecedence(): void
    {
        $file = $this->tempFile('override', 'ID=custom');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        $this->assertEquals($file, \pmssOsReleasePath());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetOsReleaseDataCachesPerPath(): void
    {
        $file = $this->tempFile('cache', "ID=test\nVERSION_ID=1\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $first = \getOsReleaseData();
        file_put_contents($file, "ID=test\nVERSION_ID=2\n");
        $second = \getOsReleaseData();
        $this->assertEquals($first, $second);
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testResetOsReleaseCacheReloadsData(): void
    {
        $file = $this->tempFile('reload', "ID=test\nVERSION_ID=3\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        \getOsReleaseData();
        file_put_contents($file, "ID=test\nVERSION_ID=4\n");
        \pmssResetOsReleaseCache();
        $data = \getOsReleaseData();
        $this->assertEquals('4', $data['VERSION_ID']);
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroVersionStripsSuffix(): void
    {
        $file = $this->tempFile('version', "ID=debian\nVERSION_ID=\"12 (bookworm)\"\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('12', \getDistroVersion());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroVersionReturnsRawWhenNonNumeric(): void
    {
        $file = $this->tempFile('version', "ID=debian\nVERSION_ID=sid\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('sid', \getDistroVersion());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroNameEmptyWhenMissing(): void
    {
        $file = $this->tempFile('noname', "VERSION_ID=11\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('', \getDistroName());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroCodenameLowercasesAndTrims(): void
    {
        $file = $this->tempFile('codename', "ID=debian\nVERSION_CODENAME=  BULLSEYE  \n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('bullseye', \getDistroCodename());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroCodenameEmptyWhenNotPresent(): void
    {
        $file = $this->tempFile('nocodename', "ID=debian\nVERSION_ID=12\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('', \getDistroCodename());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testPmssVersionFromCodenameUnknownReturnsZero(): void
    {
        $this->assertEquals(0, \pmssVersionFromCodename('unknown-planet'));
    }

    public function testGetPmssVersionTrimsWhitespace(): void
    {
        $file = $this->tempFile('version-file', "git/main:2025-01-01\n\n");
        $this->assertEquals('git/main:2025-01-01', \getPmssVersion($file));
    }

    public function testGetPmssVersionReturnsUnknownForEmptyFile(): void
    {
        $file = $this->tempFile('empty-version', '');
        $this->assertEquals('unknown', \getPmssVersion($file));
    }

    public function testResetCacheLeavesOtherPathsUntouched(): void
    {
        $first = $this->tempFile('first', "ID=alpha\nVERSION_ID=1\n");
        $second = $this->tempFile('second', "ID=beta\nVERSION_ID=2\n");

        putenv('PMSS_OS_RELEASE_PATH='.$first);
        \pmssResetOsReleaseCache();
        $firstData = \getOsReleaseData();
        $this->assertEquals('alpha', $firstData['ID']);

        putenv('PMSS_OS_RELEASE_PATH='.$second);
        \pmssResetOsReleaseCache();
        $secondData = \getOsReleaseData();
        $this->assertEquals('beta', $secondData['ID']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetOsReleaseDataHandlesMissingFile(): void
    {
        putenv('PMSS_OS_RELEASE_PATH=/nonexistent/os-release');
        \pmssResetOsReleaseCache();
        $data = \getOsReleaseData();
        $this->assertTrue(is_array($data));
        $this->assertEquals([], $data);
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testOsReleasePathOverrideClearsAfterUnset(): void
    {
        $file = $this->tempFile('override', 'ID=temp');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals($file, \pmssOsReleasePath());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
        $this->assertEquals('/etc/os-release', \pmssOsReleasePath());
    }

    private function tempFile(string $prefix, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-env-'.$prefix.'-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-env-'.$prefix.'-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}
