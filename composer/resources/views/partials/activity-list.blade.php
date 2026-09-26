{{-- A list of ActivityFeed items. Expects $activityItems; optional $activityEmpty text. --}}
<style>
    .activity-list { list-style: none; margin: 0; padding: 0; }
    .activity-entry { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border: 1px solid #d6d6d6; border-left: 3px solid #666666; border-radius: 6px; background: #f7f7f7; margin-bottom: 8px; }
    .activity-entry.is-failure { border-left-color: #b91c1c; background: #fdf4f4; }
    .activity-entry .activity-icon { width: 20px; text-align: center; color: #444; padding-top: 2px; }
    .activity-entry.is-failure .activity-icon { color: #b91c1c; }
    .activity-entry .activity-text { font-weight: 600; color: #222; }
    .activity-entry .activity-meta { color: #777; font-size: 12px; margin-top: 3px; }
    .activity-badge { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b; vertical-align: 1px; }
</style>

@if($activityItems->isEmpty())
    <p style="color: #666;">{{ $activityEmpty ?? 'No activity yet.' }}</p>
@else
    <ul class="activity-list">
        @foreach($activityItems as $activity)
            <li class="activity-entry {{ $activity['outcome'] === 'failure' ? 'is-failure' : '' }}">
                <span class="activity-icon"><i class="fas {{ $activity['icon'] }}"></i></span>
                <div>
                    <div class="activity-text">
                        {{ $activity['text'] }}
                        @if($activity['outcome'] === 'failure')
                            <span class="activity-badge">Failed</span>
                        @endif
                    </div>
                    <div class="activity-meta">
                        <span title="{{ $activity['at']->copy()->setTimezone(\App\Support\UserPreferences::timezone())->format('Y-m-d H:i:s T') }}">{{ $activity['at']->diffForHumans() }}</span>
                        · {{ \App\Support\UserPreferences::datetime($activity['at']) }}
                        @if($activity['ip'])
                            · {{ $activity['device'] }} · {{ $activity['ip'] }}
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
