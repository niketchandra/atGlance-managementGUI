<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\LicenseClient;
use App\Support\License;
use App\Support\SiteProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Licence tab of /admin/settings: activates a licence key on atglance.live for
 * this console and organisation, and saves it once the API reports "in_use".
 */
class LicenseController extends Controller
{
    public function update(Request $request, LicenseClient $client): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'licence']);

        $validated = $request->validate([
            'license_key' => ['required', 'string', 'max:512'],
        ]);

        $key = trim((string) $validated['license_key']);
        $orgName = trim((string) Organization::query()->whereKey(SiteProfile::DEFAULT_ORGANIZATION_ID)->value('name'));
        $result = $client->activate($key, $orgName !== '' ? $orgName : config('app.name', 'AtGlance'));

        if (!$result['ok']) {
            return $back->withErrors(['license_key' => $result['message']]);
        }

        License::store($key, $result);

        return $back->with('success', 'Licence activated and saved.');
    }
}
