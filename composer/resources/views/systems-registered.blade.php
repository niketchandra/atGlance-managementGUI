@extends('app')

@section('title', 'Systems Registered - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    @if(session('success'))
        <div style="padding:12px; border-radius:8px; background:#dcfce7; color:#166534; margin-bottom:16px;">
            {{ session('success') }}
        </div>
    @endif

    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 28px; font-weight: bold; color: #333; margin-bottom: 8px;">Systems Registered</h1>
        <p style="color: #666; font-size: 14px;">Manage and monitor your registered systems</p>
    </div>

    <!-- Search/Filter Section -->
    <div style="background: white; padding: 24px; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
            <i class="fas fa-filter" style="color: #111111; font-size: 18px;"></i>
            <h3 style="font-size: 16px; font-weight: bold; color: #333; margin: 0;">Search & Filter</h3>
        </div>
        <form method="GET" action="{{ route('systems-registered') }}" id="filterForm">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="font-size: 12px; color: #666; font-weight: 600; display: block; margin-bottom: 6px;">Name</label>
                    <input type="text" name="name" value="{{ request('name') }}" placeholder="Search by name" style="width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label style="font-size: 12px; color: #666; font-weight: 600; display: block; margin-bottom: 6px;">IP Address</label>
                    <input type="text" name="ip" value="{{ request('ip') }}" placeholder="Search by IP" style="width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label style="font-size: 12px; color: #666; font-weight: 600; display: block; margin-bottom: 6px;">Tags</label>
                    <input type="text" name="tags" value="{{ request('tags') }}" placeholder="Search by tags" style="width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label style="font-size: 12px; color: #666; font-weight: 600; display: block; margin-bottom: 6px;">OS Type</label>
                    <input type="text" name="os" value="{{ request('os') }}" placeholder="Search by OS" style="width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
                <div>
                    <label style="font-size: 12px; color: #666; font-weight: 600; display: block; margin-bottom: 6px;">Status</label>
                    <select name="status" style="width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; background: white; cursor: pointer;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 12px; color: #666; font-weight: 600; display: block; margin-bottom: 6px;">Hash Key</label>
                    <input type="text" name="hash" value="{{ request('hash') }}" placeholder="Search by hash" style="width: 100%; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e0e0'">
                </div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="submit" style="background: #000000; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.2s ease, background 0.2s ease;" onmouseover="this.style.transform='scale(1.05)'; this.style.background='#555555'" onmouseout="this.style.transform='scale(1)'; this.style.background='#000000'">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="{{ route('systems-registered') }}" style="background: #d1d1d1; color: #333333; border: 1px solid #a8a8a8; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: background 0.2s ease;" onmouseover="this.style.background='#b6b6b6'" onmouseout="this.style.background='#d1d1d1'">
                    <i class="fas fa-redo"></i> Clear Filters
                </a>
            </div>
        </form>
    </div>

    <!-- Systems Cards -->
    @if($items->isEmpty())
        <div style="background: white; padding: 60px; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center;">
            <i class="fas fa-server" style="font-size: 48px; color: #ddd; margin-bottom: 16px;"></i>
            <p style="color: #999; font-size: 16px;">No systems found matching your filters</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
            @foreach($items as $item)
                @php
                    $isActive = strtolower((string) $item->status) === 'active';
                    $metadata = [];
                    if (!empty($item->metadata)) {
                        $decoded = json_decode($item->metadata, true);
                        if (is_array($decoded)) {
                            $metadata = $decoded;
                        }
                    }

                    $version = $metadata['version']
                        ?? $metadata['os_version']
                        ?? $metadata['ubuntu_version']
                        ?? $metadata['linux_version']
                        ?? $metadata['release']
                        ?? 'N/A';
                @endphp

                <div style="background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%); border-radius: 14px; border: 2px solid #d0d7de; box-shadow: 0 8px 20px rgba(0,0,0,0.08); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;" 
                     onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.15)';" 
                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)';">
                    
                    <!-- Card Header -->
                    <div style="background: linear-gradient(135deg, #1f2937 0%, #374151 100%); padding: 20px; position: relative;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="background: rgba(255,255,255,0.25); border-radius: 10px; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
                                    <i class="fab fa-linux" style="font-size: 24px; color: white;"></i>
                                </div>
                                <div>
                                    <div style="font-size: 11px; color: rgba(255,255,255,0.8); font-weight: 500; margin-bottom: 2px;">SYSTEM ID</div>
                                    <div style="font-size: 18px; color: white; font-weight: bold;">#{{ $item->id }}</div>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                                <div style="background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 20px; font-size: 11px; color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                    {{ ucfirst($item->status) }}
                                </div>
                                @if((bool) ($item->is_locked ?? false))
                                    <div style="background:#7f1d1d; color:white; padding:6px 10px; border-radius:8px; font-size:11px; font-weight:600;">
                                        Locked
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div style="padding: 24px;">
                        <!-- System Name -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-desktop" style="margin-right: 4px;"></i> System Name
                            </div>
                            <div style="font-size: 16px; color: #333; font-weight: 600;">
                                {{ $item->system_name ?? 'N/A' }}
                            </div>
                        </div>

                        <!-- IP Address -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-network-wired" style="margin-right: 4px;"></i> IP Address
                            </div>
                            <div style="font-size: 14px; color: #555; font-weight: 500; font-family: 'Courier New', monospace;">
                                {{ $item->ip_address ?? 'N/A' }}
                            </div>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-globe" style="margin-right: 4px;"></i> Public Facing
                            </div>
                            <div style="font-size: 14px; color: #555; font-weight: 500;">
                                {{ ((int) ($item->public_facing ?? 0) === 1) ? 'Yes' : 'No' }}
                                @if(!empty($item->public_ip))
                                    <span style="color:#6b7280;">({{ $item->public_ip }})</span>
                                @endif
                            </div>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-layer-group" style="margin-right: 4px;"></i> Workspace
                            </div>
                            <div style="font-size: 14px; color: #555; font-weight: 500;">
                                {{ $item->workspace_name ?? 'Not assigned' }}
                            </div>
                        </div>

                        <!-- OS Version -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fab fa-linux" style="margin-right: 4px;"></i> OS Version
                            </div>
                            <div style="font-size: 14px; color: #555; font-weight: 500;">
                                {{ $item->os_type ?? 'Linux' }} - {{ $version }}
                            </div>
                        </div>

                        <!-- Tags -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-tags" style="margin-right: 4px;"></i> Tags
                            </div>
                            @if($item->tags)
                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    @foreach(explode(',', $item->tags) as $tag)
                                        <span style="background: #e3f2fd; color: #1976d2; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                            {{ trim($tag) }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <div style="font-size: 13px; color: #999;">No tags</div>
                            @endif
                        </div>

                        @php
                            $isAdminRole = in_array((int) (auth()->user()->rbac_id ?? 0), [100, 101], true);
                            $isLocked = (bool) ($item->is_locked ?? false);
                            $canEditSystemInfo = $isAdminRole || !$isLocked;
                        @endphp

                        <!-- Created At -->
                        <div style="padding-top: 16px; border-top: 1px solid #f0f0f0; margin-bottom: 16px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-clock" style="margin-right: 4px;"></i> Registered On
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                <div style="font-size: 13px; color: #666;">
                                    {{ \Carbon\Carbon::parse($item->created_at)->format('M d, Y H:i A') }}
                                </div>
                                @if($canEditSystemInfo)
                                    <a href="{{ route('systems-registered.edit', ['systemId' => $item->id]) }}"
                                       style="background:#f3f4f6; border:1px solid #d1d5db; color:#111827; padding:8px 10px; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px; transition:all 0.2s ease;"
                                       onmouseover="this.style.background='#e5e7eb'; this.style.boxShadow='0 4px 12px rgba(17,24,39,0.12)'"
                                       onmouseout="this.style.background='#f3f4f6'; this.style.boxShadow='none'">
                                        <i class="fas fa-pen"></i> Edit
                                    </a>
                                @else
                                    <div title="System Info is locked"
                                         style="background:#e5e7eb; border:1px solid #d1d5db; color:#6b7280; padding:8px 10px; border-radius:8px; font-size:12px; font-weight:600; cursor:not-allowed; display:flex; align-items:center; justify-content:center; gap:6px;">
                                        <i class="fas fa-lock"></i> Locked
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            @if($canEditSystemInfo)
                                <a href="{{ route('systems-registered.edit', ['systemId' => $item->id]) }}"
                                   style="background:#f3f4f6; border:1px solid #d1d5db; color:#111827; padding:12px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:8px; transition:all 0.2s ease;"
                                   onmouseover="this.style.background='#e5e7eb'; this.style.boxShadow='0 4px 12px rgba(17,24,39,0.12)'; showSystemInfoPreview('system-info-preview-{{ $item->id }}')"
                                   onmouseout="this.style.background='#f3f4f6'; this.style.boxShadow='none'; hideSystemInfoPreview('system-info-preview-{{ $item->id }}')">
                                    <i class="fas fa-pen"></i> System Info
                                </a>
                            @else
                                <div title="System Info is locked"
                                     style="background:#e5e7eb; border:1px solid #d1d5db; color:#6b7280; padding:12px; border-radius:8px; font-size:13px; font-weight:600; cursor:not-allowed; display:flex; align-items:center; justify-content:center; gap:8px;"
                                     onmouseover="showSystemInfoPreview('system-info-preview-{{ $item->id }}')"
                                     onmouseout="hideSystemInfoPreview('system-info-preview-{{ $item->id }}')">
                                    <i class="fas fa-lock"></i> System Info
                                </div>
                            @endif
                            <button onclick="window.location.href='{{ route('systems-registered.services', ['systemId' => $item->id]) }}'" 
                                    style="background: #000000; color: white; border: none; padding: 12px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;"
                                    onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(0, 0, 0, 0.25)'; this.style.background='#555555';" 
                                    onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'; this.style.background='#000000';">
                                <i class="fas fa-cubes"></i> View Services
                            </button>
                        </div>

                        <div id="system-info-preview-{{ $item->id }}"
                             onmouseover="showSystemInfoPreview('system-info-preview-{{ $item->id }}')"
                             onmouseout="hideSystemInfoPreview('system-info-preview-{{ $item->id }}')"
                             style="display:none; margin-top:10px; background:#ffffff; border:1px solid #d1d5db; border-radius:10px; padding:12px; box-shadow:0 8px 24px rgba(0,0,0,0.12);">
                            <div style="font-size:11px; color:#6b7280; font-weight:700; text-transform:uppercase; margin-bottom:8px;">System Info Preview</div>
                            <div style="display:grid; grid-template-columns: 1fr; gap:6px; font-size:12px; color:#374151;">
                                <div><strong>Private IP:</strong> {{ $item->ip_address ?? 'N/A' }}</div>
                                <div><strong>Public IP:</strong> {{ $item->public_ip ?? 'N/A' }}</div>
                                <div><strong>Public Facing:</strong> {{ ((int) ($item->public_facing ?? 0) === 1) ? 'Yes' : 'No' }}</div>
                                <div><strong>Workspace:</strong> {{ $item->workspace_name ?? 'Not assigned' }}</div>
                                <div><strong>Distro:</strong> {{ $item->distro ?? 'N/A' }}</div>
                                <div><strong>OS Version:</strong> {{ $item->version ?? $version }}</div>
                                <div><strong>Description:</strong> {{ $item->description ?? 'N/A' }}</div>
                                <div style="word-break:break-all;"><strong>Validation Hash:</strong> {{ $item->validation_hash ?? 'N/A' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
function showSystemInfoPreview(id) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }

    el.style.display = 'block';
}

function hideSystemInfoPreview(id) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }

    el.style.display = 'none';
}
</script>

@endsection
