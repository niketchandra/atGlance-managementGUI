<?php

namespace App\Http\Controllers;

use App\Services\LicenseClient;
use App\Support\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Licence tab of /admin/settings: verifies a licence key with atglance.live
 * and saves it once the API reports status "in_use".
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
        $result = $client->verify($key);

        if (!$result['ok']) {
            return $back->withErrors(['license_key' => $result['message']]);
        }

        License::store($key, $result);

        return $back->with('success', 'Licence verified and saved.');
    }
}
