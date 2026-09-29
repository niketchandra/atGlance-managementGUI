<?php

namespace App\Services;

use App\Support\License;
use Illuminate\Support\Facades\Http;

/**
 * Calls the atglance.live licence verification API.
 *
 * One call checks the key and, when valid, marks the licence "in_use" for this
 * console. The first console to verify a licence owns it; the console is
 * identified by the instance_id we send (see License::instanceId()), so the
 * installer and the Licence tab must send the same identity.
 */
class LicenseClient
{
    public const VERIFIED_STATUS = 'in_use';

    private const STATUS_MESSAGES = [
        'in_use_elsewhere' => 'This licence is already in use by another AtGlance console. Generate a new licence on atglance.live, or release it there first.',
        'unverified' => 'This licence is not verified yet. Enter the code emailed to you on atglance.live, then try again.',
        'under_review' => 'This licence is under review by the AtGlance team. Try again after it is approved.',
    ];

    /**
     * @return array{ok: bool, message: string, status: string, details: array<string, mixed>}
     */
    public function verify(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return $this->failure('Enter a licence key.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($key)
                ->timeout(15)
                ->post($this->verifyUrl(), License::consoleIdentity());
        } catch (\Throwable $e) {
            return $this->failure('Could not reach the licence server: ' . $e->getMessage());
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = strtolower(trim((string) ($body['status'] ?? '')));

        if ($response->successful() && $status === self::VERIFIED_STATUS) {
            return [
                'ok' => true,
                'message' => 'Licence verified.',
                'status' => $status,
                'details' => $this->details($body),
            ];
        }

        $message = self::STATUS_MESSAGES[$status] ?? null;
        if ($message === null && $response->status() === 401) {
            $message = 'The licence key is wrong or has been revoked.';
        }
        if ($message === null) {
            $message = trim((string) ($body['message'] ?? $body['error'] ?? ''));
        }
        if ($message === '') {
            $message = $status !== ''
                ? 'Licence is not usable (status: ' . $status . ').'
                : 'Licence verification failed (HTTP ' . $response->status() . ').';
        }

        return $this->failure($message, $status);
    }

    private function verifyUrl(): string
    {
        return (string) config('services.atglance_license.verify_url', 'https://atglance.live/api/licenses/verify');
    }

    /**
     * Picks the fields we show: licence name, plan, expiry, plus the other
     * response fields flattened to dot keys (user.email, console.instance_id, ...).
     *
     * @return array<string, mixed>
     */
    private function details(array $body): array
    {
        $license = is_array($body['license'] ?? null) ? $body['license'] : [];

        $plan = $body['plan'] ?? $license['plan'] ?? null;
        if (is_array($plan)) {
            $plan = $plan['name'] ?? $plan['title'] ?? $plan['slug'] ?? null;
        }

        return [
            'name' => $license['name'] ?? $body['license_name'] ?? null,
            'plan' => $plan,
            'expires_at' => $license['expires_at'] ?? $body['expires_at'] ?? null,
            'extra' => $this->flatten($body),
        ];
    }

    /**
     * @return array<string, scalar>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['key', 'license_key', 'token', 'message', 'valid', 'status'], true)) {
                continue;
            }

            $path = $prefix . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path . '.');
            } elseif (is_scalar($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private function failure(string $message, string $status = ''): array
    {
        return ['ok' => false, 'message' => $message, 'status' => $status, 'details' => []];
    }
}
