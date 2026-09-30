<?php

namespace App\Support;

use App\Models\AdminSetting;

/**
 * The organisation's own TLS certificate for the built-in proxy (HTTPS mode
 * "custom"). Stored encrypted in admin_settings; served to Caddy by
 * DomainTlsController::certificate().
 */
final class CustomCertificate
{
    private const KEY = 'custom_cert';

    public static function fromPem(string $certPem, string $keyPem, string $passphrase = ''): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $certPem, $matches);
        if (empty($matches[0])) {
            throw new InvalidCertificate('The certificate file is not a PEM certificate.');
        }
        $leaf = @openssl_x509_read($matches[0][0]);
        if ($leaf === false) {
            throw new InvalidCertificate('The certificate could not be read.');
        }
        $key = @openssl_pkey_get_private($keyPem, $passphrase === '' ? null : $passphrase);
        if ($key === false) {
            throw new InvalidCertificate('The private key could not be read. Check the key file and its passphrase.');
        }

        return self::build($leaf, array_slice($matches[0], 1), $key);
    }

    public static function fromPkcs12(string $data, string $password): array
    {
        $parts = [];
        if (!@openssl_pkcs12_read($data, $parts, $password)) {
            throw new InvalidCertificate('The .pfx/.p12 file could not be read. Check the password. Files exported with old encryption (RC2 or 3DES) must be exported again with AES.');
        }
        $leaf = @openssl_x509_read($parts['cert'] ?? '');
        $key = @openssl_pkey_get_private($parts['pkey'] ?? '');
        if ($leaf === false || $key === false) {
            throw new InvalidCertificate('The .pfx/.p12 file has no certificate or no private key.');
        }

        return self::build($leaf, $parts['extracerts'] ?? [], $key);
    }

    /** @return list<string> host names the certificate covers */
    public static function names(array $bundle): array
    {
        return $bundle['sans'] !== [] ? $bundle['sans'] : array_values(array_filter([strtolower((string) $bundle['subject'])]));
    }

    public static function covers(array $bundle, string $host): bool
    {
        $host = strtolower($host);
        foreach (self::names($bundle) as $name) {
            if ($name === $host) {
                return true;
            }
            $dot = strpos($host, '.');
            if (str_starts_with($name, '*.') && $dot !== false && substr($host, $dot + 1) === substr($name, 2)) {
                return true;
            }
        }

        return false;
    }

    public static function assertUsableFor(array $bundle, string $domain, ?int $now = null): void
    {
        $now ??= time();
        if (!self::covers($bundle, $domain)) {
            throw new InvalidCertificate('The certificate does not cover ' . $domain . '. It covers: ' . implode(', ', self::names($bundle)) . '.');
        }
        if ($bundle['not_before'] > $now) {
            throw new InvalidCertificate('The certificate is not valid yet. It is valid from ' . date('Y-m-d', $bundle['not_before']) . '.');
        }
        if ($bundle['not_after'] < $now) {
            throw new InvalidCertificate('The certificate expired on ' . date('Y-m-d', $bundle['not_after']) . '.');
        }
    }

    public static function store(array $bundle): void
    {
        AdminSetting::putValue('domain', self::KEY, $bundle, true);
    }

    public static function current(): ?array
    {
        try {
            $bundle = json_decode((string) AdminSetting::getValue(self::KEY, ''), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($bundle) && isset($bundle['cert_pem'], $bundle['key_pem']) ? $bundle : null;
    }

    public static function remove(): void
    {
        AdminSetting::query()->where('setting_key', self::KEY)->delete();
    }

    /** Leaf, chain, then key: the format Caddy's get_certificate http expects. */
    public static function pemBundle(array $bundle): string
    {
        return trim($bundle['cert_pem']) . "\n" . trim($bundle['key_pem']) . "\n";
    }

    public static function summary(array $bundle): array
    {
        return [
            'subject' => $bundle['subject'],
            'names' => self::names($bundle),
            'issuer' => $bundle['issuer'],
            'not_after' => date('Y-m-d', $bundle['not_after']),
            'fingerprint' => $bundle['fingerprint'],
            'days_left' => (int) floor(($bundle['not_after'] - time()) / 86400),
        ];
    }

    private static function build(\OpenSSLCertificate $leaf, array $chain, \OpenSSLAsymmetricKey $key): array
    {
        if (!openssl_x509_check_private_key($leaf, $key)) {
            throw new InvalidCertificate('The private key does not belong to this certificate.');
        }
        openssl_x509_export($leaf, $leafPem);
        openssl_pkey_export($key, $keyPem);
        $info = openssl_x509_parse($leaf) ?: [];

        $chainPem = '';
        foreach ($chain as $extra) {
            $chainPem .= trim((string) $extra) . "\n";
        }

        $sans = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $sans[] = strtolower(substr($entry, 4));
            }
        }

        return [
            'cert_pem' => trim($leafPem) . "\n" . $chainPem,
            'key_pem' => trim($keyPem) . "\n",
            'subject' => (string) ($info['subject']['CN'] ?? ''),
            'sans' => $sans,
            'issuer' => (string) ($info['issuer']['CN'] ?? $info['issuer']['O'] ?? ''),
            'not_before' => (int) ($info['validFrom_time_t'] ?? 0),
            'not_after' => (int) ($info['validTo_time_t'] ?? 0),
            'fingerprint' => strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($leaf, 'sha256'), 2))),
        ];
    }
}
