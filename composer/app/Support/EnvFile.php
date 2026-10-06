<?php

namespace App\Support;

/**
 * Writes KEY=value pairs into the app .env (on the storage volume in
 * containers; /app/.env is a symlink to it).
 */
final class EnvFile
{
    public static function set(array $pairs, ?string $path = null): void
    {
        $path ??= base_path('.env');
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($pairs as $key => $value) {
            $pattern = '/^' . preg_quote((string) $key, '/') . '=.*/m';
            $line = $key . '=' . $value;

            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content);
            } else {
                $content .= ($content === '' || str_ends_with($content, "\n") ? '' : "\n") . $line . "\n";
            }
        }

        file_put_contents($path, $content);
    }
}
