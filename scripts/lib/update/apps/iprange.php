<?php
/**
 * Compile iprange after the package phase completes.
 */

require_once __DIR__.'/packages/helpers.php';

if (empty($GLOBALS['PMSS_PACKAGES_READY'])) {
    if (function_exists('logmsg')) {
        logmsg('[WARN] Skipping iprange build: package phase not complete');
    }
    return;
}

if (file_exists('/usr/local/bin/iprange')) {
    return;
}

$dependencies = ['build-essential', 'gcc', 'make', 'gawk'];
$missing = [];
foreach ($dependencies as $pkg) {
    if (pmssPackageStatus($pkg) !== 'install ok installed') {
        $missing[] = $pkg;
    }
}

if (!empty($missing)) {
    $message = 'Skipping iprange build: missing toolchain packages '.implode(', ', $missing);
    if (function_exists('logmsg')) {
        logmsg('[WARN] '.$message);
    } else {
        echo $message."\n";
    }
    return;
}

$compileCmd = implode(' && ', [
    'set -e',
    'mkdir -p /root/compile',
    'cd /root/compile',
    'rm -rf iprange-1.0.4 iprange-1.0.4.tar.gz',
    'wget http://pulsedmedia.com/remote/pkg/iprange-1.0.4.tar.gz -O iprange-1.0.4.tar.gz',
    'tar -xzf iprange-1.0.4.tar.gz',
    'cd iprange-1.0.4',
    './configure',
    'make -j6',
    'make install'
]);

runStep('Building iprange from source', $compileCmd);
