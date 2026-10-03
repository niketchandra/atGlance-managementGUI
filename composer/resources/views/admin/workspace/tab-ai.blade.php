{{-- Vulnerability Checks tab: automatic AI review of the latest config versions. --}}
@unless($permissions['vulnerability_checks'])
    <div class="ag-card" style="padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: var(--ag-muted); display: flex; gap: 8px; align-items: center;">
        <i class="fas fa-lock"></i> Read only. You do not have access to change these settings. A workspace admin with the Admin role can grant it on the Members tab.
    </div>
@endunless
<fieldset {{ $permissions['vulnerability_checks'] ? '' : 'disabled' }} class="ws-config-fieldset {{ $permissions['vulnerability_checks'] ? '' : 'ws-config-fieldset--locked' }}">
@php($aiEnabled = \App\Support\AiSettings::enabled())
<div class="ag-card" style="padding: 24px;">
    <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Vulnerability Checks</h2>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">
        Review the latest version of each service config in this workspace with AI. Only the newest upload of a file on a system is checked; older versions are left alone.
    </p>

    @unless($aiEnabled)
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-muted); font-size: 13px; margin-bottom: 16px;">
            <i class="fas fa-info-circle"></i> AI Connect is off. Settings are saved, but no checks run until a super admin turns it on in Site Settings &rarr; AI Connect.
        </div>
    @endunless

    <form method="POST" action="{{ route('admin.workspaces.settings.ai', $workspace->id) }}">
        @csrf
        @method('PUT')

        <label style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; cursor: pointer;">
            <input type="checkbox" name="ai_on_upload" value="1" {{ old('ai_on_upload', $settings['ai_on_upload']) ? 'checked' : '' }} style="margin-top: 3px;">
            <span>
                <strong style="color: var(--ag-text);">Check new uploads</strong>
                <span style="display: block; font-size: 12px; color: var(--ag-muted);">Each new config version is reviewed as soon as the CLI uploads it (queued in the background).</span>
            </span>
        </label>

        <label style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; cursor: pointer;">
            <input type="checkbox" name="ai_sweep_enabled" value="1" {{ old('ai_sweep_enabled', $settings['ai_sweep_enabled']) ? 'checked' : '' }} style="margin-top: 3px;">
            <span>
                <strong style="color: var(--ag-text);">Scheduled re-check</strong>
                <span style="display: block; font-size: 12px; color: var(--ag-muted);">On a schedule, review the latest version of every config in this workspace.</span>
            </span>
        </label>

        <div style="margin: 0 0 14px 26px;">
            @include('admin.workspace.schedule-fields', ['prefix' => 'ai_sweep'])

            <label style="display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; cursor: pointer;">
                <input type="checkbox" name="ai_sweep_skip_unchanged" value="1" {{ old('ai_sweep_skip_unchanged', $settings['ai_sweep_skip_unchanged']) ? 'checked' : '' }} style="margin-top: 3px;">
                <span>
                    <strong style="color: var(--ag-text);">Skip files already reviewed</strong>
                    <span style="display: block; font-size: 12px; color: var(--ag-muted);">Only review latest versions that have no review yet. Turn off to re-review everything each run (every review is an AI call your provider bills for).</span>
                </span>
            </label>
        </div>

        <button class="ag-btn" type="submit">Save</button>
    </form>

    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--ag-line); display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
        <div style="font-size: 13px; color: var(--ag-muted);">
            @if($settings['ai_last_sweep'])
                Last run {{ \App\Support\UserPreferences::datetime($settings['ai_last_sweep']['at'] ?? null) }}: {{ $settings['ai_last_sweep']['message'] ?? '' }}
            @else
                No scheduled check has run yet.
            @endif
        </div>
        <form method="POST" action="{{ route('admin.workspaces.ai.run', $workspace->id) }}" style="margin: 0;">
            @csrf
            <button class="ag-btn ag-btn--ghost" type="submit" {{ $aiEnabled ? '' : 'disabled' }}><i class="fas fa-play"></i> Run check now</button>
        </form>
    </div>
</div>
</fieldset>

@include('admin.workspace.ai-progress')
