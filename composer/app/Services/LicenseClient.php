<?php

namespace App\Services;

use App\Support\License;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls the atglance.live licence API.
 *
 * verify() only checks the key and changes nothing on atglance.live. It backs
 * the installer's "Verify" button.
 *
 * activate() links the licence to this console and organisation and marks it
 * "in_use". The first console to activate a licence owns it; the console is
 * identified by the instance_id we send (see License::instanceId()), so the
 * installer and the Licence tab must send the same identity.
 */
class LicenseClient
{
    public const VERIFIED_STATUS = 'in_use';

    /** Statuses the verify call returns for a key that can be used. */
    private const USABLE_STATUSES = ['available', 'in_use'];

    private const STATUS_MESSAGES = [
        'in_use_elsewhere' => 'This licence is already in use by another AtGlance console. Generate a new licence on atglance.live, or release it there first.',
        'unverified' => 'This licence is not verified yet. Enter the code emailed to you on atglance.live, then try again.',
        'under_review' => 'This licence is under review by the AtGlance team. Try again after it is approved.',
    ];

    /**
     * Checks the key only. ok is true for "available" and "in_use".
     *
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
                ->withToken($key)
                ->timeout(15)
                ->post($this->url('verify_url', 'verify'));
        } catch (\Throwable $e) {
            return $this->failure('Could not reach the licence server: ' . $e->getMessage());
        }

        $body = $this->body($response);
        $status = $this->status($body);

        if ($response->successful() && in_array($status, self::USABLE_STATUSES, true)) {
            $details = $this->details($body);
            $orgName = trim((string) ($details['extra']['console.org_name'] ?? ''));

            return [
                'ok' => true,
                'message' => $status === 'in_use' && $orgName !== ''
                    ? 'Licence is valid. It is in use by "' . $orgName . '".'
                    : 'Licence is valid.',
                'status' => $status,
                'details' => $details,
            ];
        }

        return $this->failure($this->errorMessage($response, $body, $status), $status);
    }

    /**
     * Links the licence to this console and organisation. ok is true when
     * atglance.live reports the licence "in_use" (201 new link, 200 same console).
     *
     * @return array{ok: bool, message: string, status: string, details: array<string, mixed>}
     */
    public function activate(string $key, string $orgName): array
    {
        $key = trim($key);
        $orgName = trim($orgName);
        if ($key === '') {
            return $this->failure('Enter a licence key.');
        }
        if ($orgName === '') {
            return $this->failure('An organisation name is required to activate the licence.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($key)
                ->timeout(15)
                ->post($this->url('activate_url', 'activate'), ['org_name' => $orgName] + License::consoleIdentity());
        } catch (\Throwable $e) {
            return $this->failure('Could not reach the licence server: ' . $e->getMessage());
        }

        $body = $this->body($response);
        $status = $this->status($body);

        if ($response->successful() && $status === self::VERIFIED_STATUS) {
            return [
                'ok' => true,
                'message' => 'Licence activated.',
                'status' => $status,
                'details' => $this->details($body),
            ];
        }

        return $this->failure($this->errorMessage($response, $body, $status), $status);
    }

    private function url(string $key, string $action): string
    {
        return (string) config('services.atglance_license.' . $key, 'https://atglance.live/api/licenses/' . $action);
    }

    private function body(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function status(array $body): string
    {
        return strtolower(trim((string) ($body['status'] ?? '')));
    }

    private function errorMessage(Response $response, array $body, string $status): string
    {
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
                : 'Licence request failed (HTTP ' . $response->status() . ').';
        }

        return $message;
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
