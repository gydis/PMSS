<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

$versionDir = sys_get_temp_dir().'/pmss-tests-version';
if (!is_dir($versionDir)) {
    @mkdir($versionDir, 0755, true);
}
putenv('PMSS_VERSION_DIR='.$versionDir);
putenv('PMSS_JSON_LOG');
putenv('PMSS_PROFILE_OUTPUT');

define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 2).'/update.php';

foreach (glob(__DIR__.'/*Test.php') as $testFile) {
    require_once $testFile;
}

$classes = array_filter(get_declared_classes(), static function ($class) {
    return is_subclass_of($class, TestCase::class);
});

$total = 0;
$failures = 0;
foreach ($classes as $class) {
    /** @var TestCase $instance */
    $instance = new $class();
    foreach ($instance->run() as [$status, $method, $message]) {
        $total++;
        if ($status) {
            echo "[PASS] {$class}::{$method}\n";
        } else {
            $failures++;
            echo "[FAIL] {$class}::{$method} - {$message}\n";
        }
    }
}

echo "\nTests: {$total}, Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
