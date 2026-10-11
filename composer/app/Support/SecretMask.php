<?php

namespace App\Support;

/**
 * Masks secrets for display in the web console. A system key (validation_hash)
 * shows as "••••••••" plus its last 4 characters; keys shorter than 12 characters
 * show only the mask, so the visible part never gives away most of the key.
 */
final class SecretMask
{
    public const MASK = '••••••••';

    public static function key(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        return mb_strlen($value) < 12 ? self::MASK : self::MASK . mb_substr($value, -4);
    }
}
