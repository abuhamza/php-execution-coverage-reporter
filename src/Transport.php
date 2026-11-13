<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use Throwable;

interface Transport
{
    /**
     * @param array<string, mixed> $batch a wire-format v1 batch
     *
     * @throws Throwable when the collector did not accept the batch
     */
    public function send(array $batch): void;
}
