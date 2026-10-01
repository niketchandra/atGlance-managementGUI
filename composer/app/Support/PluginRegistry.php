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
        ];
    }
}
