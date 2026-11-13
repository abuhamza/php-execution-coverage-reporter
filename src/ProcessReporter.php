<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use Closure;
use Composer\InstalledVersions;
use Throwable;

/**
 * The reporter of the running PHP process, flushed with whatever the process
 * has loaded so far. The shutdown flush and the per-job flushes go through the
 * same instance, which is how a file is not sent twice.
 */
final class ProcessReporter
{
    private static ?self $shared = null;

    private ?Reporter $reporter = null;
    private string $source = Reporter::SOURCE_CLI;

    /**
     * @param Closure(): ?Reporter $factory called at each flush until it returns a reporter;
     *                                      null while the reporter is switched off or not configured
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    /**
     * The instance configured from the environment. Configuration is read at the
     * first flush rather than here, so that variables the application loads after
     * the autoloader (dotenv files) are seen.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self(static function (): ?Reporter {
            $config = Config::fromEnv($_SERVER + $_ENV + getenv(), InstalledVersions::getRootPackage()['install_path']);

            return $config === null ? null : new Reporter($config, new CurlTransport($config->url, $config->token));
        });
    }

    /**
     * Never throws.
     *
     * @param string|null $source kind of process, one of the Reporter::SOURCE_ constants;
     *                            null keeps the one of the previous flush
     */
    public function flush(?string $source = null): void
    {
        try {
            $this->source = $source ?? $this->source;
            $this->reporter ??= ($this->factory)();
            $this->reporter?->flush(get_included_files(), $this->source);
        } catch (Throwable) {
            // Nothing in the reporter may reach the host application.
        }
    }
}
