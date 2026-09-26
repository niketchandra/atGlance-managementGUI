<?php

namespace App\Services;

use App\Support\AiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the configured AI provider. Anthropic uses the Messages API;
 * every other provider uses the OpenAI Chat Completions format.
 *
 * A connection is ['provider', 'base_url', 'model', 'api_key'], as returned
 * by AiSettings::connection().
 */
class AiClient
{
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Sends one prompt and returns the reply text.
     *
     * @throws RuntimeException with a message that is safe to show (key removed).
     */
    public function complete(array $connection, string $prompt, int $maxTokens = 1024, ?string $system = null): string
    {
        $meta = $this->meta($connection);
        if (trim((string) $connection['model']) === '') {
            throw new RuntimeException('Enter a model name.');
        }

        if ($meta['protocol'] === 'anthropic') {
            $body = [
                'model' => $connection['model'],
                'max_tokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ];
            if ($system !== null) {
                $body['system'] = $system;
            }

            $data = $this->send($connection, 'post', $this->anthropicUrl($connection, '/messages'), $body);

            return collect($data['content'] ?? [])
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');
        }

        $messages = [['role' => 'user', 'content' => $prompt]];
        if ($system !== null) {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        $data = $this->send($connection, 'post', $this->baseUrl($connection) . '/chat/completions', [
            'model' => $connection['model'],
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ]);

        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * One minimal call to check the key, URL and model.
     *
     * @return array{ok: bool, message: string, latency_ms: int, reply: string}
     */
    public function test(array $connection): array
    {
        $started = microtime(true);

        try {
            $reply = $this->complete($connection, 'Reply with the single word OK.', 32);
            $latency = (int) round((microtime(true) - $started) * 1000);

            return [
                'ok' => true,
                'message' => 'Connected to ' . $this->meta($connection)['label'] . ' (' . $connection['model'] . ') in ' . $latency . ' ms.',
                'latency_ms' => $latency,
                'reply' => mb_substr(trim($reply), 0, 200),
            ];
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'reply' => '',
            ];
        }
    }

    /**
     * Model IDs the provider reports.
     *
     * @return list<string>
     * @throws RuntimeException
     */
    public function models(array $connection): array
    {
        $meta = $this->meta($connection);
        $url = $meta['protocol'] === 'anthropic'
            ? $this->anthropicUrl($connection, '/models') . '?limit=100'
            : $this->baseUrl($connection) . '/models';

        $data = $this->send($connection, 'get', $url);

        return collect($data['data'] ?? [])
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->sort()
            ->values()
            ->all();
    }

    private function meta(array $connection): array
    {
        $meta = AiSettings::PROVIDERS[$connection['provider'] ?? ''] ?? null;
        if ($meta === null) {
            throw new RuntimeException('Unknown AI provider.');
        }

        return $meta;
    }

    private function baseUrl(array $connection): string
    {
        $url = AiSettings::resolveBaseUrl($connection['provider'], trim((string) ($connection['base_url'] ?? '')));
        if ($url === '') {
            throw new RuntimeException('Enter the base URL for this provider.');
        }

        return $url;
    }

    /**
     * The Anthropic base URL may be entered with or without the /v1 suffix.
     */
    private function anthropicUrl(array $connection, string $path): string
    {
        $base = $this->baseUrl($connection);

        return (str_ends_with($base, '/v1') ? $base : $base . '/v1') . $path;
    }

    private function request(array $connection): PendingRequest
    {
        $meta = $this->meta($connection);
        $key = trim((string) ($connection['api_key'] ?? ''));

        if ($meta['key'] === 'required' && $key === '') {
            throw new RuntimeException('Enter the API key for ' . $meta['label'] . '.');
        }

        $request = Http::acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);

        if ($meta['protocol'] === 'anthropic') {
            $request = $request->withHeaders(['anthropic-version' => '2023-06-01']);
        }

        if ($key !== '') {
            $request = match ($meta['auth']) {
                'x-api-key' => $request->withHeaders(['x-api-key' => $key]),
                'api-key' => $request->withHeaders(['api-key' => $key]),
                default => $request->withToken($key),
            };
        }

        return $request;
    }

    private function send(array $connection, string $method, string $url, array $body = []): array
    {
        try {
            $response = $method === 'get'
                ? $this->request($connection)->get($url)
                : $this->request($connection)->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException($this->redact('Could not reach ' . $url . ': ' . $e->getMessage(), $connection));
        }

        if ($response->failed()) {
            throw new RuntimeException($this->redact($this->describeFailure($response), $connection));
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('The provider returned a response that is not JSON. Check the base URL.');
        }

        return $data;
    }

    private function describeFailure(Response $response): string
    {
        $data = $response->json();
        $error = is_array($data) ? ($data['error'] ?? $data['message'] ?? null) : null;
        if (is_array($error)) {
            $error = $error['message'] ?? json_encode($error);
        }
        if (!is_string($error) || $error === '') {
            $error = mb_substr(trim(strip_tags($response->body())), 0, 300) ?: 'no details';
        }

        $hint = match ($response->status()) {
            401, 403 => ' Check the API key.',
            404 => ' Check the base URL and the model name.',
            429 => ' The provider is rate limiting or the account is out of credit.',
            default => '',
        };

        return 'HTTP ' . $response->status() . ': ' . $error . $hint;
    }

    private function redact(string $message, array $connection): string
    {
        $key = trim((string) ($connection['api_key'] ?? ''));

        return strlen($key) >= 6 ? str_replace($key, '***', $message) : $message;
    }
}
