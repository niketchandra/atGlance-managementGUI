@extends('app')

@section('title', 'Configuration Backups - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">Configuration Backups</h1>
        <p style="color: var(--ag-muted); font-size: 14px;">Manage and download your configuration backup files</p>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
            <i class="fas fa-filter" style="color: var(--ag-text); font-size: 18px;"></i>
            <h3 style="font-size: 16px; font-weight: 500; color: var(--ag-text); margin: 0;">Search & Filter</h3>
        </div>
        <form method="GET" action="{{ route('configuration-backups') }}">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="ag-label">Service Name</label>
                    <input class="ag-input" type="text" name="service_name" value="{{ request('service_name') }}" placeholder="Search by service" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#000000'" onblur="this.style.borderColor='#b3b3b3'">
                </div>
                <div>
                    <label class="ag-label">Status</label>
                    <select class="ag-select" name="status" style="width: 100%; transition: border-color 0.2s; cursor: pointer;" onfocus="this.style.borderColor='#000000'" onblur="this.style.borderColor='#b3b3b3'">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="ag-label">Date</label>
                    <input class="ag-input" type="date" name="date" value="{{ request('date') }}" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#000000'" onblur="this.style.borderColor='#b3b3b3'">
                </div>
                <div>
                    <label class="ag-label">System ID</label>
                    <input class="ag-input" type="text" name="system_id" value="{{ request('system_id') }}" placeholder="Search by system id" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#000000'" onblur="this.style.borderColor='#b3b3b3'">
                </div>
                <div>
                    <label class="ag-label">Hash</label>
                    <input class="ag-input" type="text" name="hash" value="{{ request('hash') }}" placeholder="Search by hash" style="width: 100%; transition: border-color 0.2s;" onfocus="this.style.borderColor='#000000'" onblur="this.style.borderColor='#b3b3b3'">
                </div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button class="ag-btn ag-btn--accent" type="submit">
                    <i class="fas fa-search"></i> Search
                </button>
                <a class="ag-btn ag-btn--ghost" href="{{ route('configuration-backups') }}" style="text-decoration: none; display: inline-block;">
                    <i class="fas fa-redo"></i> Clear Filters
                </a>
            </div>
        </form>
    </div>

    @if($items->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-folder-open" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No configuration backups found matching your filters</p>
        </div>
    @else
        <style>
            .cfg-toolbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
            .cfg-toggle { display: inline-flex; border: 1px solid var(--ag-line); border-radius: 10px; overflow: hidden; }
            .cfg-toggle button { background: none; border: 0; padding: 6px 12px; cursor: pointer; color: var(--ag-muted); font-size: 13px; }
            .cfg-toggle button.is-active { background: var(--ag-surface); color: var(--ag-text); font-weight: 600; }
            .cfg-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
            .cfg-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 820px; }
            .cfg-table th { text-align: left; padding: 12px; color: var(--ag-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid var(--ag-line); }
            .cfg-table td { padding: 12px; border-bottom: 1px solid var(--ag-line); color: var(--ag-text); }
            [data-cfg-view="list"] .cfg-grid, [data-cfg-view="card"] .cfg-list { display: none !important; }
        </style>

        <div id="cfgView" data-cfg-view="card">
        <div class="cfg-toolbar">
            <div class="cfg-toggle" role="group" aria-label="View">
                <button type="button" data-view="card" class="is-active"><i class="fas fa-th-large"></i> Cards</button>
                <button type="button" data-view="list"><i class="fas fa-list"></i> List</button>
            </div>
        </div>
        <div class="cfg-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
            @foreach($items as $item)
                @php
                    $isActive = strtolower((string) $item->status) === 'active';
                    $systemActive = strtolower($item->system_status ?? 'inactive') === 'active';
                    $versionsUrl = !empty($item->service_id)
                        ? route('configuration-backups.service-versions', ['serviceId' => $item->service_id])
                        : route('configuration-backups.service-versions-by-name', ['serviceName' => $item->service_name]);
                @endphp
                <div style="background: linear-gradient(180deg, var(--ag-card) 0%, var(--ag-surface) 100%); border-radius: 16px; border: 2px solid {{ ['error' => '#e45757', 'warning' => '#e0a030'][$aiStatus[$item->id] ?? ''] ?? '#e6e9ee' }}; box-shadow: var(--ag-shadow); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                    <div class="ag-banner" style="border-radius: 0; padding: 20px; color: white;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-size: 11px; opacity: 0.85;">CONFIG ID</div>
                                <div style="font-size: 18px; font-weight: bold;">#{{ $item->id }}</div>
                            </div>
                            <div style="padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; background: {{ $isActive ? '#137a54' : '#b33b3b' }}; border: 1px solid rgba(255,255,255,0.3); text-transform: uppercase; letter-spacing: 0.4px;">
                                {{ ucfirst($item->status) }}
                            </div>
                        </div>
                    </div>

                    <div style="padding: 24px;">
                        <div style="margin-bottom: 12px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Service Name</div>
                            <div style="font-size: 16px; color: var(--ag-text); font-weight: 700; word-break: break-word;">{{ $item->service_name ?? 'N/A' }}</div>
                            <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                                @if($item->system_register_id)
                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; background: {{ $systemActive ? '#1fa874' : '#8a9099' }};" title="{{ $systemActive ? 'System active' : 'System inactive' }}"></span>{{ $item->system_name ?? 'System #' . $item->system_register_id }}
                                @else
                                    No System
                                @endif
                                &middot; {{ \App\Support\UserPreferences::datetime($item->created_at) }}
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                            AI Check @include('partials.ai-badge', ['status' => $aiStatus[$item->id] ?? null])
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                            <div style="background: var(--ag-surface); border-radius: 12px; padding: 10px;">
                                <div style="font-size: 10px; color: var(--ag-muted); font-weight: 600;">VERSIONS</div>
                                <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $item->version_count ?? 1 }}</div>
                            </div>
                            <div style="background: var(--ag-surface); border-radius: 12px; padding: 10px;">
                                <div style="font-size: 10px; color: var(--ag-muted); font-weight: 600;">LATEST VERSION</div>
                                <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $item->version ?? 'N/A' }}</div>
                            </div>
                        </div>

                        <button class="ag-btn ag-btn--accent" onclick="window.location.href='{{ $versionsUrl }}'"
                                style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease;">
                            <i class="fas fa-folder-open"></i> View Configuration Files & Versions
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="ag-card cfg-list" style="padding: 0; overflow-x: auto;">
            <table class="cfg-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Status</th>
                        <th>AI Check</th>
                        <th>Config ID</th>
                        <th>System</th>
                        <th>Latest Version</th>
                        <th>Versions</th>
                        <th>Created</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        @php
                            $systemActive = strtolower($item->system_status ?? 'inactive') === 'active';
                            $versionsUrl = !empty($item->service_id)
                                ? route('configuration-backups.service-versions', ['serviceId' => $item->service_id])
                                : route('configuration-backups.service-versions-by-name', ['serviceName' => $item->service_name]);
                        @endphp
                        <tr>
                            <td><strong>{{ $item->service_name ?? 'N/A' }}</strong></td>
                            <td>{{ ucfirst($item->status) }}</td>
                            <td>@include('partials.ai-badge', ['status' => $aiStatus[$item->id] ?? null])</td>
                            <td style="color: var(--ag-muted);">#{{ $item->id }}</td>
                            <td style="white-space: nowrap;">
                                @if($item->system_register_id)
                                    <span class="cfg-dot" style="background: {{ $systemActive ? '#1fa874' : '#8a9099' }};" title="{{ $systemActive ? 'Active' : 'Inactive' }}"></span>{{ $item->system_name ?? 'System #' . $item->system_register_id }}
                                @else
                                    <span style="color: var(--ag-muted);">No System</span>
                                @endif
                            </td>
                            <td>{{ $item->version ?? 'N/A' }}</td>
                            <td>{{ $item->version_count ?? 1 }}</td>
                            <td style="white-space: nowrap; color: var(--ag-muted);">{{ \App\Support\UserPreferences::datetime($item->created_at) }}</td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a class="ag-btn ag-btn--ghost" href="{{ route('configuration-backups.view', ['id' => $item->id]) }}" style="text-decoration: none; padding: 5px 10px; font-size: 12px;"><i class="fas fa-file-alt"></i> View</a>
                                <a class="ag-btn ag-btn--accent" href="{{ $versionsUrl }}" style="text-decoration: none; padding: 5px 10px; font-size: 12px;"><i class="fas fa-code-branch"></i> Versions</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>

        <script>
        (function () {
            const wrap = document.getElementById('cfgView');
            const buttons = wrap.querySelectorAll('.cfg-toggle button');
            function setView(view) {
                wrap.dataset.cfgView = view;
                buttons.forEach(b => b.classList.toggle('is-active', b.dataset.view === view));
                try { localStorage.setItem('configBackupsView', view); } catch (e) {}
            }
            buttons.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));
            try { if (localStorage.getItem('configBackupsView') === 'list') { setView('list'); } } catch (e) {}
        })();
        </script>
    @endif
    @include('partials.simple-pager', ['pager' => $items])
</div>

<script>
function toggleHash(contentId, linkEl) {
    const content = document.getElementById(contentId);
    if (!content) {
        return;
    }

    const isHidden = content.style.display === 'none' || content.style.display === '';
    content.style.display = isHidden ? 'block' : 'none';
    linkEl.textContent = isHidden ? 'Hide hash' : 'Show hash';
}

function copyHashById(hashElementId, button) {
    const hashElement = document.getElementById(hashElementId);
    if (!hashElement) {
        return;
    }

    const text = hashElement.textContent || '';

    // Create a temporary textarea element
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    
    // Select and copy the text
    textarea.select();
    textarea.setSelectionRange(0, 99999); // For mobile devices
    
    try {
        document.execCommand('copy');
        // Change button text to show success
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        button.style.background = '#1fa874';
        
        // Reset button after 2 seconds
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.style.background = '';
        }, 2000);
    } catch (err) {
        console.error('Failed to copy:', err);
        button.innerHTML = '<i class="fas fa-times"></i> Failed';
        button.style.background = '#e45757';
        
        setTimeout(() => {
            button.innerHTML = '<i class="fas fa-copy"></i> Copy';
            button.style.background = '';
        }, 2000);
    } finally {
        document.body.removeChild(textarea);
    }
}
</script>
@endsection
