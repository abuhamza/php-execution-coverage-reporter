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
    ) {
    }

    /**
     * @param array<mixed> $env environment variables by name
     *
     * @return self|null null when the collector URL, token or app name is missing
     */
    public static function fromEnv(array $env, string $projectRoot): ?self
    {
        $value = static function (string $name) use ($env): string {
            $value = $env[$name] ?? '';

            return is_string($value) ? $value : '';
        };

        $url = $value('DEADCODE_URL');
        $token = $value('DEADCODE_TOKEN');
        $app = $value('DEADCODE_APP');
        $root = realpath($projectRoot);
        if ($url === '' || $token === '' || $app === '' || $root === false) {
            return null;
        }

        $environment = $value('DEADCODE_ENV');

        return new self($url, $token, $app, $environment === '' ? 'prod' : $environment, $root);
    }
}
