{{-- SSO Configuration tab body: providers on the left (tick to enable, click to open), the open provider's settings on the right.
     Provider list, fields and setup steps come from config/sso.php. --}}
@php
    $ssoCatalog = \App\Support\SsoProviders::all();
    $ssoSaved = $ssoEnabledProviders ?? [];
    $ssoSelected = old('sso_enabled_providers', $ssoSaved);
    $ssoSecrets = \App\Support\SsoProviders::secrets();
    // Open the provider with an error, else the requested one, else the first enabled one, else the first.
    $ssoOpen = collect(array_keys($ssoCatalog))->first(fn ($key) => $errors->has('sso_config.' . $key))
        ?? (isset($ssoCatalog[request('provider')]) ? request('provider') : null)
        ?? ($ssoSaved[0] ?? array_key_first($ssoCatalog));
@endphp
<form method="POST" action="{{ route('admin.settings.sso', ['tab' => 'sso']) }}" id="sso-form">
    @csrf
    <div id="disable-email-registration-wrap" style="margin-bottom: 16px;">
        <label style="display: flex; align-items: center; gap: 8px;">
            <input id="disable-email-registration-toggle" type="checkbox" name="disable_email_registration" value="1" {{ old('disable_email_registration', $disableEmailRegistration ?? false) ? 'checked' : '' }}>
            <span>Disable user registration with email/password</span>
        </label>
        <p style="margin: 6px 0 0 26px; font-size: 12px; color: var(--ag-muted);">Registration and password reset by email are turned off on the login page. Users then sign up and sign in only through SSO.</p>
    </div>

    @error('sso_enabled_providers')
        <div class="ag-alert ag-alert--error" style="margin-bottom: 12px;"><span>{{ $message }}</span></div>
    @enderror

    <div class="ag-split" data-split="provider">
        <nav class="ag-split-nav" aria-label="SSO providers">
            <div class="ag-split-hint">Tick to enable. Click a name to set it up.</div>
            @foreach($ssoCatalog as $providerKey => $provider)
                @php
                    $isOn = in_array($providerKey, $ssoSelected, true);
                    $missing = \App\Support\SsoProviders::missingFields($providerKey);
                    [$stateText, $stateClass] = !$isOn ? ['Off', '']
                        : ($missing === [] ? ['On', 'is-on'] : ['Not set up', 'is-warn']);
                @endphp
                <div class="ag-split-item {{ $providerKey === $ssoOpen ? 'is-open' : '' }}" data-key="{{ $providerKey }}">
                    <input class="sso-enable-checkbox" type="checkbox" name="sso_enabled_providers[]" value="{{ $providerKey }}" {{ $isOn ? 'checked' : '' }}
                           aria-label="Enable {{ $provider['label'] }}">
                    <button type="button" class="ag-split-open" data-key="{{ $providerKey }}" aria-controls="sso-panel-{{ $providerKey }}" aria-expanded="{{ $providerKey === $ssoOpen ? 'true' : 'false' }}">
                        <i class="{{ $provider['icon'] }}"></i>
                        <span class="ag-split-name">{{ $provider['label'] }}</span>
                        <span class="ag-split-state {{ $stateClass }}" data-missing="{{ $missing === [] ? '0' : '1' }}">{{ $stateText }}</span>
                    </button>
                </div>
            @endforeach
        </nav>

        <div style="min-width: 0;">
            @foreach($ssoCatalog as $providerKey => $provider)
                @php
                    $callback = \App\Support\SsoProviders::callbackUrl($providerKey);
                    $hasSecret = trim((string) ($ssoSecrets[$providerKey] ?? '')) !== '' || \App\Support\SsoProviders::clientSecret($providerKey) !== '';
                    $value = fn (string $field) => old('sso_config.' . $providerKey . '.' . $field, \App\Support\SsoProviders::value($providerKey, $field));
                @endphp
                <section class="ag-split-panel" id="sso-panel-{{ $providerKey }}" data-key="{{ $providerKey }}" style="display: {{ $providerKey === $ssoOpen ? 'block' : 'none' }};">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;">
                        <h3 style="font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;"><i class="{{ $provider['icon'] }}"></i> {{ $provider['label'] }}</h3>
                        <a href="{{ $provider['docs'] }}" target="_blank" rel="noopener" style="font-size: 12px; color: var(--ag-teal); text-decoration: underline;">Official setup guide <i class="fas fa-arrow-up-right-from-square" style="font-size: 10px;"></i></a>
                    </div>

                    <p class="sso-panel-off" style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px; display: {{ in_array($providerKey, $ssoSelected, true) ? 'none' : 'block' }};">
                        Not enabled. You can fill in the settings now; tick {{ $provider['label'] }} on the left to add its button to the login page.
                    </p>

                    @error('sso_config.' . $providerKey)
                        <div class="ag-alert ag-alert--error" style="margin-bottom: 10px;"><span>{{ $message }}</span></div>
                    @enderror

                    <div style="background: var(--ag-surface); border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;">
                        <div style="font-size: 12px; font-weight: 600; margin-bottom: 6px;">1. Set up on {{ $provider['label'] }}</div>
                        <ol style="margin: 0 0 8px 18px; padding: 0; font-size: 12px; color: var(--ag-subtle); line-height: 1.7;">
                            @foreach($provider['steps'] as $step)
                                @php $parts = explode(':callback', $step); @endphp
                                <li>@foreach($parts as $i => $part){{ $part }}@if($i < count($parts) - 1)<code>{{ $callback }}</code>@endif @endforeach</li>
                            @endforeach
                        </ol>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 12px;">
                            <span style="color: var(--ag-muted);">Callback / redirect URI:</span>
                            <code id="sso-callback-{{ $providerKey }}" style="overflow-wrap: anywhere;">{{ $callback }}</code>
                            <button type="button" class="ag-btn ag-btn--ghost ag-btn--sm sso-copy" data-copy="sso-callback-{{ $providerKey }}">Copy</button>
                        </div>
                        @if(str_starts_with($callback, 'http://'))
                            <div style="font-size: 12px; color: var(--ag-warning); margin-top: 6px;">This address is plain HTTP. Most providers only accept https:// redirect URIs except for localhost. Open the console on its HTTPS domain (Custom Domain &amp; HTTPS plugin) and set it up from there.</div>
                        @endif
                    </div>

                    <div style="font-size: 12px; font-weight: 600; margin-bottom: 8px;">2. Enter the details here</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
                        <div>
                            <label class="ag-label" for="sso-{{ $providerKey }}-client-id">{{ $provider['client_id_label'] }}</label>
                            <input class="ag-input" id="sso-{{ $providerKey }}-client-id" type="text" name="sso_config[{{ $providerKey }}][client_id]" value="{{ $value('client_id') }}" autocomplete="off" style="width: 100%;">
                        </div>
                        <div>
                            <label class="ag-label" for="sso-{{ $providerKey }}-secret">{{ $provider['client_secret_label'] }}</label>
                            <input class="ag-input" id="sso-{{ $providerKey }}-secret" type="password" name="sso_secret[{{ $providerKey }}]" autocomplete="new-password" placeholder="{{ $hasSecret ? 'Saved. Leave blank to keep it.' : '' }}" style="width: 100%;">
                        </div>
                        @foreach($provider['fields'] ?? [] as $field => $meta)
                            @if(($meta['type'] ?? 'text') === 'checkbox')
                                <div style="grid-column: 1 / -1;">
                                    <label style="display: flex; gap: 8px; align-items: flex-start; font-size: 13px;">
                                        <input type="checkbox" name="sso_config[{{ $providerKey }}][{{ $field }}]" value="1" {{ filter_var($value($field), FILTER_VALIDATE_BOOL) ? 'checked' : '' }} style="margin-top: 3px;">
                                        <span>{{ $meta['label'] }}@if(!empty($meta['help']))<span style="display: block; font-size: 12px; color: var(--ag-muted);">{{ $meta['help'] }}</span>@endif</span>
                                    </label>
                                </div>
                            @else
                                <div>
                                    <label class="ag-label" for="sso-{{ $providerKey }}-{{ $field }}">{{ $meta['label'] }}</label>
                                    <input class="ag-input" id="sso-{{ $providerKey }}-{{ $field }}" type="{{ ($meta['type'] ?? 'text') === 'url' ? 'url' : 'text' }}" name="sso_config[{{ $providerKey }}][{{ $field }}]" value="{{ $value($field) }}" placeholder="{{ $meta['placeholder'] ?? '' }}" autocomplete="off" style="width: 100%;">
                                    @if(!empty($meta['help']))
                                        <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">{{ $meta['help'] }}</div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px;">
                <button class="ag-btn" type="submit">Save SSO Settings</button>
                <span style="font-size: 12px; color: var(--ag-muted);">Saves every provider, including the enabled ticks on the left.</span>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('sso-form');
    if (!form) { return; }

    var split = form.querySelector('[data-split]');

    form.querySelectorAll('.sso-enable-checkbox').forEach(function (box) {
        box.addEventListener('change', function () {
            var item = box.closest('.ag-split-item');
            var state = item.querySelector('.ag-split-state');
            var missing = state.dataset.missing === '1';
            state.textContent = !box.checked ? 'Off' : (missing ? 'Not set up' : 'On');
            state.classList.toggle('is-on', box.checked && !missing);
            state.classList.toggle('is-warn', box.checked && missing);
            var note = form.querySelector('#sso-panel-' + box.value + ' .sso-panel-off');
            if (note) { note.style.display = box.checked ? 'none' : 'block'; }
            if (box.checked && split.splitOpen) { split.splitOpen(box.value); }
        });
    });

    form.querySelectorAll('.sso-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var source = document.getElementById(btn.dataset.copy);
            var text = source ? source.textContent : '';
            var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
                return;
            }
            var area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
            done();
        });
    });
})();
</script>
