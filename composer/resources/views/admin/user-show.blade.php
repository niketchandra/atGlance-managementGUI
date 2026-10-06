@extends('app')

@section('title', 'User Dashboard - Admin - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h1 style="font-size: 30px; color: var(--ag-text);">User Dashboard</h1>
            <p style="display: flex; align-items: center; gap: 8px; color: var(--ag-muted); margin-top: 6px;"><x-user-avatar :user="$user" size="28" />{{ $user->name }} ({{ $user->email }})</p>
        </div>
        <a href="{{ route('admin.users') }}" style="text-decoration: none; color: var(--ag-teal);">← Back to Users</a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; margin-bottom: 24px;">
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Systems</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--ag-text);">{{ $stats['systems'] }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Services</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--ag-text);">{{ $stats['services'] }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Configuration Files</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--ag-text);">{{ $stats['configurations'] }}</div>
        </div>
    </div>

    @if($systems->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-server" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No systems found for this user.</p>
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

                <div style="background: linear-gradient(180deg, #ffffff 0%, #f7fafc 100%); border-radius: 14px; border: 2px solid {{ $isActive ? '#1fa874' : '#e45757' }}; box-shadow: 0 8px 20px rgba(0,0,0,0.08); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">

                    <div style="background: {{ $isActive ? 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)' : 'linear-gradient(135deg, #eb3349 0%, #f45c43 100%)' }}; padding: 20px; position: relative;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="background: rgba(255,255,255,0.25); border-radius: 16px; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px);">
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
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-desktop" style="margin-right: 4px;"></i> System Name
                            </div>
                            <div style="font-size: 16px; color: var(--ag-text); font-weight: 600;">
                                {{ $item->system_name ?? 'N/A' }}
                            </div>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-network-wired" style="margin-right: 4px;"></i> IP Address
                            </div>
                            <div style="font-size: 14px; color: var(--ag-subtle); font-weight: 500;">
                                {{ $item->ip_address ?? 'N/A' }}
                            </div>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fab fa-linux" style="margin-right: 4px;"></i> OS Version
                            </div>
                            <div style="font-size: 14px; color: var(--ag-subtle); font-weight: 500;">
                                {{ $item->os_type ?? 'Linux' }} - {{ $version }}
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;">
                            <div style="background: #f8f9ff; border-radius: 12px; padding: 10px;">
                                <div style="font-size: 10px; color: var(--ag-muted); font-weight: 600;">SERVICES</div>
                                <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $item->service_count }}</div>
                            </div>
                            <div style="background: #f8f9ff; border-radius: 12px; padding: 10px;">
                                <div style="font-size: 10px; color: var(--ag-muted); font-weight: 600;">REGISTERED</div>
                                <div style="font-size: 14px; color: var(--ag-text); font-weight: 700;">{{ \App\Support\UserPreferences::date($item->created_at) }}</div>
                            </div>
                        </div>

                        <button class="ag-btn ag-btn--ghost" onclick="window.location.href='{{ route('admin.users.systems.services', ['user' => $user->id, 'systemId' => $item->id]) }}'"
                                style="width: 100%; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <i class="fas fa-cubes"></i> View Services
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
