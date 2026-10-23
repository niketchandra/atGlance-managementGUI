<?php

namespace App\Http\Controllers;

use App\Support\ActivityRecorder;
use App\Support\License;
use App\Support\Licensing\LicenseException;
use App\Support\Licensing\LicenseInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Licence tab of /admin/settings: uploads a licence file (.atgl), the first one or a renewal.
 * The file is verified offline with its activation window and replaces the stored one only
 * when it is valid.
 */
class LicenseController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'licence']);

        // Replacing a stored licence needs the user's password; the first licence does not.
        $replacing = License::hasStoredKey();
        $validated = $request->validate([
            'license_file' => ['required', 'file', 'max:64'],
            'password' => [$replacing ? 'required' : 'nullable', 'string'],
        ], [
            'license_file.required' => 'Choose the .atgl licence file to upload.',
            'password.required' => 'Enter your password to replace the licence.',
        ]);

        if ($replacing) {
            $user = Auth::user();
            $passwordHash = $user->password_hash ?? $user->password;
            if (!$passwordHash || !Hash::check((string) $validated['password'], $passwordHash)) {
                ActivityRecorder::record($user->id, 'license.replace_denied', 'Licence replacement refused: wrong password', ActivityRecorder::FAILURE, $request);

                return $back->withErrors(['password' => 'Password is incorrect. The licence was not changed.']);
            }
        }

        $bytes = LicenseInput::uploadedFile($request);
        if ($bytes === null) {
            return $back->withErrors(['license_file' => 'Choose an .atgl licence file (at most 64 KB).']);
        }

        try {
            $license = License::activate($bytes);
        } catch (LicenseException $e) {
            ActivityRecorder::record(Auth::id(), 'license.upload_refused', 'Licence file refused: ' . $e->errorCode, ActivityRecorder::FAILURE, $request);

            return $back->withErrors(['license_file' => $e->display()]);
        }

        ActivityRecorder::record(Auth::id(), $replacing ? 'license.replaced' : 'license.added', ($replacing ? 'Replaced the licence: ' : 'Added a licence: ') . $license->licenseId, ActivityRecorder::SUCCESS, $request);

        return $back->with('success', 'Licence file accepted. Licensed to ' . $license->customer . ' until ' . $license->expiresAt->format('M j, Y') . '.');
    }
}
