<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use Throwable;

/**
 * The HTTP side of the FPM poll: who may ask for one, and with which settings it runs.
 */
final class FpmEndpoint
{
    /**
     * Cron waits for the answer, no user does: a batch gets more time here
     * than in an application that is shutting down.
     */
    private const SEND_TIMEOUT_MS = 5000;

    /**
     * A proxy on the node itself makes every request come from a loopback
     * address, so a request that says it was forwarded is refused as well.
     * These headers are only ever a reason to refuse.
     */
    private const FORWARDED_HEADERS = ['HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP'];

    /**
     * Never throws.
     *
     * @param array<mixed> $server      the request and environment variables by name, as in $_SERVER
     * @param string       $projectRoot directory of the application's composer.json
     *
     * @return array{int, string} HTTP status code and one line of text for the body
     */
    public static function handle(array $server, string $projectRoot): array
    {
        try {
            if (!self::isLocal($server)) {
                return [403, 'forbidden'];
            }

            $config = Config::fromEnv($server, $projectRoot);
            if ($config === null) {
                return [200, 'reporter is switched off or not configured'];
            }

            $stateDir = $server['DEADCODE_STATE_DIR'] ?? '';
            $stateDir = is_string($stateDir) && trim($stateDir) !== '' ? trim($stateDir) : sys_get_temp_dir();
            $stateFile = rtrim($stateDir, '/') . '/deadcode-fpm-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $config->app . '-' . $config->env) . '.json';

            $poll = new FpmPoll($config, new CurlTransport($config->url, $config->token, self::SEND_TIMEOUT_MS), $stateFile);

            return [200, $poll->run(function_exists('opcache_get_status') ? @opcache_get_status() : false)];
        } catch (Throwable) {
            return [500, 'failed'];
        }
    }

    /**
     * @param array<mixed> $server
     */
    private static function isLocal(array $server): bool
    {
        foreach (self::FORWARDED_HEADERS as $header) {
            if (isset($server[$header])) {
                return false;
            }
        }

        $address = $server['REMOTE_ADDR'] ?? null;
        $packed = is_string($address) ? @inet_pton($address) : false;
        if ($packed === false) {
            return false;
        }

        return strlen($packed) === 4
            ? $packed[0] === "\x7f"
            : $packed === str_repeat("\0", 15) . "\x01" || str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff\x7f");
    }
}
