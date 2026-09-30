<?php

namespace App\Services;

use App\Support\License;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls the atglance.live licence API.
 *
 * verify() only checks the key and changes nothing on atglance.live.
 *
 * checkAvailable() uses verify() to decide whether this console may use the
 * key: "available" keys and keys already activated for this console pass; a
 * key "in_use" by any other console or organisation is refused. It backs the
 * installer's "Verify" button, and activateIfAvailable() runs it before every
 * activation, so an in-use key never reaches the activate API.
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

    public const IN_USE_MESSAGE = 'This licence key is already in use by another AtGlance console. Deactivate it on atglance.live, or create a new licence key and use that one.';

    private const STATUS_MESSAGES = [
        'in_use_elsewhere' => self::IN_USE_MESSAGE,
        'unverified' => 'This licence is not verified yet. Enter the code emailed to you on atglance.live, then try again.',
        'under_review' => 'This licence is under review by the AtGlance team. Try again after it is approved.',
    ];

    /**
     * Checks the key only. ok is true for "available" and "in_use" (by any
     * console); use checkAvailable() to decide whether this console may use it.
     *
     * http_status is 0 when atglance.live could not be reached.
     *
     * @return array{ok: bool, message: string, status: string, http_status: int, details: array<string, mixed>}
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
            return [
                'ok' => true,
                'message' => 'Licence is valid.',
                'status' => $status,
                'http_status' => $response->status(),
                'details' => $this->details($body),
            ];
        }

        return $this->failure($this->errorMessage($response, $body, $status), $status, $response->status());
    }

    /**
     * Whether this console may use the key. ok is true when the key is
     * "available", or "in_use" by this same console (for example after a
     * reinstall that kept storage/app/installer/instance_id). A key in use by
     * any other console or organisation is refused with IN_USE_MESSAGE.
     *
     * @return array{ok: bool, message: string, status: string, http_status: int, details: array<string, mixed>}
     */
    public function checkAvailable(string $key): array
    {
        $result = $this->verify($key);
        if (!$result['ok']) {
            return $result;
        }

        if ($result['status'] === self::VERIFIED_STATUS) {
            $owner = trim((string) ($result['details']['extra']['console.instance_id'] ?? ''));
            if ($owner === '' || $owner !== License::instanceId()) {
                return $this->failure(self::IN_USE_MESSAGE, 'in_use_elsewhere', $result['http_status']);
            }

            $result['message'] = 'Licence is valid. It is already activated for this console.';

            return $result;
        }

        $result['message'] = 'Licence is valid and ready to activate.';

        return $result;
    }

    /**
     * Activates the key only when checkAvailable() allows it; an in-use key
     * is refused without calling the activate API.
     *
     * @return array{ok: bool, message: string, status: string, http_status: int, details: array<string, mixed>}
     */
    public function activateIfAvailable(string $key, string $orgName): array
    {
        $check = $this->checkAvailable($key);

        return $check['ok'] ? $this->activate($key, $orgName) : $check;
    }

    /**
     * Links the licence to this console and organisation. ok is true when
     * atglance.live reports the licence "in_use" (201 new link, 200 same console).
     *
     * http_status is 0 when atglance.live could not be reached.
     *
     * @return array{ok: bool, message: string, status: string, http_status: int, details: array<string, mixed>}
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
                'http_status' => $response->status(),
                'details' => $this->details($body),
            ];
        }

        return $this->failure($this->errorMessage($response, $body, $status), $status, $response->status());
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
            if (in_array($key, ['key', 'license_key', 'token', 'message', 'valid', 'status', 'user'], true)) {
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

    private function failure(string $message, string $status = '', int $httpStatus = 0): array
    {
        return ['ok' => false, 'message' => $message, 'status' => $status, 'http_status' => $httpStatus, 'details' => []];
    }
}
