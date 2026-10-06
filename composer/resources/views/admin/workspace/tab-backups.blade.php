{{-- Backups tab: scheduled backup of this workspace's stored config files, with notes and run history. --}}
@unless($permissions['backups'])
    <div class="ag-card" style="padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: var(--ag-muted); display: flex; gap: 8px; align-items: center;">
        <i class="fas fa-lock"></i> Read only. You do not have access to change these settings. A workspace admin with the Admin role can grant it on the Members tab.
    </div>
@endunless
<fieldset {{ $permissions['backups'] ? '' : 'disabled' }} class="ws-config-fieldset {{ $permissions['backups'] ? '' : 'ws-config-fieldset--locked' }}">
@php($s3Enabled = \App\Support\S3Settings::enabled())
<div class="ag-card" style="padding: 24px; margin-bottom: 18px;">
    <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Workspace Backups</h2>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">
        Back up the configuration files already stored in the console for this workspace's systems: every version, its content, the services and systems they belong to, and saved AI reviews.
        This is separate from the organization backup on Site Settings &rarr; Backup &amp; Restore, which still covers everything.
    </p>

    <form method="POST" action="{{ route('admin.workspaces.settings.backup', $workspace->id) }}">
        @csrf
        @method('PUT')

        <label style="display: flex; gap: 10px; align-items: center; margin-bottom: 14px; cursor: pointer;">
            <input type="checkbox" name="backup_enabled" value="1" {{ old('backup_enabled', $settings['backup_enabled']) ? 'checked' : '' }}>
            <strong style="color: var(--ag-text);">Run on a schedule</strong>
        </label>

        <div style="margin-bottom: 16px;">
            @include('admin.workspace.schedule-fields', ['prefix' => 'backup'])
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 16px;">
            <div class="ag-card" style="padding: 12px; background: var(--ag-surface);">
                <label style="display: flex; gap: 8px; align-items: center; cursor: pointer; margin-bottom: 8px;">
                    <input type="checkbox" name="backup_to_local" value="1" {{ old('backup_to_local', $settings['backup_to_local']) ? 'checked' : '' }}>
                    <strong>Local copy</strong>
                </label>
                <label class="ag-label" for="backupKeepLocal">Copies to keep</label>
                <input class="ag-input" id="backupKeepLocal" type="number" name="backup_keep_local" min="1" max="{{ \App\Support\BackupSettings::MAX_KEEP }}" value="{{ old('backup_keep_local', $settings['backup_keep_local']) }}" style="width: 100%;">
            </div>
            <div class="ag-card" style="padding: 12px; background: var(--ag-surface); {{ $s3Enabled ? '' : 'opacity: 0.6;' }}">
                <label style="display: flex; gap: 8px; align-items: center; cursor: pointer; margin-bottom: 8px;">
                    <input type="checkbox" name="backup_to_s3" value="1" {{ $s3Enabled && old('backup_to_s3', $settings['backup_to_s3']) ? 'checked' : '' }} {{ $s3Enabled ? '' : 'disabled' }}>
                    <strong>S3</strong>
                </label>
                <label class="ag-label" for="backupKeepS3">Copies to keep</label>
                <input class="ag-input" id="backupKeepS3" type="number" name="backup_keep_s3" min="1" max="{{ \App\Support\BackupSettings::MAX_KEEP }}" value="{{ old('backup_keep_s3', $settings['backup_keep_s3']) }}" style="width: 100%;">
                @unless($s3Enabled)
                    <p style="margin-top: 6px; font-size: 12px; color: var(--ag-muted);">S3 is not set up. A super admin can configure it in Site Settings &rarr; S3.</p>
                @endunless
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <label class="ag-label" for="backupNotes">Notes</label>
            <textarea class="ag-textarea" id="backupNotes" name="backup_notes" rows="3" maxlength="2000" style="width: 100%;" placeholder="e.g. Before the nginx upgrade; owner: platform team">{{ old('backup_notes', $settings['backup_notes']) }}</textarea>
            <p style="margin-top: 4px; font-size: 12px; color: var(--ag-muted);">Saved into each backup (notes.txt) and shown in the history below.</p>
        </div>

        <button class="ag-btn" type="submit">Save</button>
    </form>
</div>

<div class="ag-card" style="padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
        <h3 style="font-size: 16px; color: var(--ag-text); margin: 0;">Backup History</h3>
        <form method="POST" action="{{ route('admin.workspaces.backups.run', $workspace->id) }}" style="margin: 0;">
            @csrf
            <button class="ag-btn ag-btn--ghost" type="submit"><i class="fas fa-play"></i> Run backup now</button>
        </form>
    </div>

    @if($backupRuns->isEmpty())
        <p style="font-size: 13px; color: var(--ag-muted);">No backup has run yet.</p>
    @else
        <div style="overflow-x: auto;">
            <table class="ag-table" style="width: 100%; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--ag-line); text-align: left;">
                        <th style="padding: 10px;">Started</th>
                        <th style="padding: 10px;">Status</th>
                        <th style="padding: 10px;">Result</th>
                        <th style="padding: 10px;">Notes</th>
                        <th style="padding: 10px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($backupRuns as $backupRun)
                        <tr style="border-bottom: 1px solid var(--ag-line);">
                            <td style="padding: 10px; white-space: nowrap;">{{ \App\Support\UserPreferences::datetime($backupRun->started_at) }}</td>
                            <td style="padding: 10px;">
                                <span style="padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; background: {{ $backupRun->status === 'success' ? '#1fa874' : '#e45757' }};">{{ ucfirst($backupRun->status) }}</span>
                            </td>
                            <td style="padding: 10px; color: var(--ag-muted); word-break: break-word;">{{ $backupRun->message }}</td>
                            <td style="padding: 10px; color: var(--ag-muted); word-break: break-word;">{{ \Illuminate\Support\Str::limit((string) $backupRun->notes, 120) ?: '-' }}</td>
                            <td style="padding: 10px; white-space: nowrap;">
                                @if($backupRun->object_key)
                                    <a href="{{ route('admin.workspaces.backups.download', [$workspace->id, $backupRun->id]) }}"><i class="fas fa-download"></i> Download</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
</fieldset>
