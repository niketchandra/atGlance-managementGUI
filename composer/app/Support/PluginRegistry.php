<?php

namespace App\Support;

use App\Models\User;

/**
 * The optional features listed on the Plugins tab of /admin/settings.
 *
 * To add a plugin: add an entry to all(), give it a route that switches it
 * on and off, and a details partial in resources/views/admin/plugins/.
 */
class PluginRegistry
{
    /**
     * @return array<int, array{
     *     key: string, name: string, icon: string, summary: string, keywords: string,
     *     enabled: bool, can_toggle: bool, toggle_route: string, details_view: string,
     *     action: array{label: string, url: string}|null, attention: bool
     * }>
     */
    public static function all(?User $user): array
    {
        $isSuperAdmin = (int) ($user?->rbac_id ?? 0) === 100;

        $domain = DomainSettings::viewData(request()->getHost());
        $domainCertificate = $domain['certificate'] ?? null;

        return [
            [
                'key' => 'custom-domain',
                'name' => 'Custom Domain & HTTPS',
                'icon' => 'fa-globe',
                'summary' => 'Open the console on your own domain with HTTPS, through the built-in proxy or your platform\'s load balancer.',
                'keywords' => 'domain https tls ssl certificate proxy caddy load balancer',
                'enabled' => $domain['plugin_enabled'],
                'can_toggle' => $isSuperAdmin,
                'toggle_route' => route('admin.settings.domain.plugin'),
                'details_view' => 'admin.plugins.custom-domain',
                'action' => $domain['plugin_enabled']
                    ? ['label' => 'Set the domain on the Site tab', 'url' => route('admin.settings', ['tab' => 'site'])]
                    : null,
                // Something on the details panel needs a look.
                'attention' => $domain['plugin_enabled'] && (
                    isset($domain['proxy_seen']['untrusted'])
                    || ($domain['https_mode'] === 'custom' && $domainCertificate && $domainCertificate['days_left'] <= 30)
                ),
            ],
            [
                'key' => 'sso',
                'name' => 'SSO Login',
                'icon' => 'fa-right-to-bracket',
                'summary' => 'Let users sign in with Google, Microsoft, GitHub or another identity provider instead of a password.',
                'keywords' => 'sso single sign-on login oauth oidc google microsoft azure entra github okta identity provider',
                'enabled' => SsoSettings::enabled(),
                'can_toggle' => $isSuperAdmin,
                'toggle_route' => route('admin.settings.sso.plugin'),
                'details_view' => 'admin.plugins.sso',
                'action' => SsoSettings::enabled()
                    ? ['label' => 'Configure providers on the SSO tab', 'url' => route('admin.settings', ['tab' => 'sso'])]
                    : null,
                // Enabled but a selected provider is missing settings (or none is selected): no button for it on the login page.
                'attention' => SsoSettings::enabled() && (SsoSettings::enabledProviders() === []
                    || collect(SsoSettings::enabledProviders())->contains(fn ($provider) => SsoProviders::missingFields($provider) !== [])),
            ],
            [
                'key' => 'ai',
                'name' => 'AI Connect',
                'icon' => 'fa-robot',
                'summary' => 'Connect an AI provider (Claude, OpenAI, Gemini, Ollama and others) to review configuration files for vulnerabilities.',
                'keywords' => 'ai llm claude anthropic openai gpt gemini mistral groq deepseek grok openrouter ollama lm studio azure vulnerability review',
                'enabled' => AiSettings::pluginEnabled(),
                'can_toggle' => $isSuperAdmin,
                'toggle_route' => route('admin.settings.ai.plugin'),
                'details_view' => 'admin.plugins.ai',
                'action' => AiSettings::pluginEnabled()
                    ? ['label' => AiSettings::enabled() ? 'AI Connect' : 'Choose a provider', 'url' => route('admin.settings', ['tab' => 'ai-connect'])]
                    : null,
                // Enabled but the active provider is incomplete: no AI reviews run.
                'attention' => AiSettings::pluginEnabled() && !AiSettings::enabled(),
            ],
            [
                'key' => 's3',
                'name' => 'S3 Storage',
                'icon' => 'fa-cloud',
                'summary' => 'Keep configuration files and backups in an Amazon S3 bucket instead of only on this server.',
                'keywords' => 's3 aws amazon bucket storage cloud backup migration object',
                'enabled' => S3Settings::pluginEnabled(),
                'can_toggle' => $isSuperAdmin,
                'toggle_route' => route('admin.settings.s3.plugin'),
                'details_view' => 'admin.plugins.s3',
                'action' => S3Settings::pluginEnabled()
                    ? ['label' => S3Settings::enabled() ? 'S3 Configuration' : 'Enter bucket and keys', 'url' => route('admin.settings', ['tab' => 's3'])]
                    : null,
                // Enabled but no keys saved yet: nothing goes to S3.
                'attention' => S3Settings::pluginEnabled() && !S3Settings::enabled(),
            ],
            [
                'key' => 'mcp',
                'name' => 'MCP Server (AI tools)',
                'icon' => 'fa-plug',
                'summary' => 'Let AI tools (Claude, VS Code, GitHub Copilot, n8n) read systems, config backups and vulnerabilities, each with the user\'s own API key.',
                'keywords' => 'mcp ai claude copilot vscode n8n model context protocol assistant agent',
                'enabled' => McpControl::enabled(),
                'can_toggle' => $isSuperAdmin,
                'toggle_route' => route('admin.settings.mcp'),
                'details_view' => 'admin.plugins.mcp',
                'action' => McpControl::enabled()
                    ? ['label' => 'Open setup page', 'url' => route('mcp.connect')]
                    : null,
                // The container check is a network call, so only make it when the Plugins tab is open.
                'attention' => request('tab') === 'plugins' && McpControl::enabled() && McpControl::status() !== McpControl::RUNNING,
            ],
        ];
    }
}
