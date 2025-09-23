<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';
require_once dirname(__DIR__).'/update.php';
require_once dirname(__DIR__).'/update/apt.php';
require_once dirname(__DIR__).'/update/repositories.php';
require_once dirname(__DIR__).'/update/runtime/commands.php';

class UpdateHelpersEdgeTest extends TestCase
{
    public function testSafeWriteSourcesOverwritesExisting(): void
    {
        $target = $this->makeTempSources('old');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $result = \pmssSafeWriteSources('new', 'UnitTest', null);
        $this->assertTrue($result);
        $this->assertEquals('new', file_get_contents($target));
        $this->assertEquals('old', file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesReturnsFalseWhenTargetIsDirectory(): void
    {
        $dir = sys_get_temp_dir().'/pmss-dir-'.bin2hex(random_bytes(4));
        if (file_exists($dir)) {
            if (is_dir($dir)) {
                @rmdir($dir);
            } else {
                @unlink($dir);
            }
        }
        @mkdir($dir, 0755, true);
        $this->assertTrue(is_dir($dir));
        putenv('PMSS_APT_SOURCES_PATH='.$dir);

        $result = \pmssSafeWriteSources('data', 'DirTest', null);
        $this->assertTrue($result === false);
        $this->assertTrue(file_exists($dir.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesCreatesParentDirectoriesWhenMissing(): void
    {
        $dir = sys_get_temp_dir().'/pmss-missing-'.bin2hex(random_bytes(4));
        $target = $dir.'/sources.list';
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $result = \pmssSafeWriteSources('deb test main', 'DirCreate', null);
        $this->assertTrue($result);
        $this->assertTrue(is_dir($dir));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesBackupUpdatedOnSecondWrite(): void
    {
        $target = $this->makeTempSources('first');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \pmssSafeWriteSources('second', 'UnitTest', null);
        \pmssSafeWriteSources('third', 'UnitTest', null);

        $this->assertEquals('third', file_get_contents($target));
        $this->assertEquals('second', file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesUnsupportedDistroLogsMessage(): void
    {
        $logs = [];
        \updateAptSources('arch', 1, '', [], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'Unsupported distro: arch')));
    }

    public function testUpdateAptSourcesUbuntuLogsMessage(): void
    {
        $logs = [];
        \updateAptSources('ubuntu', 22, '', [], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'Ubuntu is not supported yet')));
    }

    public function testUpdateAptSourcesUnsupportedVersionLogs(): void
    {
        $logs = [];
        \updateAptSources('debian', 19, '', [
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'Unsupported Debian version')));
    }

    public function testUpdateAptSourcesBusterWritesTemplate(): void
    {
        $target = $this->makeTempSources('initial');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $template = "deb http://mirror.example buster main\n";
        \updateAptSources('debian', 10, sha1('initial'), [
            'buster' => $template,
            'bullseye' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($template, file_get_contents($target));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesCreatesBackupOnRewrite(): void
    {
        $target = $this->makeTempSources('alpha');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $first = "deb http://mirror.example bullseye main\n";
        $second = "deb http://mirror.example bullseye contrib\n";

        \updateAptSources('debian', 11, sha1('alpha'), [
            'bullseye' => $first,
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        \updateAptSources('debian', 11, sha1($first), [
            'bullseye' => $second,
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($second, file_get_contents($target));
        $this->assertEquals($first, file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesSkipsRewriteWhenHashesMatch(): void
    {
        $template = "deb http://mirror.example bullseye main\n";
        $hash = sha1($template);
        $logs = [];

        \updateAptSources('debian', 11, $hash, [
            'bullseye' => $template,
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });

        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'already correct')));
    }

    public function testUpdateAptSourcesEmptyRepositoriesSkipsWrites(): void
    {
        $target = $this->makeTempSources('baseline');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \updateAptSources('debian', 11, sha1('baseline'), [
            'bullseye' => '',
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals('baseline', file_get_contents($target));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesLoggerReceivesMultipleEntries(): void
    {
        $template = "deb http://mirror.example trixie main\n";
        $logs = [];
        \updateAptSources('debian', 13, '', [
            'trixie' => $template,
            'bookworm' => '', 'bullseye' => '', 'buster' => '', 'jessie' => '',
        ], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue(count($logs) >= 1);
    }

    public function testPmssAptSourcesPathOverride(): void
    {
        $file = $this->makeTempSources('example');
        putenv('PMSS_APT_SOURCES_PATH='.$file);
        $this->assertEquals($file, \pmssAptSourcesPath());
        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testAptCmdPrefixesArguments(): void
    {
        $cmd = \aptCmd('install -y');
        $this->assertTrue(strpos($cmd, 'apt-get') !== false);
        $this->assertEquals('install -y', substr($cmd, -strlen('install -y')));
    }

    private function makeTempSources(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-sources-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-sources-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}
