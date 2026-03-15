@extends('app')

@section('title', 'Systems Registered - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 28px; font-weight: bold; color: #333; margin-bottom: 8px;">Systems Registered</h1>
        <p style="color: #666; font-size: 14px;">Manage and monitor your registered systems</p>
    </div>

    <!-- Search/Filter Section -->
    <div style="background: white; padding: 24px; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
            <i class="fas fa-filter" style="color: #667eea; font-size: 18px;"></i>
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
                <button type="submit" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.2s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="{{ route('systems-registered') }}" style="background: #f5f5f5; color: #666; border: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: background 0.2s ease;" onmouseover="this.style.background='#e0e0e0'" onmouseout="this.style.background='#f5f5f5'">
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

                <div style="background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%); border-radius: 14px; border: 2px solid {{ $isActive ? '#4caf50' : '#f44336' }}; box-shadow: 0 8px 20px rgba(0,0,0,0.08); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;" 
                     onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.15)';" 
                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)';">
                    
                    <!-- Card Header -->
                    <div style="background: {{ $isActive ? 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)' : 'linear-gradient(135deg, #eb3349 0%, #f45c43 100%)' }}; padding: 20px; position: relative;">
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
                            <div style="background: {{ $isActive ? 'rgba(76, 175, 80, 0.95)' : 'rgba(244, 67, 54, 0.95)' }}; padding: 6px 14px; border-radius: 20px; font-size: 11px; color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                {{ ucfirst($item->status) }}
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

                        <!-- Hash -->
                        @if($item->validation_hash)
                            <div style="margin-bottom: 18px; padding: 12px; background: #fff3e0; border-radius: 8px; border-left: 3px solid #ff9800;">
                                <div style="font-size: 11px; color: #e65100; font-weight: 600; margin-bottom: 6px;">
                                    <i class="fas fa-fingerprint" style="margin-right: 4px;"></i> VALIDATION HASH
                                </div>
                                <a href="javascript:void(0)" id="hash-link-{{ $item->id }}" onclick="toggleHash('hash-content-{{ $item->id }}', this)" style="font-size: 12px; color: #f57c00; text-decoration: underline; cursor: pointer; font-weight: 600;">
                                    Show hash
                                </a>
                                <div id="hash-content-{{ $item->id }}" style="display: none; margin-top: 8px; background: white; padding: 10px; border-radius: 6px; border: 1px solid #ffe0b2; font-family: 'Courier New', monospace; font-size: 12px; color: #333; word-break: break-all; line-height: 1.6; position: relative; padding-right: 70px;">
                                    <span id="hash-value-{{ $item->id }}">{{ $item->validation_hash }}</span>
                                    <button onclick="copyHashById('hash-value-{{ $item->id }}', this)" style="position: absolute; top: 8px; right: 8px; background: #ff9800; color: white; border: none; padding: 6px 10px; border-radius: 6px; font-size: 11px; cursor: pointer; font-weight: 600; transition: background 0.2s;" onmouseover="this.style.background='#f57c00'" onmouseout="this.style.background='#ff9800'">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        @endif

                        <!-- Created At -->
                        <div style="padding-top: 16px; border-top: 1px solid #f0f0f0; margin-bottom: 16px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-clock" style="margin-right: 4px;"></i> Registered On
                            </div>
                            <div style="font-size: 13px; color: #666;">
                                {{ \Carbon\Carbon::parse($item->created_at)->format('M d, Y H:i A') }}
                            </div>
                        </div>

                        <!-- Action Button -->
                        <div>
                            <button onclick="window.location.href='{{ route('systems-registered.services', ['systemId' => $item->id]) }}'" 
                                    style="width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;"
                                    onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.4)';" 
                                    onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none';">
                                <i class="fas fa-cubes"></i> View Services
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
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
        button.style.background = '#4caf50';
        
        // Reset button after 2 seconds
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.style.background = '#ff9800';
        }, 2000);
    } catch (err) {
        console.error('Failed to copy:', err);
        button.innerHTML = '<i class="fas fa-times"></i> Failed';
        button.style.background = '#f44336';
        
        setTimeout(() => {
            button.innerHTML = '<i class="fas fa-copy"></i> Copy';
            button.style.background = '#ff9800';
        }, 2000);
    } finally {
        document.body.removeChild(textarea);
    }
}
</script>

@endsection
