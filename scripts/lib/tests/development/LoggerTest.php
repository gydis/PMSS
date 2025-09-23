<?php
namespace PMSS\Tests;

// Tests for scripts/lib/logger.php: Logger and logmsg wrapper
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/logger.php';

class LoggerTest extends TestCase
{
    public function testLoggerWritesToCustomDir(): void
    {
        $dir = sys_get_temp_dir().'/pmss-logs-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $logger = new \Logger(__FILE__, $dir);
        $logger->msg('hello custom');
        $base = basename(__FILE__, '.php');
        $path = rtrim($dir, '/').'/'.$base.'.log';
        $this->assertTrue(file_exists($path));
        $this->assertTrue(strpos(file_get_contents($path), 'hello custom') !== false);
    }

    public function testLoggerFallsBackToTmp(): void
    {
        // Attempt writing to a path we cannot write; should fall back to /tmp/<base>.log
        $logger = new \Logger('/no/perm/logger-fallback-test.php', '/');
        $logger->msg('fallback line');
        $path = '/tmp/'.basename('/no/perm/logger-fallback-test.php', '.php').'.log';
        $this->assertTrue(file_exists($path));
        $this->assertTrue(strpos(file_get_contents($path), 'fallback line') !== false);
    }

    public function testLogmsgWrapperFallbackToTmp(): void
    {
        // Bind script name so base is deterministic
        $_SERVER['SCRIPT_NAME'] = 'pmss-test-runner.php';
        \logmsg('wrapper line');
        $path = '/tmp/'.basename($_SERVER['SCRIPT_NAME'], '.php').'.log';
        $this->assertTrue(file_exists($path));
        $this->assertTrue(strpos(file_get_contents($path), 'wrapper line') !== false);
    }
}

