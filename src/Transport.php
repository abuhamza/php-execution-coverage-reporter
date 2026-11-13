<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

interface Transport
{
    /**
     * @param array<string, mixed> $batch a wire-format v1 batch
     */
    public function send(array $batch): void;
}
