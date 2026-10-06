<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Services\AiClient;
use App\Support\ActivityRecorder;
use App\Support\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * AI Connect tab of /admin/settings: the organization's AI provider
 * connection. Super admin only; admins see it read-only.
 */
class AiConnectController extends Controller
{
    public function togglePlugin(Request $request): RedirectResponse
    {
        abort_unless($this->isSuperAdmin(), 403);
        $enabled = $request->boolean('enabled');

        AdminSetting::putValue('ai', 'ai_plugin_enabled', $enabled ? 'true' : 'false');
        AdminSetting::putValue('ai', 'ai_enabled', $enabled ? 'true' : 'false');
        ActivityRecorder::record(Auth::id(), 'settings.ai_plugin', 'AI Connect plugin ' . ($enabled ? 'enabled' : 'disabled'));

        return redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 'ai'])->with('success', $enabled
            ? 'AI Connect is enabled. Choose and set up a provider on the AI Connect tab.'
            : 'AI Connect is disabled. No AI reviews run.');
    }

    /**
     * Saves every provider's settings (so switching providers loses nothing) and the active provider.
     * Everything entered is kept even when the active provider is incomplete; AI stays off until it is.
     */
    public function update(Request $request): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'ai-connect']);

        if (!$this->isSuperAdmin()) {
            return $back->withErrors(['ai' => 'Only the super admin can change the AI connection.']);
        }
        if (!AiSettings::pluginEnabled()) {
            return redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 'ai'])
                ->withErrors(['ai' => 'Enable AI Connect in the Plugins tab first.']);
        }

        $providers = array_keys(AiSettings::PROVIDERS);
        $request->validate([
            'ai_provider' => ['nullable', Rule::in($providers)],
            'ai_config' => ['nullable', 'array'],
            'ai_config.*' => ['array'],
            'ai_config.*.base_url' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/i'],
            'ai_config.*.model' => ['nullable', 'string', 'max:200'],
            'ai_key' => ['nullable', 'array'],
            'ai_key.*' => ['nullable', 'string', 'max:2000'],
            'ai_clear_key' => ['nullable', 'array'],
        ], [
            'ai_config.*.base_url.regex' => 'The base URL must start with http:// or https://.',
        ]);

        $config = [];
        $keys = [];
        foreach (AiSettings::PROVIDERS as $provider => $meta) {
            $savedUrl = AiSettings::baseUrlFor($provider);
            $savedKey = AiSettings::apiKeyFor($provider);
            $input = (array) $request->input('ai_config.' . $provider, []);
            $baseUrl = array_key_exists('base_url', $input) ? rtrim(trim((string) $input['base_url']), '/') : $savedUrl;
            $model = array_key_exists('model', $input) ? trim((string) $input['model']) : AiSettings::modelFor($provider);
            if ($baseUrl !== '' || $model !== '') {
                $config[$provider] = ['base_url' => $baseUrl, 'model' => $model];
            }

            if ($meta['key'] === 'none') {
                continue;
            }
            $key = trim((string) $request->input('ai_key.' . $provider, ''));
            if ($key === '' && !$request->boolean('ai_clear_key.' . $provider) && AiSettings::canReuseSavedKey($provider, $baseUrl)) {
                // A blank field keeps the saved key, but never for a changed URL: it would go to another host.
                $key = $savedKey;
            }
            if ($key !== '') {
                $keys[$provider] = $key;
            }
        }

        $active = (string) $request->input('ai_provider', AiSettings::provider());
        AiSettings::store($config, $keys, $active);

        ActivityRecorder::record(Auth::id(), 'settings.ai_updated', 'AI Connect: active provider ' . AiSettings::PROVIDERS[$active]['label']);

        $missing = AiSettings::missing($active);
        if ($missing !== []) {
            return $back
                ->withErrors(['ai_config.' . $active => AiSettings::PROVIDERS[$active]['label'] . ' is the active provider but is missing: ' . implode(', ', $missing) . '. Saved; AI reviews stay off until it is complete.'])
                ->withInput($request->except('ai_key'));
        }

        return $back->with('success', 'AI connection saved. Active provider: ' . AiSettings::PROVIDERS[$active]['label'] . '.');
    }

    /**
     * Tests the values in the form without saving them.
     */
    public function test(Request $request, AiClient $client): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Only the super admin can test the AI connection.'], 403);
        }

        $connection = $this->connectionFromRequest($request);
        $result = $client->test($connection);
        AiSettings::recordTest($result['ok'], $result['message'], $connection['provider'], $connection['model']);

        return response()->json($result);
    }

    public function models(Request $request, AiClient $client): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Only the super admin can load models.'], 403);
        }

        try {
            $models = $client->models($this->connectionFromRequest($request));
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json([
            'ok' => true,
            'models' => $models,
            'message' => $models === [] ? 'The provider did not list any models. Type the model name.' : count($models) . ' models found.',
        ]);
    }

    private function isSuperAdmin(): bool
    {
        return (int) Auth::user()->rbac_id === 100;
    }

    /**
     * Validated form values. A blank key field keeps the saved key, but only
     * for the same provider and URL; "Remove saved key" clears it.
     *
     * @return array{provider: string, base_url: string, model: string, api_key: string}
     */
    private function connectionFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'ai_provider' => ['required', Rule::in(array_keys(AiSettings::PROVIDERS))],
            'ai_base_url' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+$/i'],
            'ai_model' => ['nullable', 'string', 'max:200'],
            'ai_api_key' => ['nullable', 'string', 'max:2000'],
        ], [
            'ai_base_url.regex' => 'The base URL must start with http:// or https://.',
        ]);

        $provider = $validated['ai_provider'];
        $baseUrl = rtrim(trim((string) ($validated['ai_base_url'] ?? '')), '/');

        if ($baseUrl === '' && AiSettings::PROVIDERS[$provider]['base_url'] === null) {
            throw ValidationException::withMessages([
                'ai_base_url' => 'Enter the base URL for ' . AiSettings::PROVIDERS[$provider]['label'] . '.',
            ]);
        }

        $key = trim((string) ($validated['ai_api_key'] ?? ''));
        if ($key === '' && !$request->boolean('ai_clear_api_key') && AiSettings::canReuseSavedKey($provider, $baseUrl)) {
            $key = AiSettings::apiKeyFor($provider);
        }
        if (AiSettings::PROVIDERS[$provider]['key'] === 'none') {
            $key = '';
        }

        return [
            'provider' => $provider,
            'base_url' => $baseUrl,
            'model' => trim((string) ($validated['ai_model'] ?? '')),
            'api_key' => $key,
        ];
    }
}
