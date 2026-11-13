<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Deadcode\Reporter\FpmEndpoint;

// Served by the application's web server on a location only the node itself
// can reach, and requested by cron: see "Web requests" in the README.
require_once __DIR__ . '/../../autoload.php';

[$status, $outcome] = FpmEndpoint::handle($_SERVER + $_ENV + getenv(), InstalledVersions::getRootPackage()['install_path']);

http_response_code($status);
header('Content-Type: text/plain');
echo $outcome, "\n";
