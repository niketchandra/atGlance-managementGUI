<?php

namespace App\Support\Licensing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;

/**
 * Offline verifier for .atgl licence files, exactly as specified in
 * contracts/LICENSE_FORMAT.md (copy in docs/LICENSING.md). No network, no framework:
 * only sodium (Ed25519) and openssl (AES-256-GCM).
 *
 * Never logs or returns the decrypted payload except as the verified LicenseData.
 */
final class Verifier
{
    public const MODE_ACTIVATION = 'activation';
    public const MODE_STARTUP = 'startup';

    public const PRODUCT = 'atglance-console';
    /** File layout version (header byte). The payload has its own format_version, 1 or 2. */
    public const FORMAT_VERSION = 1;
    public const ISSUED_TOLERANCE_SECONDS = 600;
    public const ACTIVATION_WINDOW_SECONDS = 10800;

    private const MAGIC = 'ATGL';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const SIG_BYTES = 64;
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Payload versions this verifier reads. */
    public const PAYLOAD_VERSIONS = [1, 2];

    /** v2: allowed activation window (activate_before - issued_at), in seconds. */
    public const MIN_WINDOW_SECONDS = 300;
    public const MAX_WINDOW_SECONDS = 10800;

    private const FEATURE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const POLICY_ID_PATTERN = '/^[A-Za-z0-9:._-]{1,64}$/';

    /**
     * @param array<string, array{public_key: string, enc_key: string}> $keyring key_id => raw 32-byte keys
     * @param list<array{key: string, v1_default?: bool}> $catalog the feature catalog (FEATURES.md)
     */
    public function __construct(private readonly array $keyring, private readonly array $catalog = [])
    {
    }

    /**
     * The feature catalog from a features.json file (contracts/fixtures/features.json).
     *
     * @return list<array{key: string, name: string, description: string, v1_default: bool}>
     */
    public static function catalogFromFile(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return array_values(array_filter(
            is_array($data['features'] ?? null) ? $data['features'] : [],
            fn ($f) => is_array($f) && is_string($f['key'] ?? null),
        ));
    }

    /**
     * Builds a keyring from encoded entries: key_id => {public_key: base64, enc_key: hex}.
     * Malformed entries are skipped (a file naming them then fails with E_KEY_ID).
     *
     * @param array<string, array{public_key?: string, enc_key?: string}> $encoded
     * @return array<string, array{public_key: string, enc_key: string}>
     */
    public static function keyring(array $encoded): array
    {
        $ring = [];
        foreach ($encoded as $keyId => $entry) {
            $public = base64_decode((string) ($entry['public_key'] ?? ''), true);
            $enc = ctype_xdigit((string) ($entry['enc_key'] ?? '')) ? hex2bin((string) $entry['enc_key']) : false;
            if (is_string($public) && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && is_string($enc) && strlen($enc) === 32) {
                $ring[(string) $keyId] = ['public_key' => $public, 'enc_key' => $enc];
            }
        }

        return $ring;
    }

    /**
     * @throws LicenseException with the first failing check's code
     */
    public function verify(
        string $bytes,
        string $mode,
        DateTimeInterface $now,
        string $appVersion,
        ?string $installId,
    ): LicenseData {
        // 1-3: header
        if (strlen($bytes) < 6) {
            throw new LicenseException(LicenseException::FORMAT);
        }
        if (!hash_equals(self::MAGIC, substr($bytes, 0, 4))) {
            throw new LicenseException(LicenseException::MAGIC);
        }
        if (ord($bytes[4]) !== self::FORMAT_VERSION) {
            throw new LicenseException(LicenseException::VERSION);
        }
        $kidLength = ord($bytes[5]);
        $headerLength = 6 + $kidLength;
        if ($kidLength < 1 || $kidLength > 64 || strlen($bytes) < $headerLength + self::IV_BYTES + self::TAG_BYTES + 1) {
            throw new LicenseException(LicenseException::FORMAT);
        }
        $keyId = substr($bytes, 6, $kidLength);
        if (preg_match('/^[A-Za-z0-9._-]+$/', $keyId) !== 1) {
            throw new LicenseException(LicenseException::FORMAT);
        }

        // 4: key
        $key = $this->keyring[$keyId] ?? null;
        if ($key === null) {
            throw new LicenseException(LicenseException::KEY_ID);
        }

        // 5: AES-256-GCM, header as AAD
        $header = substr($bytes, 0, $headerLength);
        $iv = substr($bytes, $headerLength, self::IV_BYTES);
        $tag = substr($bytes, $headerLength + self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($bytes, $headerLength + self::IV_BYTES + self::TAG_BYTES);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key['enc_key'], OPENSSL_RAW_DATA, $iv, $tag, $header);
        if (!is_string($plain)) {
            throw new LicenseException(LicenseException::DECRYPT);
        }

        // 6-7: signature
        if (strlen($plain) < 2) {
            throw new LicenseException(LicenseException::FORMAT);
        }
        $sigLength = unpack('n', substr($plain, 0, 2))[1];
        if ($sigLength !== self::SIG_BYTES || strlen($plain) < 2 + self::SIG_BYTES + 2) {
            throw new LicenseException(LicenseException::FORMAT);
        }
        $signature = substr($plain, 2, self::SIG_BYTES);
        $payload = substr($plain, 2 + self::SIG_BYTES);
        if (!sodium_crypto_sign_verify_detached($signature, $payload, $key['public_key'])) {
            throw new LicenseException(LicenseException::SIGNATURE);
        }

        // 8-9: payload
        $data = $this->parsePayload($payload, $keyId);
        if ($data->product !== self::PRODUCT) {
            throw new LicenseException(LicenseException::PRODUCT);
        }

        // 10-12: dates
        $nowTs = $now->getTimestamp();
        if ($data->issuedAt->getTimestamp() > $nowTs + self::ISSUED_TOLERANCE_SECONDS) {
            throw new LicenseException(LicenseException::ISSUED_FUTURE);
        }
        if ($mode === self::MODE_ACTIVATION && $nowTs > $data->activateBefore->getTimestamp()) {
            throw new LicenseException(LicenseException::ACTIVATION_WINDOW);
        }
        if ($nowTs >= $data->expiresAt->getTimestamp()) {
            throw new LicenseException(LicenseException::EXPIRED);
        }

        // 13: console version
        if (self::compareVersions($appVersion, $data->minVersion) < 0) {
            throw new LicenseException(LicenseException::MIN_VERSION);
        }

        // 14: installation binding
        if ($data->installId !== null && !hash_equals($data->installId, strtolower(trim((string) $installId)))) {
            throw new LicenseException(LicenseException::INSTALL_ID);
        }

        return $data;
    }

    public static function formatDate(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** <0, 0 or >0 like strcmp; compares MAJOR.MINOR.PATCH and ignores suffixes like "-dev". */
    public static function compareVersions(string $a, string $b): int
    {
        $parse = static function (string $v): array {
            preg_match('/^\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $v, $m);

            return [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
        };

        return $parse($a) <=> $parse($b);
    }

    private function parsePayload(string $payload, string $headerKeyId): LicenseData
    {
        try {
            $p = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LicenseException(LicenseException::PAYLOAD);
        }

        $version = is_array($p) ? ($p['format_version'] ?? null) : null;
        if (is_int($version) && $version > max(self::PAYLOAD_VERSIONS)) {
            throw new LicenseException(LicenseException::VERSION);
        }
        $v2 = $version === 2;

        $ok = is_array($p)
            && in_array($version, self::PAYLOAD_VERSIONS, true)
            && is_string($p['license_id'] ?? null) && preg_match('/^LIC-[0-9a-f-]{36}$/', $p['license_id']) === 1
            && is_string($p['product'] ?? null)
            && is_string($p['key_id'] ?? null) && hash_equals($headerKeyId, $p['key_id'])
            && is_string($p['customer'] ?? null) && $p['customer'] !== '' && mb_strlen($p['customer']) <= 255
            && is_string($p['email'] ?? null)
            && is_string($p['plan'] ?? null) && $p['plan'] !== ''
            && is_array($p['features'] ?? null) && array_is_list($p['features'])
            && count(array_filter($p['features'], 'is_string')) === count($p['features'])
            && ($v2
                ? is_int($p['max_nodes'] ?? null) && $p['max_nodes'] >= 0
                    && is_int($p['max_users'] ?? null) && $p['max_users'] >= 0
                    && is_string($p['policy_id'] ?? null) && preg_match(self::POLICY_ID_PATTERN, $p['policy_id']) === 1
                    && in_array($p['policy_type'] ?? null, ['default', 'custom'], true)
                    && is_int($p['validity_months'] ?? null) && $p['validity_months'] >= 1 && $p['validity_months'] <= 120
                    && count(array_filter($p['features'], fn ($f) => preg_match(self::FEATURE_PATTERN, $f) === 1)) === count($p['features'])
                : is_int($p['seats'] ?? null) && $p['seats'] >= 1)
            && is_string($p['min_version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $p['min_version']) === 1
            && array_key_exists('install_id', $p)
            && ($p['install_id'] === null || (is_string($p['install_id']) && preg_match(self::UUID_PATTERN, strtolower($p['install_id'])) === 1));
        if (!$ok) {
            throw new LicenseException(LicenseException::PAYLOAD);
        }

        $issued = self::parseDate($p['issued_at'] ?? null);
        $activateBefore = self::parseDate($p['activate_before'] ?? null);
        $expires = self::parseDate($p['expires_at'] ?? null);
        $window = $activateBefore->getTimestamp() - $issued->getTimestamp();
        $windowOk = $v2
            ? $window >= self::MIN_WINDOW_SECONDS && $window <= self::MAX_WINDOW_SECONDS
            : $window === self::ACTIVATION_WINDOW_SECONDS;
        if (!$windowOk || $expires->getTimestamp() <= $issued->getTimestamp()) {
            throw new LicenseException(LicenseException::PAYLOAD);
        }

        return new LicenseData(
            licenseId: $p['license_id'],
            product: $p['product'],
            keyId: $p['key_id'],
            customer: $p['customer'],
            email: $p['email'],
            plan: $p['plan'],
            features: $v2 ? $this->knownFeatures($p['features']) : $this->v1Features(),
            issuedAt: $issued,
            activateBefore: $activateBefore,
            expiresAt: $expires,
            minVersion: $p['min_version'],
            installId: $p['install_id'] === null ? null : strtolower($p['install_id']),
            formatVersion: $version,
            // v1 defaults: LICENSE_FORMAT.md, "Limits and features of a version 1 payload".
            maxNodes: $v2 ? $p['max_nodes'] : 0,
            maxUsers: $v2 ? $p['max_users'] : $p['seats'],
            policyId: $v2 ? $p['policy_id'] : 'v1',
            policyType: $v2 ? $p['policy_type'] : 'default',
            validityMonths: $v2 ? $p['validity_months'] : null,
        );
    }

    /**
     * The listed keys this console knows, sorted, without duplicates. Unknown keys are ignored.
     *
     * @param list<string> $features
     * @return list<string>
     */
    private function knownFeatures(array $features): array
    {
        $known = array_column($this->catalog, 'key');
        $keys = array_values(array_unique(array_intersect($features, $known)));
        sort($keys);

        return $keys;
    }

    /** @return list<string> catalog keys marked v1_default, sorted */
    private function v1Features(): array
    {
        $keys = array_column(array_filter($this->catalog, fn ($f) => (bool) ($f['v1_default'] ?? false)), 'key');
        sort($keys);

        return array_values($keys);
    }

    private static function parseDate(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new LicenseException(LicenseException::PAYLOAD);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || self::formatDate($date) !== $value) {
            throw new LicenseException(LicenseException::PAYLOAD);
        }

        return $date;
    }
}
