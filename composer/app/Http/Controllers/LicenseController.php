<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\LicenseClient;
use App\Support\ActivityRecorder;
use App\Support\License;
use App\Support\SiteProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Licence tab of /admin/settings: activates a licence key on atglance.live for
 * this console and organisation, and saves it once the API reports "in_use".
 */
class LicenseController extends Controller
{
    public function update(Request $request, LicenseClient $client): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'licence']);

        // Replacing a saved licence needs the user's password; the first licence does not.
        $replacing = License::hasStoredKey();
        $validated = $request->validate([
            'license_key' => ['required', 'string', 'max:512'],
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

        $key = trim((string) $validated['license_key']);
        $orgName = trim((string) Organization::query()->whereKey(SiteProfile::DEFAULT_ORGANIZATION_ID)->value('name'));
        $result = $client->activateIfAvailable($key, $orgName !== '' ? $orgName : config('app.name', 'AtGlance'));

        if (!$result['ok']) {
            return $back->withErrors(['license_key' => $result['message']]);
        }

        License::store($key, $result);
        ActivityRecorder::record(Auth::id(), $replacing ? 'license.replaced' : 'license.added', $replacing ? 'Replaced the licence' : 'Added a licence', ActivityRecorder::SUCCESS, $request);

        return $back->with('success', $replacing ? 'Licence replaced, activated and saved.' : 'Licence activated and saved.');
    }
}
