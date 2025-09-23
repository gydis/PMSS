<?php
namespace PMSS\Tests\Production;

require_once __DIR__.'/../common/TestCase.php';

foreach (glob(__DIR__.'/*Test.php') as $testFile) {
    require_once $testFile;
}

$classes = array_filter(get_declared_classes(), static function ($class) {
    return is_subclass_of($class, \PMSS\Tests\TestCase::class);
});

$total = 0;
$failures = 0;
foreach ($classes as $class) {
    /** @var \PMSS\Tests\TestCase $instance */
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

echo "\nProduction tests (static scaffolding) – Tests: {$total}, Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
