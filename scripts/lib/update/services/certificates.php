<?php
/**
 * Certificate automation helpers.
 */

require_once __DIR__.'/../runtime/commands.php';

if (!function_exists('pmssEnsureLetsEncryptConfig')) {
    /**
     * Refresh Let's Encrypt automation configuration.
     */
    function pmssEnsureLetsEncryptConfig(): void
    {
        runStep('Updating Let\'s Encrypt configuration', '/scripts/util/setupLetsEncrypt.php noreplies@pulsedmedia.com');
    }
}
