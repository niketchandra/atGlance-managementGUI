@extends('app')

@section('title', 'User Dashboard - Admin - AtGlance')

@section('dashboard-content')
<div style="padding:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <div>
            <h1 style="font-size:28px; color:#111827;">User Dashboard</h1>
            <p style="color:#6b7280; margin-top:6px;">{{ $user->name }} ({{ $user->email }})</p>
        </div>
        <a href="{{ route('admin.users') }}" style="text-decoration:none; color:#4f46e5;">← Back to Users</a>
    </div>

    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px;">
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #7c3aed;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Systems</div>
            <div style="font-size:24px; font-weight:700; color:#111827;">{{ $stats['systems'] }}</div>
        </div>
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #0ea5e9;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Services</div>
            <div style="font-size:24px; font-weight:700; color:#111827;">{{ $stats['services'] }}</div>
        </div>
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #16a34a;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Configuration Files</div>
            <div style="font-size:24px; font-weight:700; color:#111827;">{{ $stats['configurations'] }}</div>
        </div>
    </div>

    @if($systems->isEmpty())
        <div style="background: white; padding: 60px; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center;">
            <i class="fas fa-server" style="font-size: 48px; color: #ddd; margin-bottom: 16px;"></i>
            <p style="color: #999; font-size: 16px;">No systems found for this user.</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
            @foreach($systems as $item)
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

                    <div style="padding: 24px;">
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-desktop" style="margin-right: 4px;"></i> System Name
                            </div>
                            <div style="font-size: 16px; color: #333; font-weight: 600;">
                                {{ $item->system_name ?? 'N/A' }}
                            </div>
                        </div>

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
                                <i class="fab fa-linux" style="margin-right: 4px;"></i> OS Version
                            </div>
                            <div style="font-size: 14px; color: #555; font-weight: 500;">
                                {{ $item->os_type ?? 'Linux' }} - {{ $version }}
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;">
                            <div style="background: #f8f9ff; border-radius: 8px; padding: 10px;">
                                <div style="font-size: 10px; color: #777; font-weight: 600;">SERVICES</div>
                                <div style="font-size: 16px; color: #333; font-weight: 700;">{{ $item->service_count }}</div>
                            </div>
                            <div style="background: #f8f9ff; border-radius: 8px; padding: 10px;">
                                <div style="font-size: 10px; color: #777; font-weight: 600;">REGISTERED</div>
                                <div style="font-size: 14px; color: #333; font-weight: 700;">{{ \Carbon\Carbon::parse($item->created_at)->format('M d, Y') }}</div>
                            </div>
                        </div>

                        <button onclick="window.location.href='{{ route('admin.users.systems.services', ['user' => $user->id, 'systemId' => $item->id]) }}'"
                                style="width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;"
                                onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.4)';"
                                onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none';">
                            <i class="fas fa-cubes"></i> View Services
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
