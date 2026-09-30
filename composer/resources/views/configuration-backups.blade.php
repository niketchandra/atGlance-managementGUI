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
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
            @foreach($items as $item)
                @php
                    $isActive = strtolower($item->status) === 'active';
                    $systemActive = strtolower($item->system_status ?? 'inactive') === 'active';
                    $versionsUrl = !empty($item->service_id)
                        ? route('configuration-backups.service-versions', ['serviceId' => $item->service_id])
                        : route('configuration-backups.service-versions-by-name', ['serviceName' => $item->service_name]);
                @endphp
                <div class="ag-card ag-item-card" style="padding: 0; overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                    
                    <!-- Card Header -->
                    <div class="ag-banner" style="border-radius: 0; padding: 20px; position: relative;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="background: rgba(255,255,255,0.25); border-radius: 16px; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
                                    <i class="fas fa-server" style="font-size: 24px; color: white;"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-size: 11px; color: rgba(255,255,255,0.8); font-weight: 500; margin-bottom: 2px;">SERVICE NAME</div>
                                    <div style="font-size: 18px; color: white; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $item->service_name ?? 'N/A' }}</div>
                                </div>
                            </div>
                            <div style="background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 20px; font-size: 11px; color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                {{ ucfirst($item->status) }}
                            </div>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div style="padding: 24px;">
                        <!-- Config ID -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-hashtag" style="margin-right: 4px;"></i> Config ID
                            </div>
                            <div style="font-size: 16px; color: var(--ag-text); font-weight: 600;">
                                #{{ $item->id }}
                            </div>
                        </div>

                        <!-- System ID & Status -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-microchip" style="margin-right: 4px;"></i> System Info
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div style="font-size: 14px; color: var(--ag-subtle); font-weight: 500;">
                                    @if($item->system_register_id)
                                        System #{{ $item->system_register_id }}
                                    @else
                                        <span style="color: var(--ag-muted);">No System</span>
                                    @endif
                                </div>
                                @if($item->system_register_id)
                                    <div style="background: {{ $systemActive ? '#e8f5e9' : '#ffebee' }}; color: {{ $systemActive ? '#2e7d32' : '#c62828' }}; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                        {{ $systemActive ? 'Active' : 'Inactive' }}
                                    </div>
                                @endif
                            </div>
                            @if($item->validation_hash)
                                <div class="ag-card" style="margin-top: 8px; padding: 10px;">
                                    <div style="font-size: 11px; color: var(--ag-teal); font-weight: 600; margin-bottom: 6px;">Validation Hash</div>
                                    <a href="javascript:void(0)" id="config-hash-link-{{ $item->id }}" onclick="toggleHash('config-hash-content-{{ $item->id }}', this)" style="font-size: 12px; color: var(--ag-teal); text-decoration: underline; cursor: pointer; font-weight: 600;">
                                        Show hash
                                    </a>
                                    <div class="ag-card" id="config-hash-content-{{ $item->id }}" style="display: none; margin-top: 8px; padding: 8px; font-size: 11px; color: var(--ag-text); word-break: break-all; line-height: 1.5; position: relative; padding-right: 70px;">
                                        <span id="config-hash-value-{{ $item->id }}">{{ $item->validation_hash }}</span>
                                        <button onclick="copyHashById('config-hash-value-{{ $item->id }}', this)" style="position: absolute; top: 6px; right: 6px; background: var(--ag-teal); color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 10px; cursor: pointer; font-weight: 600; transition: background 0.2s;">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Created At -->
                        <div style="margin-bottom: 20px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-clock" style="margin-right: 4px;"></i> Created At
                            </div>
                            <div style="font-size: 13px; color: var(--ag-muted);">
                                {{ \App\Support\UserPreferences::datetime($item->created_at) }}
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 16px; border-top: 1px solid var(--ag-line);">
                                    <button class="ag-btn ag-btn--accent" onclick="window.location.href='{{ $versionsUrl }}'" 
                                    style=" display: flex; align-items: center; justify-content: center; gap: 6px;">
                                <i class="fas fa-eye"></i> View Versions ({{ $item->version_count ?? 1 }})
                            </button>
                            <div style="background: var(--ag-surface); color: var(--ag-text); padding: 12px; border-radius: 12px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px; flex-direction: column;">
                                <div style="font-size: 10px; color: var(--ag-subtle);">LATEST VERSION</div>
                                <div style="font-size: 16px; font-weight: bold;">{{ $item->version ?? 'N/A' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
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
