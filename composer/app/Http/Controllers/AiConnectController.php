<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Services\AiClient;
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
    public function update(Request $request): RedirectResponse
    {
        $back = redirect()->route('admin.settings', ['tab' => 'ai-connect']);

        if (!$this->isSuperAdmin()) {
            return $back->withErrors(['ai' => 'Only the super admin can change the AI connection.']);
        }

        $connection = $this->connectionFromRequest($request);
        $meta = AiSettings::PROVIDERS[$connection['provider']];
        $enabled = $request->boolean('ai_enabled');

        if ($enabled) {
            $missing = match (true) {
                $connection['model'] === '' => 'Enter a model name before enabling the AI connection.',
                $meta['key'] === 'required' && $connection['api_key'] === '' => 'Enter the API key for ' . $meta['label'] . ' before enabling the AI connection.',
                default => null,
            };
            if ($missing !== null) {
                return $back->withErrors(['ai' => $missing])->withInput($request->except('ai_api_key'));
            }
        }

        AdminSetting::putValue('ai', 'ai_enabled', $enabled ? 'true' : 'false');
        AdminSetting::putValue('ai', 'ai_provider', $connection['provider']);
        AdminSetting::putValue('ai', 'ai_base_url', $connection['base_url']);
        AdminSetting::putValue('ai', 'ai_model', $connection['model']);
        AdminSetting::putValue('ai', 'ai_api_key', $connection['api_key'], $connection['api_key'] !== '');

        return $back->with('success', 'AI connection saved.');
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
            $key = AiSettings::apiKey();
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
