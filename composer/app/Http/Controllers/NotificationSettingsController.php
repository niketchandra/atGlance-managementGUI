<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Support\NotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Notification tab of /admin/settings: which channels workspace admins may
 * use, and the organization-level credentials. Super admin only.
 */
class NotificationSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'notification']);

        if ((int) Auth::user()->rbac_id !== 100) {
            return $back->withErrors(['notification' => 'Only the super admin can change notification channels.']);
        }

        $rules = ['allowed' => ['nullable', 'array'], 'allowed.*' => ['nullable', 'boolean']];
        foreach (NotificationSettings::CREDENTIALS as $credentials) {
            foreach (array_keys($credentials) as $key) {
                $rules[$key] = ['nullable', 'string', 'max:2000'];
            }
        }
        $rules['notify_sms_from'] = ['nullable', 'regex:/^\+[1-9]\d{6,14}$/'];
        $validated = $request->validate($rules, [
            'notify_sms_from.regex' => 'The SMS from number must be in E.164 format, e.g. +14155550100.',
        ]);

        foreach (NotificationSettings::CHANNELS as $channel => $meta) {
            $allowed = $meta['available'] && filter_var($validated['allowed'][$channel] ?? false, FILTER_VALIDATE_BOOL);
            AdminSetting::putValue('notification', 'notify_' . $channel . '_allowed', $allowed ? 'true' : 'false');
        }

        foreach (NotificationSettings::CREDENTIALS as $credentials) {
            foreach ($credentials as $key => $meta) {
                $value = trim((string) ($validated[$key] ?? ''));

                // A blank secret field keeps the saved secret; a "clear" box removes it.
                if ($meta['secret'] && $value === '' && !$request->boolean('clear.' . $key)) {
                    continue;
                }

                AdminSetting::putValue('notification', $key, $value, $meta['secret'] && $value !== '');
            }
        }

        return $back->with('success', 'Notification channels saved.');
    }
}
