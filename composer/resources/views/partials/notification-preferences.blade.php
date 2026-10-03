{{-- Settings > Notifications: per workspace, which events the user gets by email. --}}
<div class="settings-card">
    <h2 style="font-size: 20px; font-weight: 500; margin-bottom: 10px;"><i class="fas fa-bell"></i> Notifications</h2>
    <p style="color: var(--ag-muted); font-size: 14px; margin-bottom: 20px;">
        Choose the workspace events you get by email. Only events the workspace sends are listed. Until you save, the workspace default applies.
    </p>

    @foreach($notificationWorkspaces as $notificationWorkspace)
        <form method="POST" action="{{ route('settings.notifications') }}" class="ag-card" style="padding: 18px; margin-bottom: 14px;">
            @csrf
            <input type="hidden" name="workspace_id" value="{{ $notificationWorkspace->id }}">

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px;">
                <h3 style="font-size: 16px; margin: 0;">{{ $notificationWorkspace->name }}</h3>
                <label style="display: flex; gap: 8px; align-items: center; cursor: pointer; font-size: 13px;">
                    <input type="checkbox" name="email_enabled" value="1" {{ $notificationWorkspace->email_enabled ? 'checked' : '' }}>
                    Email me
                </label>
            </div>

            @if(empty($notificationWorkspace->events))
                <p style="font-size: 13px; color: var(--ag-muted);">This workspace sends no notifications.</p>
            @else
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px; margin-bottom: 12px;">
                    @foreach($notificationWorkspace->events as $eventKey => $eventLabel)
                        <label style="display: flex; gap: 8px; align-items: center; cursor: pointer; font-size: 13px;">
                            <input type="checkbox" name="events[]" value="{{ $eventKey }}" {{ in_array($eventKey, $notificationWorkspace->chosen, true) ? 'checked' : '' }}>
                            {{ $eventLabel }}
                        </label>
                    @endforeach
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                    <span style="font-size: 12px; color: var(--ag-muted);">{{ $notificationWorkspace->customised ? 'Your own choice.' : 'Workspace default.' }}</span>
                    <button class="ag-btn" type="submit">Save</button>
                </div>
            @endif
        </form>
    @endforeach
</div>
