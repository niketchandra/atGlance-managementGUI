<?php

namespace App\Support;

use App\Models\AdminSetting;

/**
 * The organization's AI provider connection (AI Connect tab of /admin/settings).
 * Stored in admin_settings (group "ai"); the API key is encrypted.
 */
class AiSettings
{
    /**
     * Provider catalog.
     *
     * - protocol: "anthropic" (Messages API) or "openai" (Chat Completions).
     * - auth: how the key is sent: "x-api-key" (Anthropic), "api-key" (Azure) or "bearer".
     * - key: "required", "optional" or "none".
     * - base_url: default base URL; null means the admin must enter one.
     * - steps: setup instructions shown on the tab.
     */
    public const PROVIDERS = [
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'protocol' => 'anthropic',
            'auth' => 'x-api-key',
            'key' => 'required',
            'base_url' => 'https://api.anthropic.com',
            'model_hint' => 'e.g. claude-opus-5, claude-sonnet-5, claude-haiku-4-5',
            'steps' => [
                'Sign in at https://console.anthropic.com and add billing credits.',
                'Open Settings > API keys and create a key. Copy it now; it is shown only once.',
                'Paste the key below and choose a model.',
            ],
        ],
        'openai' => [
            'label' => 'OpenAI',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://api.openai.com/v1',
            'model_hint' => 'Any chat model your project can use',
            'steps' => [
                'Sign in at https://platform.openai.com and add a payment method.',
                'Create a key at https://platform.openai.com/api-keys (a project key is recommended).',
                'Make sure the project has access to the model you enter.',
            ],
        ],
        'azure' => [
            'label' => 'Azure OpenAI / Microsoft Foundry',
            'protocol' => 'openai',
            'auth' => 'api-key',
            'key' => 'required',
            'base_url' => null,
            'base_url_hint' => 'https://<resource>.openai.azure.com/openai/v1',
            'model_hint' => 'Your deployment name',
            'steps' => [
                'Create an Azure OpenAI (or Foundry) resource and deploy a model.',
                'Copy the endpoint and a key from Keys and Endpoint.',
                'Base URL: https://<resource>.openai.azure.com/openai/v1. Model: the deployment name, not the model family.',
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'model_hint' => 'e.g. a gemini-* model',
            'steps' => [
                'Create a key at https://aistudio.google.com/apikey.',
                'Keep the default base URL (the Gemini OpenAI-compatible endpoint).',
            ],
        ],
        'mistral' => [
            'label' => 'Mistral AI',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://api.mistral.ai/v1',
            'model_hint' => 'e.g. mistral-small-latest',
            'steps' => [
                'Create a key at https://console.mistral.ai (API Keys).',
                'Keep the default base URL.',
            ],
        ],
        'groq' => [
            'label' => 'Groq',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model_hint' => 'Any model listed in the Groq console',
            'steps' => [
                'Create a key at https://console.groq.com/keys.',
                'Keep the default base URL.',
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://api.deepseek.com/v1',
            'model_hint' => 'e.g. deepseek-chat',
            'steps' => [
                'Create a key at https://platform.deepseek.com/api_keys and top up the balance.',
                'Keep the default base URL.',
            ],
        ],
        'xai' => [
            'label' => 'xAI Grok',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://api.x.ai/v1',
            'model_hint' => 'e.g. a grok-* model',
            'steps' => [
                'Create a key at https://console.x.ai.',
                'Keep the default base URL.',
            ],
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'required',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model_hint' => 'vendor/model, e.g. anthropic/claude-sonnet-5',
            'steps' => [
                'Create a key at https://openrouter.ai/keys and add credits.',
                'Keep the default base URL. The model is written as vendor/model.',
            ],
        ],
        'ollama' => [
            'label' => 'Ollama (self-hosted)',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'optional',
            'base_url' => 'http://host.docker.internal:11434/v1',
            'model_hint' => 'A pulled model, e.g. llama3.2',
            'steps' => [
                'Install Ollama on a host the AtGlance containers can reach, then pull a model: ollama pull llama3.2',
                'Make Ollama listen beyond localhost: set OLLAMA_HOST=0.0.0.0:11434 (systemd: systemctl edit ollama, add Environment="OLLAMA_HOST=0.0.0.0:11434", restart).',
                'Ollama on the Docker host: keep the default URL (docker-compose maps host.docker.internal to the host). Ollama elsewhere: http://<ip>:11434/v1.',
                'No API key is needed. For Ollama Cloud use https://ollama.com/v1 and an ollama.com API key.',
                'Ollama has no authentication: firewall port 11434 to trusted hosts only.',
            ],
        ],
        'lmstudio' => [
            'label' => 'LM Studio (self-hosted)',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'none',
            'base_url' => 'http://host.docker.internal:1234/v1',
            'model_hint' => 'The loaded model identifier',
            'steps' => [
                'Load a model, open the Developer tab and start the server.',
                'Turn on "Serve on Local Network" so the containers can reach port 1234.',
                'From Docker use http://host.docker.internal:1234/v1. No API key is needed.',
            ],
        ],
        'openclaw' => [
            'label' => 'OpenClaw gateway',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'optional',
            'base_url' => 'http://host.docker.internal:18789/v1',
            'model_hint' => 'openclaw (default agent) or openclaw/<agentId>',
            'steps' => [
                'The Chat Completions endpoint is off by default. In the gateway config set gateway.http.endpoints.chatCompletions.enabled: true and restart the gateway.',
                'Use the gateway token (gateway.auth.token or OPENCLAW_GATEWAY_TOKEN) as the API key; in password mode use the gateway password.',
                'Base URL: http://<gateway-host>:18789/v1. Model: openclaw, or openclaw/<agentId> for a specific agent.',
                'The gateway token is full operator access to that OpenClaw instance. Keep the gateway on loopback, a tailnet or a private network only.',
            ],
        ],
        'custom' => [
            'label' => 'Custom OpenAI-compatible',
            'protocol' => 'openai',
            'auth' => 'bearer',
            'key' => 'optional',
            'base_url' => null,
            'base_url_hint' => 'https://llm.internal.example/v1',
            'model_hint' => 'As the server exposes it',
            'steps' => [
                'Any server that implements POST /chat/completions: vLLM, LocalAI, LiteLLM proxy, text-generation-webui.',
                'Enter its /v1 base URL and the model name. Enter a key only if the server needs one.',
            ],
        ],
    ];

    /** AI Connect plugin (Plugins tab): shows the AI Connect tab. Installs that had AI on count as enabled. */
    public static function pluginEnabled(): bool
    {
        $stored = AdminSetting::getValue('ai_plugin_enabled', null);

        return filter_var((string) ($stored ?? AdminSetting::getValue('ai_enabled', 'false')), FILTER_VALIDATE_BOOL);
    }

    /** AI features run only while the plugin is on and the active provider is fully set up. */
    public static function enabled(): bool
    {
        return self::pluginEnabled() && self::missing(self::provider()) === [];
    }

    /** The active provider. */
    public static function provider(): string
    {
        $provider = (string) AdminSetting::getValue('ai_provider', '');

        return isset(self::PROVIDERS[$provider]) ? $provider : 'anthropic';
    }

    public static function model(): string
    {
        return self::modelFor(self::provider());
    }

    /**
     * The base URL the admin entered; empty means "use the provider default".
     */
    public static function baseUrl(): string
    {
        return self::baseUrlFor(self::provider());
    }

    public static function apiKey(): string
    {
        return self::apiKeyFor(self::provider());
    }

    public static function modelFor(string $provider): string
    {
        return trim((string) (self::savedConfig()[$provider]['model'] ?? self::legacy($provider, 'ai_model')));
    }

    public static function baseUrlFor(string $provider): string
    {
        return trim((string) (self::savedConfig()[$provider]['base_url'] ?? self::legacy($provider, 'ai_base_url')));
    }

    public static function apiKeyFor(string $provider): string
    {
        if ((self::PROVIDERS[$provider]['key'] ?? 'none') === 'none') {
            return '';
        }

        return trim((string) (self::savedKeys()[$provider] ?? self::legacy($provider, 'ai_api_key')));
    }

    /** Each provider's saved base URL and model: [provider => [base_url, model]]. */
    public static function savedConfig(): array
    {
        $decoded = json_decode((string) AdminSetting::getValue('ai_provider_config', ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Each provider's saved API key (stored encrypted). */
    public static function savedKeys(): array
    {
        $decoded = json_decode((string) AdminSetting::getValue('ai_provider_keys', ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** What the provider still needs before it can be used, as labels; [] when ready. */
    public static function missing(string $provider): array
    {
        $meta = self::PROVIDERS[$provider] ?? null;
        if ($meta === null) {
            return ['Provider'];
        }
        $missing = [];
        if ($meta['base_url'] === null && self::baseUrlFor($provider) === '') {
            $missing[] = 'Base URL';
        }
        if ($meta['key'] === 'required' && self::apiKeyFor($provider) === '') {
            $missing[] = 'API key';
        }
        if (self::modelFor($provider) === '') {
            $missing[] = 'Model';
        }

        return $missing;
    }

    /**
     * Before per-provider settings existed, one connection was stored in ai_base_url / ai_model /
     * ai_api_key for the provider in ai_provider. Those values belong to that provider only.
     */
    private static function legacy(string $provider, string $key): string
    {
        if (AdminSetting::getValue('ai_provider_config', null) !== null || $provider !== (string) AdminSetting::getValue('ai_provider', '')) {
            return '';
        }

        return (string) AdminSetting::getValue($key, '');
    }

    /**
     * The URL requests go to: the entered base URL, else the provider default.
     */
    public static function resolveBaseUrl(string $provider, string $baseUrl): string
    {
        $url = $baseUrl !== '' ? $baseUrl : (string) (self::PROVIDERS[$provider]['base_url'] ?? '');

        return rtrim($url, '/');
    }

    /**
     * The saved connection as the client needs it.
     *
     * @return array{provider: string, base_url: string, model: string, api_key: string}
     */
    public static function connection(): array
    {
        return [
            'provider' => self::provider(),
            'base_url' => self::baseUrl(),
            'model' => self::model(),
            'api_key' => self::apiKey(),
        ];
    }

    /**
     * The saved key may only be reused for the same provider and URL, so a
     * blank key field never sends the saved key to a different host.
     */
    public static function canReuseSavedKey(string $provider, string $baseUrl): bool
    {
        return isset(self::PROVIDERS[$provider])
            && self::resolveBaseUrl($provider, $baseUrl) === self::resolveBaseUrl($provider, self::baseUrlFor($provider));
    }

    /**
     * @return array{ok: bool, message: string, at: string, provider: string, model: string}|null
     */
    public static function lastTest(): ?array
    {
        $decoded = json_decode((string) AdminSetting::getValue('ai_last_test', ''), true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function recordTest(bool $ok, string $message, string $provider, string $model): void
    {
        AdminSetting::putValue('ai', 'ai_last_test', [
            'ok' => $ok,
            'message' => $message,
            'at' => now()->toIso8601String(),
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    /**
     * Provider catalog for the tab's script (no secrets).
     */
    public static function catalogForView(): array
    {
        return collect(self::PROVIDERS)->map(fn (array $meta) => [
            'label' => $meta['label'],
            'key' => $meta['key'],
            'base_url' => $meta['base_url'],
            'base_url_hint' => $meta['base_url'] ?? $meta['base_url_hint'] ?? '',
            'model_hint' => $meta['model_hint'],
            'steps' => $meta['steps'],
        ])->all();
    }

    /** Saves every provider's settings and the active provider; legacy single-connection values are then ignored. */
    public static function store(array $config, array $keys, string $activeProvider): void
    {
        AdminSetting::putValue('ai', 'ai_provider_config', $config);
        AdminSetting::putValue('ai', 'ai_provider_keys', $keys, $keys !== []);
        AdminSetting::putValue('ai', 'ai_provider', $activeProvider);
    }
}
