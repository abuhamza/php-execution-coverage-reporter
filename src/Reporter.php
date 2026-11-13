<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use Throwable;

final class Reporter
{
    private const WIRE_FORMAT_VERSION = 1;

    private bool $failureLogged = false;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
    ) {
    }

    /**
     * Sends one batch with a hit for every loaded file under the project root.
     * Never throws: a batch that cannot be sent is dropped.
     *
     * @param list<string> $loadedFiles absolute paths, as returned by get_included_files()
     */
    public function flush(array $loadedFiles): void
    {
        try {
            $prefix = $this->config->projectRoot . '/';
            $files = [];
            foreach ($loadedFiles as $file) {
                if (str_starts_with($file, $prefix)) {
                    $files[substr($file, strlen($prefix))] = 1;
                }
            }

            if ($files === []) {
                return;
            }

            $this->transport->send([
                'v' => self::WIRE_FORMAT_VERSION,
                'app' => $this->config->app,
                'host' => (string) gethostname(),
                'sha' => $this->config->sha,
                'env' => $this->config->env,
                'day' => gmdate('Y-m-d'),
                'files' => $files,
            ]);
        } catch (Throwable $failure) {
            $this->logOnce($failure);
        }
    }

    private function logOnce(Throwable $failure): void
    {
        if ($this->failureLogged) {
            return;
        }
        $this->failureLogged = true;

        // Without an error_log setting the CLI writes the line to stderr, where
        // it would end up in the application's own output and in cron mail.
        if (PHP_SAPI === 'cli' && (string) ini_get('error_log') === '') {
            return;
        }

        error_log('deadcode reporter: batch dropped: ' . preg_replace('/\s+/', ' ', $failure->getMessage()));
    }
}
