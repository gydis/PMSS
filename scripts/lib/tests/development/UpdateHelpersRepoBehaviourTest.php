<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/apt.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';

class UpdateHelpersRepoBehaviourTest extends TestCase
{
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

    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $target = $this->makeTempSources('unchanged');
        putenv('PMSS_APT_SOURCES_PATH='.$target);
        putenv('PMSS_DRY_RUN=1');
        $logs = [];
        \pmssRefreshRepositories('debian', 0, function (string $msg) use (&$logs): void { $logs[] = $msg; });
        $this->assertEquals('unchanged', file_get_contents($target));
        $this->assertTrue((bool)array_filter($logs, static fn($m) => str_contains($m, 'Skipping repository refresh')));
        $this->clearEnv('PMSS_APT_SOURCES_PATH');
        putenv('PMSS_DRY_RUN');
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
