<?php

namespace App\Support\Licensing;

use Illuminate\Http\Request;

/**
 * The licence file (.atgl) a form uploaded, for the installer and Admin Settings > Licence.
 * Licences are licence files only.
 */
final class LicenseInput
{
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
