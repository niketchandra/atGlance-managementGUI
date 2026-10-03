{{-- Latest AI validation result for a config file or service. $status: error|warning|ok|unknown|null. --}}
@php
    $aiBadge = [
        'error' => ['Error', '#e45757', 'fa-times-circle'],
        'warning' => ['Warning', '#e0a030', 'fa-exclamation-triangle'],
        'ok' => ['No Issues', '#1fa874', 'fa-check-circle'],
    ][$status ?? ''] ?? null;
@endphp
@if($aiBadge)
    <span title="Latest AI validation: {{ $aiBadge[0] }}" style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; background: {{ $aiBadge[1] }}; white-space: nowrap;">
        <i class="fas {{ $aiBadge[2] }}"></i> {{ $aiBadge[0] }}
    </span>
@else
    <span style="font-size: 12px; color: var(--ag-muted);">Not checked</span>
@endif
