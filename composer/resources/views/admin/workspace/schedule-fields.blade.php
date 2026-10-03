{{-- Frequency select plus a custom cron field. Params: $prefix ('ai_sweep' | 'backup'), $settings. --}}
@php
    $frequencyValue = old($prefix . '_frequency', $settings[$prefix . '_frequency']);
    $cronValue = old($prefix . '_cron', $settings[$prefix . '_cron']);
@endphp
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
    <div>
        <label class="ag-label" for="{{ $prefix }}Frequency">Schedule</label>
        <select class="ag-select" id="{{ $prefix }}Frequency" name="{{ $prefix }}_frequency" style="width: 100%;"
                onchange="document.getElementById('{{ $prefix }}CronWrap').style.display = this.value === 'custom' ? 'block' : 'none'">
            @foreach(\App\Support\BackupSettings::FREQUENCIES as $frequencyKey => $frequency)
                <option value="{{ $frequencyKey }}" {{ $frequencyValue === $frequencyKey ? 'selected' : '' }}>{{ $frequency['label'] }}</option>
            @endforeach
            <option value="custom" {{ $frequencyValue === 'custom' ? 'selected' : '' }}>Custom (cron)</option>
        </select>
    </div>
    <div id="{{ $prefix }}CronWrap" style="display: {{ $frequencyValue === 'custom' ? 'block' : 'none' }};">
        <label class="ag-label" for="{{ $prefix }}Cron">Cron expression</label>
        <input class="ag-input" id="{{ $prefix }}Cron" type="text" name="{{ $prefix }}_cron" value="{{ $cronValue }}" placeholder="0 2 * * *" maxlength="100" style="width: 100%; font-family: monospace;">
        <p style="margin-top: 4px; font-size: 12px; color: var(--ag-muted);">Five fields: minute hour day month weekday. Server time ({{ config('app.timezone') }}).</p>
    </div>
</div>
