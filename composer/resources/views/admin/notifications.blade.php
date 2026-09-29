@extends('app')

@section('title', 'Notifications - ' . $brandName)

@section('dashboard-content')
@php
    $channelMeta = \App\Support\NotificationSettings::CHANNELS;
    $fieldStyle = 'width:100%; border:1px solid transparent; border-radius:12px; padding:11px 14px; background:#f4f6f8; font-size:14px;';
    $labelStyle = 'display:block; font-size:13px; font-weight:500; color:#5b626b; margin-bottom:6px;';
    $cardStyle = 'background:#ffffff; border-radius:20px; padding:22px; box-shadow:0 8px 30px rgba(20,23,27,0.06); margin-bottom:16px;';
@endphp
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Notifications</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration: none; color: var(--ag-text);">← Back to Dashboard</a>
    </div>

    @if(session('success'))
        <div style="margin-bottom: 16px; padding: 12px; border-radius: 12px; background: var(--ag-success-soft); border: 1px solid #a7f3d0; color: #137a54;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div style="margin-bottom: 16px; padding: 12px; border-radius: 12px; background: var(--ag-danger-soft); border: 1px solid #f9d6d6; color: var(--ag-danger);">
            <ul style="margin-left: 16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(empty($scopes))
        <div style="{{ $cardStyle }}">
            <p style="color: var(--ag-muted);">You are not an admin of any workspace. Ask the super admin to make you a workspace admin to set up notifications.</p>
        </div>
    @else
        <div style="{{ $cardStyle }}">
            <form method="GET" action="{{ route('admin.notifications') }}" style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
                <div style="min-width: 260px;">
                    <label style="{{ $labelStyle }}">Workspace</label>
                    <select name="scope" onchange="this.form.submit()" style="{{ $fieldStyle }}">
                        @foreach($scopes as $scopeKey => $scopeLabel)
                            <option value="{{ $scopeKey }}" {{ (string) $scopeKey === $scope ? 'selected' : '' }}>{{ $scopeLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <p style="font-size: 13px; color: var(--ag-muted); margin-top: 10px;">
                Groups receive the events you choose for this {{ $scope === 'organization' ? 'organization' : 'workspace' }}.
                Channels are allowed by the super admin on the Notification tab of Site Setting.
            </p>
        </div>

        <div style="{{ $cardStyle }}">
            <h2 style="font-size: 18px; margin-bottom: 12px;">Groups</h2>
            @forelse($groups as $group)
                <div class="ag-card" style="padding: 12px; margin-bottom: 10px;">
                    <div style="display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <div>
                            <strong>{{ $group->name }}</strong>
                            <span style="font-size: 12px; color: var(--ag-subtle); background: var(--ag-surface); border-radius: 999px; padding: 2px 8px; margin-left: 6px;">{{ $channelMeta[$group->channel]['label'] ?? $group->channel }}</span>
                            @unless($group->enabled)
                                <span style="font-size: 12px; color: var(--ag-warning); background: var(--ag-warning-soft); border-radius: 999px; padding: 2px 8px; margin-left: 4px;">Disabled</span>
                            @endunless
                            @unless(\App\Support\NotificationSettings::isAllowed($group->channel))
                                <span style="font-size: 12px; color: var(--ag-danger); background: var(--ag-danger-soft); border-radius: 999px; padding: 2px 8px; margin-left: 4px;">Channel not allowed</span>
                            @endunless
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <form method="POST" action="{{ route('admin.notifications.test', $group) }}">
                                @csrf
                                <button class="ag-btn ag-btn--ghost" type="submit">Send test</button>
                            </form>
                            <form method="POST" action="{{ route('admin.notifications.destroy', $group) }}" onsubmit="return confirm('Delete this group?');">
                                @csrf
                                @method('DELETE')
                                <button class="ag-btn ag-btn--danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </div>
                    <div style="font-size: 13px; color: var(--ag-subtle); margin-top: 6px;">
                        Events: {{ collect($group->events)->map(fn ($event) => \App\Notifications\NotificationEvents::label($event))->implode(', ') }}
                    </div>
                    <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                        Last delivery:
                        @if($group->last_sent_at)
                            <span style="font-weight:600; color:{{ $group->last_status === 'sent' ? '#15803d' : '#b91c1c' }};">{{ ucfirst($group->last_status) }}</span>
                            at {{ \App\Support\UserPreferences::datetime($group->last_sent_at) }}
                            @if($group->last_error)
                                <div style="color: var(--ag-danger); word-break: break-word;">{{ $group->last_error }}</div>
                            @endif
                        @else
                            never
                        @endif
                    </div>

                    <details style="margin-top: 8px;">
                        <summary style="cursor: pointer; font-size: 13px; color: var(--ag-teal);">Edit</summary>
                        <form method="POST" action="{{ route('admin.notifications.update', $group) }}" style="margin-top: 10px;">
                            @csrf
                            @method('PUT')
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                                <div>
                                    <label style="{{ $labelStyle }}">Name</label>
                                    <input type="text" name="name" value="{{ $group->name }}" required maxlength="255" style="{{ $fieldStyle }}">
                                </div>
                                <div>
                                    <label style="{{ $labelStyle }}">{{ $channelMeta[$group->channel]['target'] ?? 'Target' }}</label>
                                    <input type="text" name="target" placeholder="Saved; leave blank to keep" autocomplete="off" style="{{ $fieldStyle }}">
                                </div>
                            </div>
                            <div style="margin-bottom: 10px;">
                                @foreach($events as $eventKey => $eventLabel)
                                    <label style="display: inline-flex; gap: 6px; align-items: center; font-size: 13px; margin-right: 14px;">
                                        <input type="checkbox" name="events[]" value="{{ $eventKey }}" {{ $group->subscribesTo($eventKey) ? 'checked' : '' }}> {{ $eventLabel }}
                                    </label>
                                @endforeach
                            </div>
                            <label style="display: flex; gap: 6px; align-items: center; font-size: 13px; margin-bottom: 10px;">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" name="enabled" value="1" {{ $group->enabled ? 'checked' : '' }}> Enabled
                            </label>
                            <button class="ag-btn" type="submit">Save group</button>
                        </form>
                    </details>
                </div>
            @empty
                <p style="font-size: 13px; color: var(--ag-muted);">No groups yet.</p>
            @endforelse
        </div>

        <div style="{{ $cardStyle }}">
            <h2 style="font-size: 18px; margin-bottom: 12px;">Add group</h2>
            @if(empty($channels))
                <p style="font-size: 13px; color: var(--ag-muted);">No channels are available yet. The super admin allows channels on the Notification tab of Site Setting.</p>
            @else
                <form method="POST" action="{{ route('admin.notifications.store') }}">
                    @csrf
                    <input type="hidden" name="scope" value="{{ $scope }}">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                        <div>
                            <label style="{{ $labelStyle }}">Channel</label>
                            <select name="channel" id="notify-channel" style="{{ $fieldStyle }}">
                                @foreach($channels as $channelKey => $channelLabel)
                                    <option value="{{ $channelKey }}" data-target="{{ $channelMeta[$channelKey]['target'] }}" {{ old('channel') === $channelKey ? 'selected' : '' }}>{{ $channelLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="{{ $labelStyle }}">Name</label>
                            <input type="text" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="e.g. Ansible on-call" style="{{ $fieldStyle }}">
                        </div>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="{{ $labelStyle }}" id="notify-target-label">Target</label>
                        <textarea name="target" rows="2" required style="{{ $fieldStyle }}">{{ old('target') }}</textarea>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="{{ $labelStyle }}">Events</label>
                        @foreach($events as $eventKey => $eventLabel)
                            <label style="display: inline-flex; gap: 6px; align-items: center; font-size: 13px; margin-right: 14px;">
                                <input type="checkbox" name="events[]" value="{{ $eventKey }}" {{ in_array($eventKey, old('events', array_keys($events)), true) ? 'checked' : '' }}> {{ $eventLabel }}
                            </label>
                        @endforeach
                    </div>
                    <label style="display: flex; gap: 6px; align-items: center; font-size: 13px; margin-bottom: 12px;">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" checked> Enabled
                    </label>
                    <button class="ag-btn" type="submit">Add group</button>
                </form>
            @endif
        </div>
    @endif
</div>

<script>
(function () {
    const channel = document.getElementById('notify-channel');
    const label = document.getElementById('notify-target-label');
    if (!channel || !label) return;
    const update = () => { label.textContent = channel.selectedOptions[0]?.dataset.target || 'Target'; };
    channel.addEventListener('change', update);
    update();
})();
</script>
@endsection
