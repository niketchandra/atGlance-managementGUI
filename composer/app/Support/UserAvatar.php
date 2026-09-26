<?php

namespace App\Support;

/**
 * Initials avatar for a user: first letter of the first name + first letter
 * of the last name. Works with User models and plain query rows.
 */
class UserAvatar
{
    public static function initials(object|array $user): string
    {
        $user = (object) $user;
        $first = self::clean($user->first_name ?? null);
        $last = self::clean($user->last_name ?? null);

        // Web sign-up and SSO only fill `name`, so first/last are used only when both are set.
        $words = ($first !== '' && $last !== '')
            ? [$first, $last]
            : self::words(self::clean($user->name ?? null) ?: trim($first . ' ' . $last));

        if ($words === []) {
            $local = strstr((string) ($user->email ?? ''), '@', true) ?: (string) ($user->email ?? '');
            $words = self::words($local);
        }

        if ($words === []) {
            return '?';
        }

        $initials = self::firstLetter($words[0]);
        if (count($words) > 1) {
            $initials .= self::firstLetter($words[count($words) - 1]);
        }

        return mb_strtoupper($initials);
    }

    private static function clean(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * Words that contain at least one letter or digit.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_values(array_filter(
            preg_split('/[\s._\-]+/u', $text) ?: [],
            fn (string $word) => preg_match('/[\p{L}\p{N}]/u', $word) === 1
        ));
    }

    private static function firstLetter(string $word): string
    {
        preg_match('/[\p{L}\p{N}]/u', $word, $match);

        return $match[0] ?? '';
    }
}
