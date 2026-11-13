<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use Closure;
use Throwable;

/**
 * One reading of the opcache counters of an FPM pool, sent as the loads since
 * the previous reading. What the previous reading saw is kept in a file on the
 * node, by project-relative path.
 */
final class FpmPoll
{
    public const MAX_PATHS_PER_BATCH = 1000;

    private const BUDGET_SECONDS = 25;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock current Unix time, time() when null
     */
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly string $stateFile,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Never throws.
     *
     * @param mixed $status what opcache_get_status() returned
     *
     * @return string the outcome in one line
     */
    public function run(mixed $status): string
    {
        try {
            if (!is_array($status) || ($status['opcache_enabled'] ?? false) !== true || !is_array($status['scripts'] ?? null)) {
                return 'opcache is not available';
            }
            $loads = $this->loads($status['scripts'], $status['preload_statistics'] ?? null);

            $state = @fopen($this->stateFile, 'c+');
            if ($state !== false && !flock($state, LOCK_EX | LOCK_NB)) {
                return 'another poll is running';
            }

            $cache = self::cacheId($status);
            $previous = $state === false ? [] : $this->previousLoads($state, $cache);

            $increase = [];
            $seen = [];
            foreach ($loads as $path => $count) {
                $before = $previous[$path] ?? 0;
                // A counter below the recorded one belongs to a script that was cached anew.
                $delta = $count < $before ? $count : $count - $before;
                if ($delta > 0) {
                    $increase[$path] = $delta;
                } else {
                    $seen[$path] = $count;
                }
            }

            $now = ($this->clock)();
            $sent = 0;
            $batches = 0;
            $problem = null;
            foreach (array_chunk($increase, self::MAX_PATHS_PER_BATCH, true) as $files) {
                if (($this->clock)() - $now >= self::BUDGET_SECONDS) {
                    $problem = 'out of time';
                    break;
                }

                try {
                    $this->transport->send(Batch::of($this->config, Reporter::SOURCE_FPM, $now, $files));
                } catch (Throwable $failure) {
                    $problem = (string) preg_replace('/\s+/', ' ', $failure->getMessage());
                    break;
                }

                foreach ($files as $path => $delta) {
                    $seen[$path] = $loads[$path];
                }
                $sent += count($files);
                ++$batches;
            }

            // What was not sent keeps its recorded counter and goes out with the next poll.
            $seen += array_intersect_key($previous, $increase);
            $saved = $state !== false && $this->save($state, $cache, $seen);

            if ($increase === [] && $saved) {
                return 'no new hits';
            }

            $outcome = $problem === null
                ? sprintf('sent %d path%s in %d batch%s', $sent, $sent === 1 ? '' : 's', $batches, $batches === 1 ? '' : 'es')
                : sprintf('sent %d of %d paths: %s', $sent, count($increase), $problem);
            if (!$saved) {
                $outcome .= ', counters not saved';
            }
            if ($problem !== null || !$saved) {
                error_log('deadcode reporter: fpm poll: ' . $outcome);
            }

            return $outcome;
        } catch (Throwable $failure) {
            $outcome = 'failed: ' . preg_replace('/\s+/', ' ', $failure->getMessage());
            error_log('deadcode reporter: fpm poll: ' . $outcome);

            return $outcome;
        }
    }

    /**
     * Loads by project-relative path for the cached scripts under the project
     * root. The load that compiles a script is not counted by opcache as a hit,
     * so a cached script was loaded once more than it was hit, unless preloading
     * cached it without anything asking for it.
     *
     * @param array<mixed> $scripts
     *
     * @return array<string, int>
     */
    private function loads(array $scripts, mixed $preload): array
    {
        $preloaded = [];
        foreach (is_array($preload) && is_array($preload['scripts'] ?? null) ? $preload['scripts'] : [] as $file) {
            if (is_string($file)) {
                $preloaded[$file] = true;
            }
        }

        $prefix = $this->config->projectRoot . '/';
        $loads = [];
        foreach ($scripts as $script) {
            $file = is_array($script) ? $script['full_path'] ?? null : null;
            $hits = is_array($script) ? $script['hits'] ?? null : null;
            if (is_string($file) && is_int($hits) && str_starts_with($file, $prefix)) {
                $loads[substr($file, strlen($prefix))] = $hits + (isset($preloaded[$file]) ? 0 : 1);
            }
        }

        return $loads;
    }

    /**
     * Changes when FPM restarts and when opcache empties its cache, the two
     * events that put every counter back to zero.
     *
     * @param array<mixed> $status
     */
    private static function cacheId(array $status): string
    {
        $statistics = $status['opcache_statistics'] ?? null;
        $startTime = is_array($statistics) ? $statistics['start_time'] ?? null : null;
        $lastRestartTime = is_array($statistics) ? $statistics['last_restart_time'] ?? null : null;

        return (is_int($startTime) ? $startTime : 0) . ':' . (is_int($lastRestartTime) ? $lastRestartTime : 0);
    }

    /**
     * @param resource $state
     *
     * @return array<string, int> empty when the file is new, unreadable, or about another release or another life of the cache
     */
    private function previousLoads($state, string $cache): array
    {
        $saved = json_decode((string) stream_get_contents($state), true);
        if (
            !is_array($saved)
            || ($saved['root'] ?? null) !== $this->config->projectRoot
            || ($saved['cache'] ?? null) !== $cache
            || !is_array($saved['loads'] ?? null)
        ) {
            return [];
        }

        $loads = [];
        foreach ($saved['loads'] as $path => $count) {
            if (is_string($path) && is_int($count)) {
                $loads[$path] = $count;
            }
        }

        return $loads;
    }

    /**
     * @param resource           $state
     * @param array<string, int> $loads
     */
    private function save($state, string $cache, array $loads): bool
    {
        $json = json_encode(['root' => $this->config->projectRoot, 'cache' => $cache, 'loads' => $loads], JSON_THROW_ON_ERROR);

        return rewind($state) && ftruncate($state, 0) && @fwrite($state, $json) === strlen($json) && fflush($state);
    }
}
