{{-- AI Connect tab body: providers on the left (radio picks the active one, click to open), the open provider's setup on the right.
     Catalog: AiSettings::PROVIDERS. Each provider keeps its own base URL, model and key. --}}
@php
    $aiCanEdit = (int) auth()->user()->rbac_id === 100;
    $aiProviders = \App\Support\AiSettings::PROVIDERS;
    $aiActive = old('ai_provider', \App\Support\AiSettings::provider());
    $aiActive = isset($aiProviders[$aiActive]) ? $aiActive : 'anthropic';
    $aiOpen = collect(array_keys($aiProviders))->first(fn ($key) => $errors->has('ai_config.' . $key) || $errors->has('ai_config.' . $key . '.base_url'))
        ?? (isset($aiProviders[request('provider')]) ? request('provider') : $aiActive);
    $aiLastTest = \App\Support\AiSettings::lastTest();
    $aiStep = 'margin: 0 0 4px 18px; padding: 0; font-size: 12px; color: var(--ag-subtle); line-height: 1.7;';
@endphp

@if($aiLastTest)
    <div style="font-size: 12px; color: var(--ag-muted); margin-bottom: 12px;">
        Last test: <span style="color:{{ $aiLastTest['ok'] ? 'var(--ag-success)' : 'var(--ag-danger)' }}; font-weight:600;">{{ $aiLastTest['ok'] ? 'Success' : 'Failed' }}</span>
        ({{ $aiProviders[$aiLastTest['provider']]['label'] ?? $aiLastTest['provider'] }}{{ $aiLastTest['model'] ? ', ' . $aiLastTest['model'] : '' }})
        {{ \Illuminate\Support\Carbon::parse($aiLastTest['at'])->diffForHumans() }} - {{ $aiLastTest['message'] }}
    </div>
@endif

<form id="ai-connect-form" method="POST" action="{{ route('admin.settings.ai') }}" data-test-url="{{ route('admin.settings.ai.test') }}" data-models-url="{{ route('admin.settings.ai.models') }}">
    @csrf
    <fieldset {{ $aiCanEdit ? '' : 'disabled' }} style="border: none; padding: 0; margin: 0; min-width: 0;">
        <div class="ag-split" data-split="provider">
            <nav class="ag-split-nav" aria-label="AI providers">
                <div class="ag-split-hint">Pick the active provider. Click a name to set it up; each keeps its own settings.</div>
                @foreach($aiProviders as $aiKey => $aiMeta)
                    @php
                        $aiMissing = \App\Support\AiSettings::missing($aiKey);
                        $aiIsActive = $aiKey === $aiActive;
                        [$stateText, $stateClass] = $aiIsActive
                            ? ($aiMissing === [] ? ['Active', 'is-on'] : ['Active, not set up', 'is-warn'])
                            : ($aiMissing === [] ? ['Ready', ''] : ['', '']);
                    @endphp
                    <div class="ag-split-item {{ $aiKey === $aiOpen ? 'is-open' : '' }}" data-key="{{ $aiKey }}">
                        <input type="radio" class="ai-active-radio" name="ai_provider" value="{{ $aiKey }}" {{ $aiIsActive ? 'checked' : '' }} aria-label="Use {{ $aiMeta['label'] }}">
                        <button type="button" class="ag-split-open" data-key="{{ $aiKey }}" aria-controls="ai-panel-{{ $aiKey }}" aria-expanded="{{ $aiKey === $aiOpen ? 'true' : 'false' }}">
                            <span class="ag-split-name">{{ $aiMeta['label'] }}</span>
                            <span class="ag-split-state {{ $stateClass }}" data-missing="{{ $aiMissing === [] ? '0' : '1' }}">{{ $stateText }}</span>
                        </button>
                    </div>
                @endforeach
            </nav>

            <div style="min-width: 0;">
                @foreach($aiProviders as $aiKey => $aiMeta)
                    @php
                        $aiHasKey = \App\Support\AiSettings::apiKeyFor($aiKey) !== '';
                        $aiBaseUrl = old('ai_config.' . $aiKey . '.base_url', \App\Support\AiSettings::baseUrlFor($aiKey));
                        $aiModel = old('ai_config.' . $aiKey . '.model', \App\Support\AiSettings::modelFor($aiKey));
                    @endphp
                    <section class="ag-split-panel ai-panel" id="ai-panel-{{ $aiKey }}" data-key="{{ $aiKey }}" style="display: {{ $aiKey === $aiOpen ? 'block' : 'none' }};">
                        <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 10px;">{{ $aiMeta['label'] }}</h3>

                        <p class="ai-not-active" style="font-size: 12px; color: var(--ag-muted); margin-bottom: 10px; display: {{ $aiKey === $aiActive ? 'none' : 'block' }};">
                            Not the active provider. You can set it up and test it now; pick it on the left to use it for AI reviews.
                        </p>

                        @error('ai_config.' . $aiKey)
                            <div class="ag-alert ag-alert--error" style="margin-bottom: 10px;"><span>{{ $message }}</span></div>
                        @enderror

                        <div style="background: var(--ag-surface); border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;">
                            <div style="font-size: 12px; font-weight: 600; margin-bottom: 6px;">1. Set up on {{ $aiMeta['label'] }}</div>
                            <ol style="{{ $aiStep }}">
                                @foreach($aiMeta['steps'] as $aiLine)
                                    <li>{{ $aiLine }}</li>
                                @endforeach
                            </ol>
                        </div>

                        <div style="font-size: 12px; font-weight: 600; margin-bottom: 8px;">2. Enter the details here</div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 12px;">
                            <div>
                                <label class="ag-label" for="ai-{{ $aiKey }}-base-url">Base URL <span style="color: var(--ag-muted);">{{ $aiMeta['base_url'] ? '(optional)' : '(required)' }}</span></label>
                                <input class="ag-input ai-base-url" id="ai-{{ $aiKey }}-base-url" type="text" name="ai_config[{{ $aiKey }}][base_url]" value="{{ $aiBaseUrl }}" placeholder="{{ $aiMeta['base_url'] ?? $aiMeta['base_url_hint'] ?? '' }}" style="width: 100%;">
                                @error('ai_config.' . $aiKey . '.base_url')
                                    <div style="font-size: 12px; color: var(--ag-danger); margin-top: 4px;">{{ $message }}</div>
                                @enderror
                                @if($aiMeta['base_url'])
                                    <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">Leave blank for the default shown.</div>
                                @endif
                            </div>
                            @if($aiMeta['key'] !== 'none')
                                <div>
                                    <label class="ag-label" for="ai-{{ $aiKey }}-key">API key <span style="color: var(--ag-muted);">{{ $aiMeta['key'] === 'required' ? '(required)' : '(optional)' }}</span></label>
                                    <input class="ag-input ai-api-key" id="ai-{{ $aiKey }}-key" type="password" name="ai_key[{{ $aiKey }}]" autocomplete="new-password" placeholder="{{ $aiHasKey ? 'Saved; leave blank to keep' : 'Not set' }}" style="width: 100%;">
                                    @if($aiHasKey)
                                        <label style="display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                                            <input type="checkbox" class="ai-clear-key" name="ai_clear_key[{{ $aiKey }}]" value="1"> Remove saved key
                                        </label>
                                    @endif
                                </div>
                            @endif
                            <div style="grid-column: 1 / -1;">
                                <label class="ag-label" for="ai-{{ $aiKey }}-model">Model</label>
                                <div style="display: flex; gap: 6px;">
                                    <input class="ag-input ai-model" id="ai-{{ $aiKey }}-model" type="text" name="ai_config[{{ $aiKey }}][model]" list="ai-{{ $aiKey }}-models" value="{{ $aiModel }}" placeholder="{{ $aiMeta['model_hint'] }}" style="flex: 1; min-width: 0;">
                                    @if($aiCanEdit)
                                        <button class="ag-btn ag-btn--ghost ai-load-models" type="button" style="white-space: nowrap;">Load models</button>
                                    @endif
                                </div>
                                <datalist id="ai-{{ $aiKey }}-models" class="ai-model-options"></datalist>
                                <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">{{ $aiMeta['model_hint'] }}</div>
                            </div>
                        </div>

                        <div class="ai-result" role="status" style="display: none; margin-bottom: 12px; padding: 10px; border-radius: 12px; font-size: 13px; word-break: break-word;"></div>

                        @if($aiCanEdit)
                            <button class="ag-btn ag-btn--ghost ai-test" type="button">Test {{ $aiMeta['label'] }}</button>
                        @endif
                    </section>
                @endforeach

                @if($aiCanEdit)
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px;">
                        <button class="ag-btn" type="submit">Save AI Connection</button>
                        <span style="font-size: 12px; color: var(--ag-muted);">Saves every provider's settings and the active provider.</span>
                    </div>
                @endif
            </div>
        </div>
    </fieldset>
</form>

<script>
(function () {
    var form = document.getElementById('ai-connect-form');
    if (!form) { return; }
    var split = form.querySelector('[data-split]');
    var csrf = form.querySelector('input[name="_token"]').value;

    form.querySelectorAll('.ai-active-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            form.querySelectorAll('.ag-split-item').forEach(function (item) {
                var state = item.querySelector('.ag-split-state');
                var active = item.dataset.key === radio.value;
                var missing = state.dataset.missing === '1';
                state.textContent = active ? (missing ? 'Active, not set up' : 'Active') : (missing ? '' : 'Ready');
                state.classList.toggle('is-on', active && !missing);
                state.classList.toggle('is-warn', active && missing);
            });
            form.querySelectorAll('.ai-panel').forEach(function (panel) {
                panel.querySelector('.ai-not-active').style.display = panel.dataset.key === radio.value ? 'none' : 'block';
            });
            if (split && split.splitOpen) { split.splitOpen(radio.value); }
        });
    });

    function show(panel, ok, message) {
        var result = panel.querySelector('.ai-result');
        result.style.display = 'block';
        result.style.background = ok ? 'var(--ag-success-soft)' : 'var(--ag-danger-soft)';
        result.style.color = ok ? 'var(--ag-success)' : 'var(--ag-danger)';
        result.textContent = message;
    }

    // Test and Load models use this pane's values only, without saving.
    async function post(panel, url) {
        var data = new FormData();
        data.append('_token', csrf);
        data.append('ai_provider', panel.dataset.key);
        data.append('ai_base_url', panel.querySelector('.ai-base-url').value);
        data.append('ai_model', panel.querySelector('.ai-model').value);
        var key = panel.querySelector('.ai-api-key');
        if (key) { data.append('ai_api_key', key.value); }
        var clear = panel.querySelector('.ai-clear-key');
        if (clear && clear.checked) { data.append('ai_clear_api_key', '1'); }
        var response = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json' }, body: data });
        var json = await response.json().catch(function () { return {}; });
        if (response.status === 422 && json.errors) {
            return { ok: false, message: Object.values(json.errors).flat().join(' ') };
        }
        if (!response.ok && !json.message) {
            return { ok: false, message: 'Request failed (HTTP ' + response.status + ').' };
        }
        return json;
    }

    async function run(button, busy, url, done) {
        var panel = button.closest('.ai-panel');
        var label = button.textContent;
        button.disabled = true;
        button.textContent = busy;
        try {
            done(panel, await post(panel, url));
        } catch (error) {
            show(panel, false, 'Request failed: ' + error.message);
        } finally {
            button.disabled = false;
            button.textContent = label;
        }
    }

    form.querySelectorAll('.ai-test').forEach(function (button) {
        button.addEventListener('click', function () {
            run(button, 'Testing...', form.dataset.testUrl, function (panel, data) {
                show(panel, data.ok, data.message + (data.ok && data.reply ? ' Reply: "' + data.reply + '"' : ''));
            });
        });
    });

    form.querySelectorAll('.ai-load-models').forEach(function (button) {
        button.addEventListener('click', function () {
            run(button, 'Loading...', form.dataset.modelsUrl, function (panel, data) {
                show(panel, data.ok, data.message);
                panel.querySelector('.ai-model-options').replaceChildren.apply(panel.querySelector('.ai-model-options'), (data.models || []).map(function (id) {
                    var option = document.createElement('option');
                    option.value = id;
                    return option;
                }));
            });
        });
    });
})();
</script>
