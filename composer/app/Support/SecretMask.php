<?php

namespace App\Support;

/**
 * Masks secrets for display in the web console. A system key (validation_hash)
 * shows as "••••••••" plus its last 4 characters; keys shorter than 12 characters
 * show only the mask, so the visible part never gives away most of the key.
 *
 * Provider-specific masking detects and masks:
 * - OpenAI API keys (sk-proj-* or sk-*)
 * - Anthropic/Claude API keys (sk-ant-*)
 * - Google Gemini API keys (AIza*)
 * - Generic API keys (anything else)
 * - URLs and base URLs
 */
final class SecretMask
{
    public const MASK = '••••••••';

    /**
     * Mask a system key (original method for backward compatibility).
     * Shows as "••••••••" plus last 4 characters; short keys show only the mask.
     */
    public static function key(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        return mb_strlen($value) < 12 ? self::MASK : self::MASK . mb_substr($value, -4);
    }

    /**
     * Automatically detect the secret type and mask it appropriately.
     * This is the main entry point for masking any secret.
     */
    public static function mask(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        // Auto-detect the type and apply appropriate masking
        if (self::isOpenAIKey($value)) {
            return self::maskOpenAI($value);
        }

        if (self::isAnthropicKey($value)) {
            return self::maskAnthropic($value);
        }

        if (self::isGeminiKey($value)) {
            return self::maskGemini($value);
        }

        if (self::isUrl($value)) {
            return self::maskUrl($value);
        }

        // Default to generic masking
        return self::maskGeneric($value);
    }

    /**
     * Mask an OpenAI API key (sk-proj-* or sk-*).
     * Shows the prefix and last 4 characters.
     */
    public static function maskOpenAI(?string $value): string
    {
        $value = (string) $value;

        if ($value === '' || !self::isOpenAIKey($value)) {
            return $value;
        }

        // Extract the prefix (sk-proj or sk-)
        if (str_starts_with($value, 'sk-proj-')) {
            $prefix = 'sk-proj-';
        } else {
            $prefix = 'sk-';
        }

        return self::maskWithPrefix($value, $prefix);
    }

    /**
     * Mask an Anthropic/Claude API key (sk-ant-*).
     * Shows the prefix and last 4 characters.
     */
    public static function maskAnthropic(?string $value): string
    {
        $value = (string) $value;

        if ($value === '' || !self::isAnthropicKey($value)) {
            return $value;
        }

        // Anthropic keys always start with sk-ant-
        $prefix = 'sk-ant-';

        return self::maskWithPrefix($value, $prefix);
    }

    /**
     * Mask a Google Gemini API key (AIza*).
     * Shows the prefix and last 4 characters.
     */
    public static function maskGemini(?string $value): string
    {
        $value = (string) $value;

        if ($value === '' || !self::isGeminiKey($value)) {
            return $value;
        }

        // Gemini keys start with AIzaSy
        $prefix = 'AIzaSy';

        return self::maskWithPrefix($value, $prefix);
    }

    /**
     * Mask a URL (for Ollama, custom providers, etc.).
     * URLs are not heavily masked since they're not as sensitive as keys,
     * but we ensure no auth tokens in the path are exposed.
     */
    public static function maskUrl(?string $value): string
    {
        $value = (string) $value;

        if ($value === '' || !self::isUrl($value)) {
            return $value;
        }

        // URLs are mostly OK to show, just validate they don't contain embedded keys
        // Remove any query parameters that might contain auth tokens
        if (str_contains($value, '?')) {
            $parts = explode('?', $value);
            return $parts[0] . '?...';
        }

        return $value;
    }

    /**
     * Mask a generic API key.
     * Shows only the last 4 characters with a mask prefix.
     */
    public static function maskGeneric(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        return mb_strlen($value) < 12 ? self::MASK : self::MASK . mb_substr($value, -4);
    }

    /**
     * Mask secrets within a text string (like error messages).
     * Finds and masks all detected secret patterns in the text.
     */
    public static function maskInText(?string $text): string
    {
        $text = (string) $text;

        if ($text === '') {
            return '';
        }

        // Pattern for OpenAI keys (sk-proj-* or sk-*)
        $text = preg_replace_callback(
            '/sk-proj-[a-zA-Z0-9_-]+/',
            fn ($match) => self::maskOpenAI($match[0]),
            $text
        );

        // Pattern for Anthropic keys (sk-ant-*)
        $text = preg_replace_callback(
            '/sk-ant-[a-zA-Z0-9_-]+/',
            fn ($match) => self::maskAnthropic($match[0]),
            $text
        );

        // Pattern for Gemini keys (AIza*)
        $text = preg_replace_callback(
            '/AIza[a-zA-Z0-9_-]{35,}/',
            fn ($match) => self::maskGemini($match[0]),
            $text
        );

        // Pattern for generic keys (word-key-... patterns)
        $text = preg_replace_callback(
            '/(?:key|token|secret|password)[-_=:\s]*[a-zA-Z0-9_\-\.]{20,}/i',
            fn ($match) => self::maskGeneric($match[0]),
            $text
        );

        return $text;
    }

    /**
     * Determine if a string is an OpenAI API key.
     */
    private static function isOpenAIKey(string $value): bool
    {
        return str_starts_with($value, 'sk-proj-') || (str_starts_with($value, 'sk-') && !str_starts_with($value, 'sk-ant-'));
    }

    /**
     * Determine if a string is an Anthropic/Claude API key.
     */
    private static function isAnthropicKey(string $value): bool
    {
        return str_starts_with($value, 'sk-ant-');
    }

    /**
     * Determine if a string is a Google Gemini API key.
     */
    private static function isGeminiKey(string $value): bool
    {
        return str_starts_with($value, 'AIza') && mb_strlen($value) >= 39;
    }

    /**
     * Determine if a string is a URL.
     */
    private static function isUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * Generic helper to mask a key by showing a prefix and last 4 characters.
     */
    private static function maskWithPrefix(string $value, string $prefix): string
    {
        $len = mb_strlen($value);

        if ($len < 12) {
            // Short keys: just show the prefix and mask the rest
            return $prefix . self::MASK;
        }

        // Long keys: show prefix and last 4 characters
        $lastFour = mb_substr($value, -4);

        return $prefix . self::MASK . $lastFour;
    }
}
