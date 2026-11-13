<?php

declare(strict_types=1);

use Deadcode\Reporter\ProcessReporter;

// Loaded by Composer's autoloader in the host application. Web requests are not
// captured here: under FPM this would send one batch per request.
if (PHP_SAPI === 'cli') {
    register_shutdown_function(static function (): void {
        ProcessReporter::shared()->flush();
    });
}
