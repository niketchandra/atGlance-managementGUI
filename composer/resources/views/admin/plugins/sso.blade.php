{{-- Details panel of the SSO Login plugin. --}}
@php
    $ssoPluginEnabled = \App\Support\SsoSettings::enabled();
    $ssoPluginProviders = \App\Support\SsoSettings::enabledProviders();
    $ssoPluginCatalog = config('sso.providers', []);
    $ssoEmailOff = filter_var((string) \App\Models\AdminSetting::getValue('disable_email_registration', 'false'), FILTER_VALIDATE_BOOL);
@endphp
<p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px; line-height: 1.6;">
    Adds "Sign in with ..." buttons to the login page for the identity providers you set up (Google, Microsoft, GitHub and others).
    Users are matched by email address. Password login keeps working unless you turn off email registration on the SSO tab.
</p>

@if(!$ssoPluginEnabled)
    <p style="font-size: 13px; color: var(--ag-muted);">Enable the plugin, then set up at least one provider on the SSO tab.</p>
@else
    @if($ssoPluginProviders === [])
        <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;">
            <span>No provider is selected, so the login page shows no SSO buttons. Select and set up a provider on the SSO tab.</span>
        </div>
    @endif
    @foreach($ssoPluginProviders as $ssoPluginProvider)
        @php $ssoPluginMissing = \App\Support\SsoProviders::missingFields($ssoPluginProvider); @endphp
        @if($ssoPluginMissing !== [])
            <div class="ag-alert ag-alert--warning" style="margin-bottom: 8px;">
                <span>{{ \App\Support\SsoProviders::label($ssoPluginProvider) }} has no sign-in button yet. Missing: {{ implode(', ', $ssoPluginMissing) }}.</span>
            </div>
        @endif
    @endforeach
    <div style="display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; font-size: 13px; margin-bottom: 12px;">
        <span style="color: var(--ag-muted);">Providers</span>
        <span>{{ $ssoPluginProviders === [] ? 'None' : collect($ssoPluginProviders)->map(fn ($key) => $ssoPluginCatalog[$key]['label'] ?? ucfirst($key))->implode(', ') }}</span>
        <span style="color: var(--ag-muted);">Callback URL</span>
        <code style="overflow-wrap: anywhere;">{{ url('/auth/sso/{provider}/callback') }}</code>
        <span style="color: var(--ag-muted);">Email registration</span>
        <span>{{ $ssoEmailOff ? 'Off: users sign up and reset passwords through SSO only' : 'On' }}</span>
    </div>
    <p style="font-size: 12px; color: var(--ag-muted); line-height: 1.6;">
        Register the callback URL with each provider, replacing <code>{provider}</code> with the provider name.
        Disabling the plugin removes the SSO buttons and turns email registration back on, so users can still sign up and reset passwords.
    </p>
@endif
