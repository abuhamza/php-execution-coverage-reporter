<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use Closure;
use Throwable;

final class Reporter
{
    public const SOURCE_CLI = 'cli';
    public const SOURCE_WORKER = 'worker';
    public const SOURCE_FPM = 'fpm';

    private const BACK_OFF_SECONDS = 60;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var array<string, 1> project-relative paths the collector accepted on $sentDay */
    private array $sent = [];
    private string $sentDay = '';
    private int $nextAttemptAt = 0;
    private bool $failureLogged = false;

    /**
     * @param (Closure(): int)|null $clock current Unix time, time() when null
     */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Sends one batch with a hit for every loaded file under the project root
     * that the collector has not accepted from this reporter yet today (UTC).
     * So a flush sends what was loaded since the previous one, the first flush
     * of a day sends everything again, and files of a batch that could not be
     * sent go out with a later flush. After a failed send, flushes do nothing
     * for a minute. Never throws.
     *
     * @param list<string> $loadedFiles absolute paths, as returned by get_included_files()
     * @param string       $source      kind of process, one of the SOURCE_ constants
     */
    public function flush(array $loadedFiles, string $source = self::SOURCE_CLI): void
    {
        try {
            $now = ($this->clock)();
            if ($now < $this->nextAttemptAt) {
                return;
            }

            $day = gmdate('Y-m-d', $now);
            if ($day !== $this->sentDay) {
                $this->sent = [];
                $this->sentDay = $day;
            }

            $prefix = $this->config->projectRoot . '/';
            $files = [];
            foreach ($loadedFiles as $file) {
                if (!str_starts_with($file, $prefix)) {
                    continue;
                }
                $path = substr($file, strlen($prefix));
                if (!isset($this->sent[$path])) {
                    $files[$path] = 1;
                }
            }

            if ($files === []) {
                return;
            }

            // Set before sending so that it stands when the transport throws.
            $this->nextAttemptAt = $now + self::BACK_OFF_SECONDS;
            $this->transport->send(Batch::of($this->config, $source, $now, $files));
            $this->nextAttemptAt = 0;
            $this->sent += $files;
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

        error_log('deadcode reporter: batch not sent: ' . preg_replace('/\s+/', ' ', $failure->getMessage()));
    }
}
