<?php

namespace App\Support\Licensing;

use App\Support\License;

/**
 * Which features (FEATURES.md) the active licence enables. The only source is the verified
 * licence file: there is no database or config override. Without an active licence nothing is
 * enabled.
 */
final class Entitlements
{
    public const ERROR_CODE = 'FEATURE_NOT_LICENSED';

    /** @var list<array{key: string, name: string, description: string, v1_default: bool}>|null */
    private static ?array $catalog = null;

    /**
     * The feature catalog shipped with this console (resources/licensing/features.json, a copy
     * of the workspace contracts/fixtures/features.json).
     *
     * @return list<array{key: string, name: string, description: string, v1_default: bool}>
     */
    public static function catalog(): array
    {
        return self::$catalog ??= Verifier::catalogFromFile(resource_path('licensing/features.json'));
    }

    public static function isFeatureEnabled(string $key): bool
    {
        try {
            $status = License::status();
        } catch (\Throwable) {
            return false;
        }

        return $status['state'] === License::ACTIVE && $status['license']->hasFeature($key);
    }

    public static function name(string $key): string
    {
        foreach (self::catalog() as $feature) {
            if ($feature['key'] === $key) {
                return (string) $feature['name'];
            }
        }

        return $key;
    }

    public static function notLicensedMessage(string $key): string
    {
        return "'" . self::name($key) . "' is not included in your license.";
    }

    /**
     * Every catalog feature with its state, for Settings > Licence and the installer.
     *
     * @return list<array{key: string, name: string, description: string, enabled: bool}>
     */
    public static function overview(): array
    {
        return array_map(fn (array $f) => [
            'key' => $f['key'],
            'name' => (string) $f['name'],
            'description' => (string) ($f['description'] ?? ''),
            'enabled' => self::isFeatureEnabled($f['key']),
        ], self::catalog());
    }
}
