<?php

namespace Tests\Support;

use App\Support\License;
use App\Support\Licensing\LicenseStore;
use App\Support\Licensing\Verifier;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Licence files for tests, signed with the TEST key in tests/Fixtures/licensing (copied
 * from the workspace contracts/fixtures). Packs exactly as contracts/LICENSE_FORMAT.md.
 */
final class TestLicense
{
    public const KEY_ID = 'test-2026';

    public static function fixtureDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/licensing';
    }

    /**
     * @return array<string, array{public_key: string, enc_key: string}> encoded keyring for config('licensing.keyring')
     */
    public static function keyring(): array
    {
        $key = self::key(self::KEY_ID);

        return [self::KEY_ID => ['public_key' => $key['ed25519_public_b64'], 'enc_key' => $key['aes_key_hex']]];
    }

    /**
     * A licence file issued at $issuedAt (default now).
     *
     * @param array<string, mixed> $overrides payload fields to change
     */
    public static function issue(array $overrides = [], ?\DateTimeInterface $issuedAt = null): string
    {
        $issued = \DateTimeImmutable::createFromInterface($issuedAt ?? now('UTC'));
        $payload = array_merge([
            'format_version' => 1,
            'license_id' => 'LIC-' . Str::uuid(),
            'product' => 'atglance-console',
            'key_id' => self::KEY_ID,
            'customer' => 'Test Org',
            'email' => 'owner@example.test',
            'plan' => 'enterprise',
            'seats' => 10,
            'features' => ['sso'],
            'issued_at' => Verifier::formatDate($issued),
            'activate_before' => Verifier::formatDate($issued->modify('+3 hours')),
            'expires_at' => Verifier::formatDate($issued->modify('+365 days')),
            'min_version' => '2.0.0',
            'install_id' => null,
        ], $overrides);

        return self::pack(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) $payload['key_id']);
    }

    /**
     * The default licence for tests: issued in 2020 and valid until 2100, so tests that move
     * the clock (forwards or back) still run with an active licence. Activated at its issue
     * time, inside the 3-hour window.
     */
    public static function installLongLived(): void
    {
        $issued = new DateTimeImmutable('2020-01-01T00:00:00Z');
        $bytes = self::issue(['expires_at' => '2100-01-01T00:00:00Z'], $issued);

        $previous = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::instance($issued)->addMinute());
        try {
            License::activate($bytes);
        } finally {
            Carbon::setTestNow($previous);
            License::reset();
        }
    }

    /** Issues a licence now and stores it as this console's active licence. */
    public static function install(array $overrides = []): string
    {
        $bytes = self::issue($overrides);
        License::activate($bytes);

        return $bytes;
    }

    public static function store(): LicenseStore
    {
        return LicenseStore::make();
    }

    public static function pack(string $payload, string $keyId = self::KEY_ID): string
    {
        $key = self::key(self::KEY_ID);
        $pair = sodium_crypto_sign_seed_keypair(self::seed($key['ed25519_private_pem']));
        $header = 'ATGL' . chr(1) . chr(strlen($keyId)) . $keyId;
        $signature = sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair));
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(pack('n', strlen($signature)) . $signature . $payload, 'aes-256-gcm', hex2bin($key['aes_key_hex']), OPENSSL_RAW_DATA, $iv, $tag, $header, 16);

        return $header . $iv . $tag . $ciphertext;
    }

    /**
     * @return array{key_id: string, ed25519_private_pem: string, ed25519_public_b64: string, aes_key_hex: string}
     */
    public static function key(string $keyId): array
    {
        $keys = json_decode((string) file_get_contents(self::fixtureDir() . '/keys.json'), true)['keys'] ?? [];
        foreach ($keys as $key) {
            if ($key['key_id'] === $keyId) {
                return $key;
            }
        }
        throw new RuntimeException("No test key $keyId in keys.json");
    }

    private static function seed(string $pem): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        return substr((string) $der, -32);
    }
}
