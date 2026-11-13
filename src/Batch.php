<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

final class Batch
{
    private const WIRE_FORMAT_VERSION = 1;

    /**
     * @param string             $source one of the Reporter::SOURCE_ constants
     * @param int                $now    Unix time; the batch is filed under its UTC day
     * @param array<string, int> $files  hits by project-relative path
     *
     * @return array<string, mixed> a wire-format v1 batch
     */
    public static function of(Config $config, string $source, int $now, array $files): array
    {
        return [
            'v' => self::WIRE_FORMAT_VERSION,
            'app' => $config->app,
            'host' => (string) gethostname(),
            'sha' => $config->sha,
            'env' => $config->env,
            'source' => $source,
            'day' => gmdate('Y-m-d', $now),
            'files' => $files,
        ];
    }
}
