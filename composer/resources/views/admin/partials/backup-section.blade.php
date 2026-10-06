{{--
    One backup section of the Backup & Restore tab: schedule, destinations,
    retention, "Run now" and the restore list.

    Expects: $type ('database' or 'config'), $section (BackupSettings::get() + label + last_run),
    $sectionKey ('database' or 'files', for the restore list), $icon, $description,
    $restoreNote, $frequencies, $s3Available, $open (shown as the open pane).
--}}
@php
    $old = fn (string $name, $current) => old("backup_{$type}_{$name}", $current);
    $enabled = (string) $old('enabled', $section['enabled'] ? '1' : '0') === '1';
    $frequency = (string) $old('frequency', $section['frequency']);
    $cronExpression = (string) $old('cron_expression', $section['cron_expression']);
    $toS3 = (string) $old('to_s3', $section['to_s3'] ? '1' : '0') === '1';
    $toLocal = (string) $old('to_local', $section['to_local'] ? '1' : '0') === '1';
    $lastRun = $section['last_run'] ?? [];
    $statusColor = ['success' => 'var(--ag-success)', 'failed' => 'var(--ag-danger)'][$lastRun['status'] ?? ''] ?? 'var(--ag-muted)';
@endphp
<section class="backup-section ag-split-panel" data-type="{{ $type }}" data-key="{{ $type }}" style="display: {{ ($open ?? true) ? 'block' : 'none' }};">
    <h3 style="font-size: 17px; margin-bottom: 4px;"><i class="fas {{ $icon }}"></i> {{ $section['label'] }} &amp; restore</h3>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">{{ $description }}</p>

    <div class="backup-settings-fields">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 12px;">
            <div>
                <label class="ag-label">Scheduled backup</label>
                <select class="ag-select backup-enabled" name="backup_{{ $type }}_enabled" form="backup-settings-form" style="width: 100%;">
                    <option value="0" {{ $enabled ? '' : 'selected' }}>Off</option>
                    <option value="1" {{ $enabled ? 'selected' : '' }}>On</option>
                </select>
            </div>
            <div class="backup-schedule-field">
                <label class="ag-label">How often</label>
                <select class="ag-select backup-frequency" name="backup_{{ $type }}_frequency" form="backup-settings-form" style="width: 100%;">
                    <option value="" {{ $frequency === '' ? 'selected' : '' }}>Select frequency</option>
                    @foreach($frequencies as $value => $preset)
                        <option value="{{ $value }}" {{ $frequency === $value ? 'selected' : '' }}>{{ $preset['label'] }} ({{ $preset['expression'] }})</option>
                    @endforeach
                    <option value="custom" {{ $frequency === 'custom' ? 'selected' : '' }}>Custom cron expression</option>
                </select>
            </div>
            <div class="backup-schedule-field backup-custom-cron" style="display: {{ $frequency === 'custom' ? 'block' : 'none' }};">
                <label class="ag-label">Cron expression (minute hour day month weekday)</label>
                <input class="ag-input" type="text" name="backup_{{ $type }}_cron_expression" form="backup-settings-form" value="{{ $cronExpression }}" placeholder="30 2 * * *" style="width: 100%; font-family: monospace;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 12px;">
            <div>
                <input type="hidden" name="backup_{{ $type }}_to_local" value="0" form="backup-settings-form">
                <label style="display: flex; gap: 8px; align-items: center; font-size: 14px; margin-bottom: 8px;">
                    <input type="checkbox" class="backup-to-local" name="backup_{{ $type }}_to_local" value="1" form="backup-settings-form" {{ $toLocal ? 'checked' : '' }}>
                    Keep local copies on this server
                </label>
                <div class="backup-keep-local" style="display: {{ $toLocal ? 'block' : 'none' }};">
                    <label class="ag-label">Local copies to keep</label>
                    <input class="ag-input" type="number" min="1" max="365" name="backup_{{ $type }}_keep_local" form="backup-settings-form" value="{{ $old('keep_local', $section['keep_local']) }}" style="width: 120px;">
                </div>
            </div>
            <div>
                <input type="hidden" name="backup_{{ $type }}_to_s3" value="0" form="backup-settings-form">
                <label style="display: flex; gap: 8px; align-items: center; font-size: 14px; margin-bottom: 8px;">
                    <input type="checkbox" class="backup-to-s3" name="backup_{{ $type }}_to_s3" value="1" form="backup-settings-form" {{ $toS3 ? 'checked' : '' }}>
                    Upload to S3
                    @unless($s3Available)
                        <span style="font-size: 12px; color: var(--ag-muted);">(set up S3 Storage in the Plugins tab first)</span>
                    @endunless
                </label>
                <div class="backup-keep-s3" style="display: {{ $toS3 ? 'block' : 'none' }};">
                    <label class="ag-label">S3 copies to keep</label>
                    <input class="ag-input" type="number" min="1" max="365" name="backup_{{ $type }}_keep_s3" form="backup-settings-form" value="{{ $old('keep_s3', $section['keep_s3']) }}" style="width: 120px;">
                </div>
            </div>
        </div>
        <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 12px;">After each backup, older copies beyond these numbers are deleted. Pre-restore snapshots are never deleted automatically.</p>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px;">
        <button class="ag-btn" type="submit" form="backup-settings-form">Save Backup Settings</button>
        <form method="POST" action="{{ route('admin.settings.backups.run') }}" style="display: inline;">
            @csrf
            <input type="hidden" name="type" value="{{ $type }}">
            <button class="ag-btn ag-btn--ghost" type="submit" title="Uses the saved destinations and retention, even when the schedule is off.">
                <i class="fas fa-play"></i> Run now
            </button>
        </form>
    </div>
    <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 4px;">
        Last run:
        @if(!empty($lastRun))
            <strong style="color: {{ $statusColor }};">{{ $lastRun['status'] }}</strong>
            {{ \App\Support\UserPreferences::datetime($lastRun['finished_at'] ?? null) }}
            @if(!empty($lastRun['message'])) - {{ $lastRun['message'] }} @endif
        @else
            never
        @endif
    </p>

    <div class="restore-section" data-section="{{ $sectionKey }}" data-url="{{ route('admin.settings.backups', ['section' => $sectionKey]) }}" style="margin-top: 16px; padding-top: 14px; border-top: 1px dashed var(--ag-line);">
        <h4 style="font-size: 15px; margin-bottom: 6px;">Restore</h4>
        <div style="margin-bottom: 12px; padding: 10px; border-radius: 12px; background: var(--ag-danger-soft); border: 1px solid #f9d6d6; color: var(--ag-danger); font-size: 13px; line-height: 1.5;">
            <strong>Warning:</strong> {{ $restoreNote }} A snapshot of the current state is saved on this server first, so a restore can be undone.
        </div>

        <div class="restore-errors" style="display: none; margin-bottom: 10px; font-size: 13px; color: var(--ag-danger);"></div>
        <div style="overflow-x: auto; margin-bottom: 12px;">
            <table class="ag-table" style="width: 100%;">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid var(--ag-line); color: var(--ag-subtle);">
                        <th style="padding: 6px;">Where</th>
                        <th style="padding: 6px;">Backup</th>
                        <th style="padding: 6px;">Created</th>
                        <th style="padding: 6px;">Size</th>
                        <th style="padding: 6px;"></th>
                    </tr>
                </thead>
                <tbody class="restore-backup-rows">
                    <tr><td colspan="5" style="padding: 8px;">Loading backups...</td></tr>
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.settings.restore') }}" class="restore-form" style="display: none; padding: 12px; border-radius: 12px; background: var(--ag-surface);">
            @csrf
            <input type="hidden" name="source" class="restore-source">
            <input type="hidden" name="path" class="restore-path">
            <div style="font-size: 13px; color: var(--ag-text); margin-bottom: 10px;">Selected backup: <strong class="restore-selected-name"></strong></div>
            <div style="margin-bottom: 10px; max-width: 320px;">
                <label class="ag-label">Confirm your password</label>
                <input class="ag-input" type="password" name="password" required autocomplete="current-password" style="width: 100%;">
            </div>
            <label style="display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--ag-subtle); margin-bottom: 12px;">
                <input type="checkbox" name="confirm_overwrite" value="1" required>
                I understand that current data will be overwritten.
            </label>
            <button class="ag-btn ag-btn--danger" type="submit">Restore Backup</button>
            <button class="ag-btn ag-btn--ghost restore-cancel" type="button" style="margin-left: 6px;">Cancel</button>
        </form>
    </div>
</section>
