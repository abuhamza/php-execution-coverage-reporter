<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Deadcode\Reporter\Config;
use Deadcode\Reporter\CurlTransport;
use Deadcode\Reporter\Reporter;

// Loaded by Composer's autoloader in the host application. Web requests are not
// captured here: under FPM this would send one batch per request.
if (PHP_SAPI === 'cli') {
    // Configuration is read at shutdown so that variables loaded by the
    // application after the autoloader (dotenv files) are seen.
    register_shutdown_function(static function (): void {
        try {
            $config = Config::fromEnv($_SERVER + $_ENV + getenv(), InstalledVersions::getRootPackage()['install_path']);
            if ($config === null) {
                return;
            }

            (new Reporter($config, new CurlTransport($config->url, $config->token)))->flush(get_included_files());
        } catch (Throwable) {
            // Nothing in the reporter may reach the host application.
        }
    });
}
