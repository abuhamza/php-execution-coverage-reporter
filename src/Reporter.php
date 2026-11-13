<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

final class Reporter
{
    private const WIRE_FORMAT_VERSION = 1;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
    ) {
    }

    /**
     * Sends one batch with a hit for every loaded file under the project root.
     *
     * @param list<string> $loadedFiles absolute paths, as returned by get_included_files()
     */
    public function flush(array $loadedFiles): void
    {
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
            'sha' => '',
            'env' => $this->config->env,
            'day' => gmdate('Y-m-d'),
            'files' => $files,
        ]);
    }
}
