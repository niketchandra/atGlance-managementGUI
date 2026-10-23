<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\LicenseClient;
use App\Support\ActivityRecorder;
use App\Support\License;
use App\Support\Licensing\LicenseException;
use App\Support\Licensing\LicenseInput;
use App\Support\SiteProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Licence tab of /admin/settings: adds or renews the licence, with a licence key (console with
 * internet access: activated online once, atglance.live answers with the licence file) or a
 * licence file (.atgl, console without internet access). Either way the file is verified with
 * its activation window and replaces the stored one only when it is valid.
 */
class LicenseController extends Controller
{
    public function update(Request $request, LicenseClient $client): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'licence', 'licence_mode' => LicenseInput::mode($request)]);

        // Replacing a stored licence needs the user's password; the first licence does not.
        $replacing = License::hasStoredKey();
        $validated = $request->validate([
            'license_mode' => ['nullable', 'in:key,file'],
            'license_key' => ['nullable', 'string', 'max:512'],
            'license_file' => ['nullable', 'file', 'max:64'],
            'password' => [$replacing ? 'required' : 'nullable', 'string'],
        ], [
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

        $orgName = trim((string) Organization::query()->whereKey(SiteProfile::DEFAULT_ORGANIZATION_ID)->value('name'));
        $input = LicenseInput::resolve($request, $client, $orgName);
        if ($input['bytes'] === null) {
            return $back->withErrors([$input['field'] => $input['error']]);
        }

        try {
            $license = License::activate($input['bytes']);
        } catch (LicenseException $e) {
            ActivityRecorder::record(Auth::id(), 'license.upload_refused', 'Licence refused: ' . $e->errorCode, ActivityRecorder::FAILURE, $request);

            return $back->withErrors([$input['field'] => $e->display()]);
        }

        $how = LicenseInput::mode($request) === LicenseInput::MODE_KEY ? 'licence key' : 'licence file';
        ActivityRecorder::record(Auth::id(), $replacing ? 'license.replaced' : 'license.added', ($replacing ? 'Replaced the licence (' : 'Added a licence (') . $how . '): ' . $license->licenseId, ActivityRecorder::SUCCESS, $request);

        return $back->with('success', 'Licence accepted (' . $how . '). Licensed to ' . $license->customer . ' until ' . $license->expiresAt->format('M j, Y') . '.');
    }
}
