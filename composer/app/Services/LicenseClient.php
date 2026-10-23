<?php

namespace App\Services;

use App\Support\License;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Online use of a licence key (consoles with internet access). The key is sent to
 * atglance.live once, at activation; atglance.live answers with the licence file (.atgl) of
 * that licence, bound to this console. The console then stores and checks that file exactly
 * like an uploaded one, offline from then on (no later online checks).
 *
 * One licence = one console: a key already in use by another console is refused.
 */
class LicenseClient
{
    /** Licence statuses atglance.live reports for a key that this console may use. */
    private const STATUS_MESSAGES = [
        'in_use_elsewhere' => 'This licence key is already in use by another Management Console. Revoke it on atglance.live and create a new licence, or use another key.',
        'unverified' => 'This licence is not confirmed yet. Enter the 5-digit code we emailed you on your atglance.live dashboard, then try again.',
        'under_review' => 'This licence is waiting for approval by an AtGlance admin. Try again after it is approved.',
        'expired' => 'This licence has expired. Create a new licence on atglance.live.',
    ];

    /**
     * Checks the key without changing anything on atglance.live (installer "Verify").
     *
     * @return array{ok: bool, message: string, name: ?string, plan: ?string, expires_at: ?string}
     */
    public function verify(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return $this->failure('Enter a licence key.');
        }

        try {
            $response = Http::acceptJson()->withToken($key)->timeout(15)->post($this->url('verify'));
        } catch (\Throwable) {
            return $this->failure(self::unreachableMessage());
        }

        $body = $this->body($response);
        $status = strtolower((string) ($body['status'] ?? ''));
        $owner = (string) ($body['console']['instance_id'] ?? '');

        if ($response->successful() && ($status === 'available' || ($status === 'in_use' && $owner === License::instanceId()))) {
            return [
                'ok' => true,
                'message' => 'Licence key is valid.',
                'name' => $body['license']['name'] ?? null,
                'plan' => is_string($body['plan'] ?? null) ? $body['plan'] : null,
                'expires_at' => $body['license']['expires_at'] ?? null,
            ];
        }
        if ($response->successful() && $status === 'in_use') {
            return $this->failure(self::STATUS_MESSAGES['in_use_elsewhere']);
        }

        return $this->failure($this->errorMessage($response, $body));
    }

    /**
     * Activates the key for this console and organisation and returns the licence file.
     *
     * @return array{ok: bool, message: string, bytes?: string}
     */
    public function activate(string $key, string $orgName): array
    {
        $key = trim($key);
        if ($key === '') {
            return $this->failure('Enter a licence key.');
        }

        try {
            $response = Http::acceptJson()->asJson()->withToken($key)->timeout(20)->post($this->url('activate'), [
                'org_name' => trim($orgName) !== '' ? trim($orgName) : (string) config('app.name', 'AtGlance'),
                'instance_id' => License::instanceId(),
                'hostname' => (string) (gethostname() ?: 'atglance-console'),
                'version' => (string) config('app.version'),
                'license_file' => true,
            ]);
        } catch (\Throwable) {
            return $this->failure(self::unreachableMessage());
        }

        $body = $this->body($response);
        if (!$response->successful()) {
            return $this->failure($this->errorMessage($response, $body));
        }

        $bytes = is_string($body['license_file'] ?? null) ? base64_decode($body['license_file'], true) : false;
        if (!is_string($bytes) || $bytes === '') {
            $reason = trim((string) ($body['license_file_error'] ?? ''));

            return $this->failure('atglance.live accepted the key but sent no licence file' . ($reason !== '' ? ': ' . $reason : '.') . ' Try again later, or use a licence file.');
        }

        return ['ok' => true, 'message' => 'Licence key activated.', 'bytes' => $bytes];
    }

    public static function unreachableMessage(): string
    {
        return 'Could not reach atglance.live. If this Management Console has no internet access, use a licence file instead of the licence key.';
    }

    private function url(string $action): string
    {
        return rtrim((string) config('licensing.api_url'), '/') . '/' . $action;
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function errorMessage(Response $response, array $body): string
    {
        $status = strtolower((string) ($body['status'] ?? ''));
        if (isset(self::STATUS_MESSAGES[$status])) {
            return self::STATUS_MESSAGES[$status];
        }
        if ($response->status() === 401) {
            return 'The licence key is wrong or has been revoked.';
        }
        $message = trim((string) ($body['message'] ?? ''));

        return $message !== '' ? $message : 'atglance.live refused the licence key (HTTP ' . $response->status() . ').';
    }

    /** @return array{ok: false, message: string, name: null, plan: null, expires_at: null} */
    private function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'name' => null, 'plan' => null, 'expires_at' => null];
    }
}
