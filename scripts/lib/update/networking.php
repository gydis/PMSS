<?php
/**
 * Networking helpers for update-step2.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssEnsureNetworkTemplate')) {
    /**
     * Seed the default network configuration file when missing.
     */
    function pmssEnsureNetworkTemplate(?callable $logger = null): void
    {
        $log  = pmssSelectLogger($logger);
        $path = '/etc/seedbox/config/network';
        if (file_exists($path)) {
            return;
        }

        $template = <<<PHP
<?php
#Default settings, change these to suit your system. Speeds are in mbits
return array(
    'interface' => 'eth0',
    'speed' => '1000',
    'throttle' => array(
      'min' => 50,
      'max' => 100,
      'soft' => 250,
      'limitSoft' => 80,
      'limitExceedMax' => 20

    )

);
PHP;

        file_put_contents($path, $template);
        $log('Created default network configuration');
    }
}

if (!function_exists('pmssApplyNetworkConfig')) {
    /**
     * Reapply the active network configuration.
     */
    function pmssApplyNetworkConfig(): void
    {
        runStep('Reapplying network configuration', '/scripts/util/setupNetwork.php');
    }
}
