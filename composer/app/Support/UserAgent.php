<?php

namespace App\Support;

/**
 * Turns a User-Agent header into "Chrome on Windows". Covers the browsers,
 * systems and command-line clients AtGlance sees; anything else is "Unknown".
 */
class UserAgent
{
    private const BROWSERS = [
        '/\bEdg(?:e|A|iOS)?\//' => 'Edge',
        '/\b(?:OPR|Opera)\//' => 'Opera',
        '/\bFirefox\//' => 'Firefox',
        '/\bSamsungBrowser\//' => 'Samsung Internet',
        '/\b(?:Chrome|CriOS)\//' => 'Chrome',
        '/\bVersion\/[\d.]+.*Safari\//' => 'Safari',
        '/^atglance/i' => 'atglance CLI',
        '/^python-requests\//i' => 'atglance CLI',
        '/^curl\//i' => 'curl',
    ];

    private const SYSTEMS = [
        '/Windows NT/' => 'Windows',
        '/iPhone|iPad|iPod/' => 'iOS',
        '/Mac OS X|Macintosh/' => 'macOS',
        '/Android/' => 'Android',
        '/CrOS/' => 'ChromeOS',
        '/Linux/' => 'Linux',
    ];

    public static function describe(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return 'Unknown device';
        }

        $browser = self::match(self::BROWSERS, $userAgent);
        $system = self::match(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => $browser . ' on ' . $system,
            $browser !== null => $browser,
            $system !== null => 'Unknown browser on ' . $system,
            default => 'Unknown device',
        };
    }

    private static function match(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
