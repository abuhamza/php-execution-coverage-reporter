<?php

declare(strict_types=1);

namespace Deadcode\Reporter;

use RuntimeException;

final class CurlTransport implements Transport
{
    /**
     * @param int $timeoutMs limit for connecting and for the whole request; the
     *                       default is what an application can afford at shutdown
     */
    public function __construct(
        private readonly string $collectorUrl,
        private readonly string $token,
        private readonly int $timeoutMs = 1000,
    ) {
    }

    public function send(array $batch): void
    {
        $curl = curl_init(rtrim($this->collectorUrl, '/') . '/ingest');
        if ($curl === false) {
            throw new RuntimeException('could not initialise curl');
        }

        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($batch, JSON_THROW_ON_ERROR));
        // The empty Expect header stops curl from holding back a large body (over
        // 1 MB, or 1 KB with older curl) for a second waiting for "100 Continue".
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Expect:',
        ]);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, $this->timeoutMs);
        curl_setopt($curl, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
        // Keeps curl from using SIGALRM, which belongs to the host application.
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        if (curl_exec($curl) === false) {
            throw new RuntimeException(curl_error($curl));
        }

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('collector answered HTTP ' . $status);
        }
    }
}
