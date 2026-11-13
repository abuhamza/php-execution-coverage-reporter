<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

final class Config
{
    public function __construct(
        public readonly string $url,
        public readonly string $token,
        public readonly string $app,
        public readonly string $env,
        public readonly string $projectRoot,
        public readonly string $sha,
    ) {
    }

    /**
     * @param array<mixed> $env environment variables by name
     *
     * @return self|null null when the reporter is switched off or the collector URL, token or app name is missing
     */
    public static function fromEnv(array $env, string $projectRoot): ?self
    {
        $value = static function (string $name) use ($env): string {
            $value = $env[$name] ?? '';

            return is_string($value) ? trim($value) : '';
        };

        $url = $value('DEADCODE_URL');
        $token = $value('DEADCODE_TOKEN');
        $app = $value('DEADCODE_APP');
        $root = realpath($projectRoot);
        if (
            in_array(strtolower($value('DEADCODE_ENABLED')), ['0', 'false', 'off', 'no'], true)
            || $url === '' || $token === '' || $app === '' || $root === false
        ) {
            return null;
        }

        $environment = $value('DEADCODE_ENV');
        $sha = $value('DEADCODE_SHA');

        return new self(
            $url,
            $token,
            $app,
            $environment === '' ? 'prod' : $environment,
            $root,
            $sha === '' ? self::deployedRevision($root) : $sha,
        );
    }

    /**
     * Deployer writes the deployed commit to a REVISION file in each release
     * directory, which is the project root once the "current" symlink is resolved.
     */
    private static function deployedRevision(string $root): string
    {
        $file = $root . '/REVISION';
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        return trim((string) @file_get_contents($file, false, null, 0, 64));
    }
}
