@extends('app')

@section('title', 'System Services - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <div>
            <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">Services</h1>
            <p style="color: var(--ag-muted); font-size: 14px;">System #{{ $system->id }} - {{ $system->system_name ?? 'N/A' }}</p>
        </div>
        <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered') }}" style="text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Systems
        </a>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
            <i class="fas fa-filter" style="color: var(--ag-text); font-size: 18px;"></i>
            <h3 style="font-size: 16px; font-weight: 500; color: var(--ag-text); margin: 0;">Search & Filter Services</h3>
        </div>
        <form method="GET" action="{{ route('systems-registered.services', ['systemId' => $system->id]) }}">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="ag-label">Service Name</label>
                    <input class="ag-input" type="text" name="service_name" value="{{ request('service_name') }}" placeholder="Search by service" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#111827'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label class="ag-label">Status</label>
                    <select class="ag-select" name="status" style="width: 100%; cursor: pointer; transition: border-color 0.2s;" onfocus="this.style.borderColor='#111827'" onblur="this.style.borderColor='#e0e0e0'">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="ag-btn ag-btn--accent" type="submit">
                    <i class="fas fa-search"></i> Search
                </button>
                <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered.services', ['systemId' => $system->id]) }}" style="text-decoration: none; display: inline-block;">
                    <i class="fas fa-redo"></i> Clear
                </a>
            </div>
        </form>
    </div>

    @if($services->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-cubes" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No services found for this system</p>
        </div>
    @else
        <style>
            .svc-toolbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
            .svc-toggle { display: inline-flex; border: 1px solid var(--ag-line); border-radius: 10px; overflow: hidden; }
            .svc-toggle button { background: none; border: 0; padding: 6px 12px; cursor: pointer; color: var(--ag-muted); font-size: 13px; }
            .svc-toggle button.is-active { background: var(--ag-surface); color: var(--ag-text); font-weight: 600; }
            .svc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
            .svc-card { padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; }
            .svc-name { font-size: 15px; font-weight: 600; color: var(--ag-text); word-break: break-word; }
            .svc-sub { font-size: 12px; color: var(--ag-muted); }
            .svc-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
            .svc-nums { display: flex; gap: 14px; font-size: 12px; color: var(--ag-muted); }
            .svc-nums strong { color: var(--ag-text); font-size: 14px; }
            .svc-actions { display: flex; justify-content: flex-end; }
            .svc-actions .ag-btn { text-decoration: none; padding: 5px 10px; font-size: 12px; }
            .svc-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 640px; }
            .svc-table th { text-align: left; padding: 12px; color: var(--ag-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid var(--ag-line); }
            .svc-table td { padding: 12px; border-bottom: 1px solid var(--ag-line); color: var(--ag-text); }
            [data-svc-view="list"] .svc-grid, [data-svc-view="card"] .svc-list { display: none !important; }
        </style>

        <div id="svcView" data-svc-view="card">
            <div class="svc-toolbar">
                <div class="svc-toggle" role="group" aria-label="View">
                    <button type="button" data-view="card" class="is-active"><i class="fas fa-th-large"></i> Cards</button>
                    <button type="button" data-view="list"><i class="fas fa-list"></i> List</button>
                </div>
            </div>

            <div class="svc-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
                @foreach($services as $service)
                    @php $isActive = strtolower((string) $service->status) === 'active'; @endphp
                    <div style="background: linear-gradient(180deg, var(--ag-card) 0%, var(--ag-surface) 100%); border-radius: 16px; border: 2px solid {{ ['error' => '#e45757', 'warning' => '#e0a030'][$aiStatus[$service->service_id] ?? ''] ?? '#e6e9ee' }}; box-shadow: var(--ag-shadow); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                        <div class="ag-banner" style="border-radius: 0; padding: 20px; color: white;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-size: 11px; opacity: 0.85;">SERVICE ID</div>
                                    <div style="font-size: 18px; font-weight: bold;">#{{ $service->service_id }}</div>
                                </div>
                                <div style="padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; background: {{ $isActive ? '#137a54' : '#b33b3b' }}; border: 1px solid rgba(255,255,255,0.3); text-transform: uppercase; letter-spacing: 0.4px;">
                                    {{ ucfirst($service->status) }}
                                </div>
                            </div>
                        </div>

                        <div style="padding: 24px;">
                            <div style="margin-bottom: 12px;">
                                <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Service Name</div>
                                <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $service->service_name }}</div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                            AI Check @include('partials.ai-badge', ['status' => $aiStatus[$service->service_id] ?? null])
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                                <div style="background: var(--ag-surface); border-radius: 12px; padding: 10px;">
                                    <div style="font-size: 10px; color: var(--ag-muted); font-weight: 600;">CONFIG FILES</div>
                                    <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $service->config_count ?? 0 }}</div>
                                </div>
                                <div style="background: var(--ag-surface); border-radius: 12px; padding: 10px;">
                                    <div style="font-size: 10px; color: var(--ag-muted); font-weight: 600;">LATEST VERSION</div>
                                    <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $service->latest_version ?? 'N/A' }}</div>
                                </div>
                            </div>

                            <button class="ag-btn ag-btn--accent" onclick="window.location.href='{{ route('configuration-backups.service-versions', ['serviceId' => $service->service_id]) }}'"
                                    style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease;">
                                <i class="fas fa-folder-open"></i> View Configuration Files & Versions
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="ag-card svc-list" style="padding: 0; overflow-x: auto;">
                <table class="svc-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Status</th>
                            <th>AI Check</th>
                            <th>Service ID</th>
                            <th>Config Files</th>
                            <th>Latest Version</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($services as $service)
                            @php $isActive = strtolower((string) $service->status) === 'active'; @endphp
                            <tr>
                                <td><strong>{{ $service->service_name }}</strong></td>
                                <td style="white-space: nowrap;"><span class="svc-dot" style="background: {{ $isActive ? '#1fa874' : '#8a9099' }};"></span>{{ ucfirst($service->status) }}</td>
                                <td>@include('partials.ai-badge', ['status' => $aiStatus[$service->service_id] ?? null])</td>
                                <td style="color: var(--ag-muted);">#{{ $service->service_id }}</td>
                                <td>{{ $service->config_count ?? 0 }}</td>
                                <td>{{ $service->latest_version ?? 'N/A' }}</td>
                                <td class="svc-actions">
                                    <a class="ag-btn ag-btn--accent" href="{{ route('configuration-backups.service-versions', ['serviceId' => $service->service_id]) }}"><i class="fas fa-code-branch"></i> Versions</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        (function () {
            const wrap = document.getElementById('svcView');
            const buttons = wrap.querySelectorAll('.svc-toggle button');
            function setView(view) {
                wrap.dataset.svcView = view;
                buttons.forEach(b => b.classList.toggle('is-active', b.dataset.view === view));
                try { localStorage.setItem('servicesView', view); } catch (e) {}
            }
            buttons.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));
            try { if (localStorage.getItem('servicesView') === 'list') { setView('list'); } } catch (e) {}
        })();
        </script>
    @endif
</div>
@endsection
