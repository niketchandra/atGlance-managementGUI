<?php

namespace App\Support;

use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Per-user preferences stored in users.preferences (JSON), with defaults,
 * and date formatting in the signed-in user's time zone and format.
 */
class UserPreferences
{
    /**
     * key => [date-and-time format, date-only format, example label]
     */
    public const DATE_FORMATS = [
        'us_short' => ['M j, Y g:i A', 'M j, Y', 'Sep 27, 2026 2:05 PM'],
        'day_first' => ['j M Y H:i', 'j M Y', '27 Sep 2026 14:05'],
        'iso' => ['Y-m-d H:i', 'Y-m-d', '2026-09-27 14:05'],
        'us_numeric' => ['m/d/Y g:i A', 'm/d/Y', '09/27/2026 2:05 PM'],
    ];

    public const PAGE_SIZES = [10, 25, 50];

    /**
     * Account email alerts: key => [label, description, can be turned off].
     */
    public const ALERTS = [
        'new_signin' => ['New sign-in', 'A sign-in from a browser or IP address not seen before on your account.', true],
        'password_changed' => ['Password changed', 'Always sent, so you notice if someone else changed it.', false],
        'api_keys' => ['API keys', 'An API key was created or revoked.', true],
        'system_deregistered' => ['System deregistered', 'One of your systems was deregistered.', true],
    ];

    public const DEFAULTS = [
        'timezone' => null,
        'date_format' => 'us_short',
        'per_page' => 25,
        'alerts' => [
            'new_signin' => true,
            'password_changed' => true,
            'api_keys' => true,
            'system_deregistered' => true,
        ],
    ];

    public static function all(?User $user): array
    {
        $saved = is_array($user?->preferences) ? $user->preferences : [];
        $prefs = array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $prefs['alerts'] = array_replace(self::DEFAULTS['alerts'], array_intersect_key((array) ($saved['alerts'] ?? []), self::DEFAULTS['alerts']));
        $prefs['alerts']['password_changed'] = true;

        if (!isset(self::DATE_FORMATS[$prefs['date_format']])) {
            $prefs['date_format'] = self::DEFAULTS['date_format'];
        }
        if (!in_array((int) $prefs['per_page'], self::PAGE_SIZES, true)) {
            $prefs['per_page'] = self::DEFAULTS['per_page'];
        }
        if (!self::isValidTimezone($prefs['timezone'])) {
            $prefs['timezone'] = null;
        }

        return $prefs;
    }

    public static function get(?User $user, string $key): mixed
    {
        return self::all($user)[$key];
    }

    public static function wantsAlert(User $user, string $alert): bool
    {
        return (bool) (self::all($user)['alerts'][$alert] ?? false);
    }

    public static function save(User $user, array $values): void
    {
        $user->forceFill(['preferences' => array_replace(self::all($user), $values)])->save();
    }

    /**
     * The time zone dates are shown in: the user's choice, else the app's.
     */
    public static function timezone(?User $user = null): string
    {
        return self::get($user ?? Auth::user(), 'timezone') ?? (string) config('app.timezone', 'UTC');
    }

    public static function isValidTimezone(mixed $timezone): bool
    {
        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Date and time in the signed-in user's zone and format, e.g. "Sep 27, 2026 2:05 PM".
     */
    public static function datetime(mixed $value): string
    {
        return self::render($value, 0);
    }

    /**
     * Date only, e.g. "Sep 27, 2026".
     */
    public static function date(mixed $value): string
    {
        return self::render($value, 1);
    }

    private static function render(mixed $value, int $formatIndex): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $user = Auth::user();
        $format = self::DATE_FORMATS[self::get($user, 'date_format')][$formatIndex];

        return Carbon::parse($value)->setTimezone(self::timezone($user))->format($format);
    }
}
