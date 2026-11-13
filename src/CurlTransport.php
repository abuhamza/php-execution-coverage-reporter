<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

final class CurlTransport implements Transport
{
    private const TIMEOUT_MS = 1000;

    public function __construct(
        private readonly string $collectorUrl,
        private readonly string $token,
    ) {
    }

    public function send(array $batch): void
    {
        $body = json_encode($batch);
        $curl = curl_init(rtrim($this->collectorUrl, '/') . '/ingest');
        if ($body === false || $curl === false) {
            return;
        }

        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ]);
        curl_setopt($curl, CURLOPT_TIMEOUT_MS, self::TIMEOUT_MS);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_exec($curl);
    }
}
