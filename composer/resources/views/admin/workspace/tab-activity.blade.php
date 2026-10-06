{{-- Recent Activity tab of the workspace page: everything that happened on this workspace's systems and services. --}}
@php
    $activityFilters = [
        'type' => request('activity_type'),
        'system' => request('activity_system') ? (int) request('activity_system') : null,
        'period' => request('activity_period', '30'),
    ];
    $activityPage = $activeTab === 'activity'
        ? \App\Support\WorkspaceActivity::paginate($workspace->id, $activityFilters, 50, max(1, (int) request('activity_page', 1)))
        : null;
    $activitySystems = \Illuminate\Support\Facades\DB::table('system_register')->where('workspace_id', $workspace->id)->orderBy('system_name')->get(['id', 'system_name', 'status']);
    $activityColors = [
        'success' => ['var(--ag-success-soft)', 'var(--ag-success)'],
        'warning' => ['var(--ag-warning-soft)', 'var(--ag-warning)'],
        'error' => ['var(--ag-danger-soft)', 'var(--ag-danger)'],
        'info' => ['var(--ag-surface)', 'var(--ag-subtle)'],
    ];
    $activityTz = \App\Support\UserPreferences::timezone(auth()->user());
    $activityLocal = fn ($at) => $at->copy()->setTimezone($activityTz);
    $activityDay = function ($at) use ($activityLocal) {
        $at = $activityLocal($at);

        return $at->isToday() ? 'Today' : ($at->isYesterday() ? 'Yesterday' : $at->format('l, M j, Y'));
    };
@endphp

<div class="ag-card" style="padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 6px;">
        <h2 style="font-size: 18px;">Recent Activity</h2>
        @if($activityPage)
            <span style="font-size: 12px; color: var(--ag-muted);">{{ $activityPage->total() >= 1 ? 'Newest first' : '' }}</span>
        @endif
    </div>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">
        Everything that happened in {{ $workspace->name }}: systems registered or deregistered, services added, configuration backups,
        AI reviews and Vulnerability Checks, workspace backups, members and settings changes.
    </p>

    <form method="GET" action="{{ url()->current() }}" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px;">
        <input type="hidden" name="tab" value="activity">
        <div>
            <label class="ag-label" for="activity-type">Show</label>
            <select class="ag-select" id="activity-type" name="activity_type" onchange="this.form.submit()">
                <option value="">Everything</option>
                @foreach(\App\Support\WorkspaceActivity::TYPES as $typeKey => $typeLabel)
                    <option value="{{ $typeKey }}" {{ $activityFilters['type'] === $typeKey ? 'selected' : '' }}>{{ $typeLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ag-label" for="activity-system">System</label>
            <select class="ag-select" id="activity-system" name="activity_system" onchange="this.form.submit()">
                <option value="">All systems</option>
                @foreach($activitySystems as $activitySystem)
                    <option value="{{ $activitySystem->id }}" {{ $activityFilters['system'] === (int) $activitySystem->id ? 'selected' : '' }}>{{ $activitySystem->system_name }}{{ $activitySystem->status !== 'active' ? ' (inactive)' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ag-label" for="activity-period">Period</label>
            <select class="ag-select" id="activity-period" name="activity_period" onchange="this.form.submit()">
                @foreach(\App\Support\WorkspaceActivity::PERIODS as $periodKey => $periodLabel)
                    <option value="{{ $periodKey }}" {{ (string) $activityFilters['period'] === (string) $periodKey ? 'selected' : '' }}>{{ $periodLabel }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="ag-btn ag-btn--ghost" type="submit">Apply</button></noscript>
    </form>

    @if($activityPage === null)
        <p data-activity-lazy style="font-size: 13px; color: var(--ag-muted);">Loading…</p>
    @elseif($activityPage->isEmpty())
        <div style="padding: 18px; border-radius: 12px; background: var(--ag-surface); color: var(--ag-subtle); font-size: 13px;">
            Nothing happened in this period{{ $activityFilters['type'] || $activityFilters['system'] ? ' for these filters' : '' }}. Try a longer period.
        </div>
    @else
        @foreach($activityPage->groupBy(fn ($entry) => $activityDay($entry['at'])) as $day => $dayEntries)
            <div style="font-size: 12px; font-weight: 600; color: var(--ag-muted); margin: 14px 0 6px; text-transform: uppercase; letter-spacing: 0.04em;">{{ $day }}</div>
            <ul style="list-style: none; margin: 0; padding: 0; border: 1px solid var(--ag-line); border-radius: 14px; overflow: hidden;">
                @foreach($dayEntries as $entry)
                    @php [$entryBg, $entryFg] = $activityColors[$entry['outcome']] ?? $activityColors['info']; @endphp
                    <li style="display: flex; gap: 12px; align-items: flex-start; padding: 10px 14px; {{ $loop->last ? '' : 'border-bottom: 1px solid var(--ag-line);' }}">
                        <span style="flex: 0 0 32px; height: 32px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: {{ $entryBg }}; color: {{ $entryFg }};">
                            <i class="fas {{ $entry['icon'] }}" style="font-size: 13px;"></i>
                        </span>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 13px; color: var(--ag-text); overflow-wrap: anywhere;">
                                @if($entry['url'])
                                    <a href="{{ $entry['url'] }}" style="color: inherit; text-decoration: none;">{{ $entry['title'] }}</a>
                                @else
                                    {{ $entry['title'] }}
                                @endif
                            </div>
                            <div style="font-size: 12px; color: var(--ag-muted); margin-top: 2px; display: flex; gap: 8px; flex-wrap: wrap;">
                                @if($entry['system'])
                                    <span><i class="fas fa-server" style="font-size: 10px;"></i> {{ $entry['system'] }}</span>
                                @endif
                                @if($entry['detail'])
                                    <span>{{ $entry['detail'] }}</span>
                                @endif
                                @if($entry['actor'])
                                    <span>by {{ $entry['actor'] }}</span>
                                @endif
                            </div>
                        </div>
                        <span style="flex-shrink: 0; font-size: 12px; color: var(--ag-muted); white-space: nowrap;" title="{{ \App\Support\UserPreferences::datetime($entry['at']) }}">
                            {{ $activityLocal($entry['at'])->format('H:i') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endforeach

        @if($activityPage->hasPages())
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 13px;">
                @if($activityPage->onFirstPage())
                    <span></span>
                @else
                    <a class="ag-btn ag-btn--ghost ag-btn--sm" href="{{ $activityPage->previousPageUrl() }}">&larr; Newer</a>
                @endif
                <span style="color: var(--ag-muted);">Page {{ $activityPage->currentPage() }}</span>
                @if($activityPage->hasMorePages())
                    <a class="ag-btn ag-btn--ghost ag-btn--sm" href="{{ $activityPage->nextPageUrl() }}">Older &rarr;</a>
                @else
                    <span></span>
                @endif
            </div>
        @endif
    @endif
</div>
