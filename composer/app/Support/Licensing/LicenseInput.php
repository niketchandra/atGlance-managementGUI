<?php

namespace App\Support\Licensing;

use App\Services\LicenseClient;
use Illuminate\Http\Request;

/**
 * The licence a form submitted, as licence file bytes: either an uploaded .atgl file
 * (license_mode=file, consoles without internet access) or a licence key activated online
 * (license_mode=key), for which atglance.live returns the licence file. Used by the installer
 * and Admin Settings > Licence; both then verify and store the bytes the same way.
 */
final class LicenseInput
{
    public const MODE_KEY = 'key';
    public const MODE_FILE = 'file';

    public static function mode(Request $request): string
    {
        return $request->input('license_mode') === self::MODE_KEY ? self::MODE_KEY : self::MODE_FILE;
    }

    /** Whether the form carries a licence at all (a key in key mode, any file in file mode). */
    public static function provided(Request $request): bool
    {
        return self::mode($request) === self::MODE_KEY
            ? trim((string) $request->input('license_key', '')) !== ''
            : $request->hasFile('license_file');
    }

    /**
     * @return array{bytes: ?string, field: string, error: ?string}
     */
    public static function resolve(Request $request, LicenseClient $client, string $orgName): array
    {
        if (self::mode($request) === self::MODE_KEY) {
            $key = trim((string) $request->input('license_key', ''));
            if ($key === '') {
                return ['bytes' => null, 'field' => 'license_key', 'error' => 'Enter your licence key from atglance.live.'];
            }
            $result = $client->activate($key, $orgName);

            return $result['ok']
                ? ['bytes' => $result['bytes'], 'field' => 'license_key', 'error' => null]
                : ['bytes' => null, 'field' => 'license_key', 'error' => $result['message']];
        }

        $bytes = self::uploadedFile($request);

        return $bytes === null
            ? ['bytes' => null, 'field' => 'license_file', 'error' => 'Choose the .atgl licence file from atglance.live (at most 64 KB).']
            : ['bytes' => $bytes, 'field' => 'license_file', 'error' => null];
    }

    /** The uploaded licence file's bytes, or null when missing, too large or not .atgl. */
    public static function uploadedFile(Request $request): ?string
    {
        $file = $request->file('license_file');
        if (!$file || !$file->isValid() || $file->getSize() > 65536
            || strtolower((string) $file->getClientOriginalExtension()) !== 'atgl') {
            return null;
        }

        $bytes = @file_get_contents($file->getRealPath());

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
