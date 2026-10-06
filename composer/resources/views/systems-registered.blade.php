@extends('app')

@section('title', 'Systems Registered - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    @if(session('success'))
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-success-soft); color: var(--ag-success); margin-bottom: 16px;">
            {{ session('success') }}
        </div>
    @endif

    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">Systems Registered</h1>
        <p style="color: var(--ag-muted); font-size: 14px;">Manage and monitor your registered systems</p>
    </div>

    <!-- Search/Filter Section -->
    <div class="ag-card" style="padding: 24px; margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
            <i class="fas fa-filter" style="color: var(--ag-text); font-size: 18px;"></i>
            <h3 style="font-size: 16px; font-weight: 500; color: var(--ag-text); margin: 0;">Search & Filter</h3>
        </div>
        <datalist id="systemTagCatalogue">
            @foreach($tagCatalogue ?? [] as $catalogueTag)
                <option value="{{ $catalogueTag }}"></option>
            @endforeach
        </datalist>
        <form method="GET" action="{{ route('systems-registered') }}" id="filterForm">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="ag-label">Name</label>
                    <input class="ag-input" type="text" name="name" value="{{ request('name') }}" placeholder="Search by name" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label class="ag-label">IP Address</label>
                    <input class="ag-input" type="text" name="ip" value="{{ request('ip') }}" placeholder="Search by IP" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label class="ag-label">Tags</label>
                    <input class="ag-input" type="text" name="tags" list="systemTagCatalogue" value="{{ request('tags') }}" placeholder="Search by tags" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label class="ag-label">OS Type</label>
                    <input class="ag-input" type="text" name="os" value="{{ request('os') }}" placeholder="Search by OS" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label class="ag-label">Status</label>
                    <select class="ag-select" name="status" style="width: 100%; transition: border-color 0.2s; cursor: pointer;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="ag-label">Hash Key</label>
                    <input class="ag-input" type="text" name="hash" value="{{ request('hash') }}" placeholder="Search by hash" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="ag-btn ag-btn--accent" type="submit">
                    <i class="fas fa-search"></i> Search
                </button>
                <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered') }}" style="text-decoration: none; display: inline-block;">
                    <i class="fas fa-redo"></i> Clear Filters
                </a>
            </div>
        </form>
    </div>

    <!-- Systems Cards -->
    @if($items->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-server" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No systems found matching your filters</p>
        </div>
    @else
        <style>
            .sys-toolbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
            .sys-toggle { display: inline-flex; border: 1px solid var(--ag-line); border-radius: 10px; overflow: hidden; }
            .sys-toggle button { background: none; border: 0; padding: 6px 12px; cursor: pointer; color: var(--ag-muted); font-size: 13px; }
            .sys-toggle button.is-active { background: var(--ag-surface); color: var(--ag-text); font-weight: 600; }
            .sys-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
            .sys-card { padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; }
            .sys-name { font-size: 15px; font-weight: 600; color: var(--ag-text); word-break: break-word; }
            .sys-sub { font-size: 12px; color: var(--ag-muted); }
            .sys-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
            .sys-nums { display: flex; gap: 14px; font-size: 12px; color: var(--ag-muted); }
            .sys-nums strong { color: var(--ag-text); font-size: 14px; }
            .sys-actions { display: flex; gap: 6px; justify-content: flex-end; }
            .sys-actions .ag-btn { text-decoration: none; padding: 5px 10px; font-size: 12px; }
            .sys-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 820px; }
            .sys-table th { text-align: left; padding: 12px; color: var(--ag-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid var(--ag-line); }
            .sys-table td { padding: 12px; border-bottom: 1px solid var(--ag-line); color: var(--ag-text); }
            [data-sys-view="list"] .sys-grid, [data-sys-view="card"] .sys-list { display: none; }
        </style>

        @php
            $isAdminRole = in_array((int) (auth()->user()->rbac_id ?? 0), [100, 101], true);
            $rows = $items->map(function ($item) use ($systemStats, $isAdminRole) {
                $metadata = json_decode((string) ($item->metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $stats = $systemStats[$item->id] ?? ['services' => 0, 'configs' => 0, 'error' => 0, 'warning' => 0];

                return (object) [
                    'item' => $item,
                    'isActive' => strtolower((string) $item->status) === 'active',
                    'os' => trim(($item->os_type ?? 'Linux') . ' ' . ($metadata['version'] ?? $metadata['os_version'] ?? $metadata['ubuntu_version'] ?? $metadata['linux_version'] ?? $metadata['release'] ?? '')),
                    'stats' => $stats,
                    'issueColor' => $stats['error'] > 0 ? '#e45757' : ($stats['warning'] > 0 ? '#e0a030' : '#1fa874'),
                    'isLocked' => (bool) ($item->is_locked ?? false),
                    'canEdit' => $isAdminRole || !((bool) ($item->is_locked ?? false)),
                ];
            });
        @endphp

        <div id="sysView" data-sys-view="card">
            <div class="sys-toolbar">
                <div class="sys-toggle" role="group" aria-label="View">
                    <button type="button" data-view="card" class="is-active"><i class="fas fa-th-large"></i> Cards</button>
                    <button type="button" data-view="list"><i class="fas fa-list"></i> List</button>
                </div>
            </div>

            <div class="sys-grid">
                @foreach($rows as $row)
                    <div class="ag-card sys-card">
                        <div style="display: flex; justify-content: space-between; gap: 8px;">
                            <div style="min-width: 0;">
                                <div class="sys-name">{{ $row->item->system_name ?? 'N/A' }}</div>
                                <div class="sys-sub">
                                    <span class="sys-dot" style="background: {{ $row->isActive ? '#1fa874' : '#8a9099' }};"></span>{{ ucfirst($row->item->status) }}
                                    &middot; {{ $row->os }}
                                </div>
                                <div class="sys-sub">{{ $row->item->ip_address ?? 'N/A' }} &middot; {{ $row->item->workspace_name ?? 'Not assigned' }}</div>
                            </div>
                            @if($row->isLocked)
                                <span title="System Info is locked" style="color: #b33b3b;"><i class="fas fa-lock"></i></span>
                            @endif
                        </div>
                        <div class="sys-nums">
                            <span><strong>{{ $row->stats['services'] }}</strong> services</span>
                            <span><strong>{{ $row->stats['configs'] }}</strong> backups</span>
                            <span title="{{ $row->stats['error'] }} errors, {{ $row->stats['warning'] }} warnings"><strong style="color: {{ $row->issueColor }};">{{ $row->stats['error'] + $row->stats['warning'] }}</strong> issues</span>
                        </div>
                        <div class="sys-actions">
                            @if($row->canEdit)
                                <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered.edit', ['systemId' => $row->item->id]) }}"><i class="fas fa-pen"></i> Edit</a>
                            @endif
                            <a class="ag-btn ag-btn--accent" href="{{ route('systems-registered.services', ['systemId' => $row->item->id]) }}"><i class="fas fa-cubes"></i> Services</a>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="ag-card sys-list" style="padding: 0; overflow-x: auto;">
                <table class="sys-table">
                    <thead>
                        <tr>
                            <th>System</th>
                            <th>Status</th>
                            <th>OS</th>
                            <th>IP</th>
                            <th>Workspace</th>
                            <th>Services</th>
                            <th>Backups</th>
                            <th>Issues</th>
                            <th>Registered</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row->item->system_name ?? 'N/A' }}</strong>
                                    @if($row->isLocked)
                                        <i class="fas fa-lock" title="System Info is locked" style="color: #b33b3b; margin-left: 4px;"></i>
                                    @endif
                                </td>
                                <td style="white-space: nowrap;"><span class="sys-dot" style="background: {{ $row->isActive ? '#1fa874' : '#8a9099' }};"></span>{{ ucfirst($row->item->status) }}</td>
                                <td>{{ $row->os }}</td>
                                <td>{{ $row->item->ip_address ?? 'N/A' }}</td>
                                <td>{{ $row->item->workspace_name ?? 'Not assigned' }}</td>
                                <td>{{ $row->stats['services'] }}</td>
                                <td>{{ $row->stats['configs'] }}</td>
                                <td title="{{ $row->stats['error'] }} errors, {{ $row->stats['warning'] }} warnings"><strong style="color: {{ $row->issueColor }};">{{ $row->stats['error'] + $row->stats['warning'] }}</strong></td>
                                <td style="white-space: nowrap; color: var(--ag-muted);">{{ \App\Support\UserPreferences::datetime($row->item->created_at) }}</td>
                                <td class="sys-actions" style="white-space: nowrap;">
                                    @if($row->canEdit)
                                        <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered.edit', ['systemId' => $row->item->id]) }}"><i class="fas fa-pen"></i> Edit</a>
                                    @endif
                                    <a class="ag-btn ag-btn--accent" href="{{ route('systems-registered.services', ['systemId' => $row->item->id]) }}"><i class="fas fa-cubes"></i> Services</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        (function () {
            const wrap = document.getElementById('sysView');
            const buttons = wrap.querySelectorAll('.sys-toggle button');
            function setView(view) {
                wrap.dataset.sysView = view;
                buttons.forEach(b => b.classList.toggle('is-active', b.dataset.view === view));
                try { localStorage.setItem('systemsView', view); } catch (e) {}
            }
            buttons.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));
            try { if (localStorage.getItem('systemsView') === 'list') { setView('list'); } } catch (e) {}
        })();
        </script>
    @endif
    @include('partials.simple-pager', ['pager' => $items])
</div>


@endsection
