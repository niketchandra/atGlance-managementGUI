{{-- Notifications tab: events this workspace sends, member email defaults, channel groups, member choices. --}}
@unless($permissions['notifications'])
    <div class="ag-card" style="padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: var(--ag-muted); display: flex; gap: 8px; align-items: center;">
        <i class="fas fa-lock"></i> Read only. You do not have access to change these settings. A workspace admin with the Admin role can grant it on the Members tab.
    </div>
@endunless
<fieldset {{ $permissions['notifications'] ? '' : 'disabled' }} class="ws-config-fieldset {{ $permissions['notifications'] ? '' : 'ws-config-fieldset--locked' }}">
@php
    $workspaceEvents = \App\Notifications\NotificationEvents::forScope(\App\Notifications\NotificationEvents::SCOPE_WORKSPACE);
    $sentEvents = $settings['events'] === null ? array_keys($workspaceEvents) : (array) $settings['events'];
    $memberDefaults = (array) $settings['member_email_default_events'];
    $members = $workspace->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']);
@endphp
<div class="ag-card" style="padding: 24px; margin-bottom: 18px;">
    <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Notifications</h2>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">
        Choose which events this workspace sends. They go to the channel groups below (email lists, Slack, Teams, Telegram, webhooks, ...)
        and by email to members who want them. Members pick their own events under Settings &rarr; Notifications; the defaults apply until they do.
    </p>

    <form method="POST" action="{{ route('admin.workspaces.settings.notifications', $workspace->id) }}">
        @csrf
        @method('PUT')

        <div style="overflow-x: auto;">
            <table class="ag-table" style="width: 100%; font-size: 13px; margin-bottom: 16px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--ag-line); text-align: left;">
                        <th style="padding: 10px;">Event</th>
                        <th style="padding: 10px; text-align: center;">Send</th>
                        <th style="padding: 10px; text-align: center;">Email members by default</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($workspaceEvents as $eventKey => $eventLabel)
                        <tr style="border-bottom: 1px solid var(--ag-line);">
                            <td style="padding: 10px;">{{ $eventLabel }} <code style="font-size: 11px; color: var(--ag-muted);">{{ $eventKey }}</code></td>
                            <td style="padding: 10px; text-align: center;">
                                <input type="checkbox" name="events[]" value="{{ $eventKey }}" {{ in_array($eventKey, $sentEvents, true) ? 'checked' : '' }} aria-label="Send {{ $eventLabel }}">
                            </td>
                            <td style="padding: 10px; text-align: center;">
                                <input type="checkbox" name="member_email_default_events[]" value="{{ $eventKey }}" {{ in_array($eventKey, $memberDefaults, true) ? 'checked' : '' }} aria-label="Email members about {{ $eventLabel }}">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button class="ag-btn" type="submit">Save</button>
    </form>
</div>

<div class="ag-card" style="padding: 24px; margin-bottom: 18px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
        <h3 style="font-size: 16px; color: var(--ag-text); margin: 0;">Channel Groups</h3>
        <a class="ag-btn ag-btn--ghost" href="{{ route('admin.notifications', ['scope' => $workspace->id]) }}" style="text-decoration: none;"><i class="fas fa-plus"></i> Add or edit groups</a>
    </div>
    <p style="font-size: 12px; color: var(--ag-muted); margin-bottom: 12px;">An email group can hold a distribution list or several addresses; chat groups post to a Slack, Teams or Telegram channel.</p>

    @if($notificationGroups->isEmpty())
        <p style="font-size: 13px; color: var(--ag-muted);">No channel groups yet.</p>
    @else
        <div style="overflow-x: auto;">
            <table class="ag-table" style="width: 100%; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--ag-line); text-align: left;">
                        <th style="padding: 10px;">Name</th>
                        <th style="padding: 10px;">Channel</th>
                        <th style="padding: 10px;">Events</th>
                        <th style="padding: 10px;">Last sent</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($notificationGroups as $group)
                        <tr style="border-bottom: 1px solid var(--ag-line);">
                            <td style="padding: 10px;">{{ $group->name }} @unless($group->enabled)<span style="color: var(--ag-muted);">(off)</span>@endunless</td>
                            <td style="padding: 10px;">{{ ucfirst($group->channel) }}</td>
                            <td style="padding: 10px; color: var(--ag-muted);">{{ collect($group->events)->map(fn ($e) => \App\Notifications\NotificationEvents::label($e))->implode(', ') }}</td>
                            <td style="padding: 10px; white-space: nowrap; color: {{ $group->last_status === 'failed' ? '#e45757' : 'var(--ag-muted)' }};">
                                {{ $group->last_sent_at ? \App\Support\UserPreferences::datetime($group->last_sent_at) . ' (' . $group->last_status . ')' : 'Never' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="ag-card" style="padding: 24px;">
    <h3 style="font-size: 16px; color: var(--ag-text); margin-bottom: 12px;">Member Email Choices</h3>
    <div style="overflow-x: auto;">
        <table class="ag-table" style="width: 100%; font-size: 13px;">
            <thead>
                <tr style="border-bottom: 1px solid var(--ag-line); text-align: left;">
                    <th style="padding: 10px;">Member</th>
                    <th style="padding: 10px;">Email</th>
                    <th style="padding: 10px;">Gets</th>
                </tr>
            </thead>
            <tbody>
                @foreach($members as $member)
                    @php($preference = $memberPreferences->get($member->id))
                    <tr style="border-bottom: 1px solid var(--ag-line);">
                        <td style="padding: 10px;">{{ $member->name }}</td>
                        <td style="padding: 10px; color: var(--ag-muted);">{{ $member->email }}</td>
                        <td style="padding: 10px; color: var(--ag-muted);">
                            @if($preference === null)
                                Workspace default ({{ count(array_intersect($memberDefaults, $sentEvents)) }} events)
                            @elseif(!$preference->email_enabled)
                                Email off
                            @else
                                {{ collect($preference->events)->intersect($sentEvents)->map(fn ($e) => \App\Notifications\NotificationEvents::label($e))->implode(', ') ?: 'Nothing' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
</fieldset>
