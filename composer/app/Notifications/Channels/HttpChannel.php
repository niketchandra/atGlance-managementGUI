<?php

namespace App\Notifications\Channels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared HTTP handling: a short timeout, so a slow provider does not hold up
 * the request that triggered the notification, and errors without secrets.
 */
abstract class HttpChannel implements NotificationChannel
{
    protected const TIMEOUT_SECONDS = 5;

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::TIMEOUT_SECONDS)->acceptJson();
    }

    protected function ensureSuccessful(Response $response, string $provider): Response
    {
        if (!$response->successful()) {
            throw new RuntimeException(sprintf('%s returned HTTP %d: %s', $provider, $response->status(), mb_substr(trim($response->body()), 0, 300)));
        }

        return $response;
    }
}
