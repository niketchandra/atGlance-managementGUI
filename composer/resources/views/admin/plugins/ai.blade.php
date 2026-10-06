{{-- Details panel of the AI Connect plugin. --}}
@php
    $aiPluginOn = \App\Support\AiSettings::pluginEnabled();
    $aiActive = \App\Support\AiSettings::provider();
    $aiActiveLabel = \App\Support\AiSettings::PROVIDERS[$aiActive]['label'];
    $aiActiveMissing = \App\Support\AiSettings::missing($aiActive);
@endphp
<p style="font-size: 13px; color: var(--ag-subtle); margin-bottom: 10px; line-height: 1.6;">
    Reviews configuration files for vulnerabilities with an AI model: on demand from a file, after each upload, or on a schedule per workspace.
    Use a cloud provider (Anthropic Claude, OpenAI, Azure, Gemini, Mistral, Groq, DeepSeek, xAI, OpenRouter) or a self-hosted model (Ollama, LM Studio, OpenClaw, any OpenAI-compatible server).
    Secret-looking values are masked before a file is sent.
</p>

@if(!$aiPluginOn)
    <p style="font-size: 13px; color: var(--ag-muted);">Enable the plugin, then choose and set up a provider on the AI Connect tab.</p>
@elseif($aiActiveMissing !== [])
    <div class="ag-alert ag-alert--warning" style="margin-bottom: 12px;">
        <span>No AI reviews run yet: the active provider, {{ $aiActiveLabel }}, is missing {{ implode(', ', $aiActiveMissing) }}. Set it up on the <a href="{{ route('admin.settings', ['tab' => 'ai-connect']) }}" style="color: var(--ag-teal); text-decoration: underline;">AI Connect</a> tab.</span>
    </div>
@else
    <div style="display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; font-size: 13px;">
        <span style="color: var(--ag-muted);">Status</span><span><span class="ag-badge ag-badge--success">In use</span></span>
        <span style="color: var(--ag-muted);">Active provider</span><span>{{ $aiActiveLabel }}</span>
        <span style="color: var(--ag-muted);">Model</span><span>{{ \App\Support\AiSettings::model() }}</span>
    </div>
@endif
