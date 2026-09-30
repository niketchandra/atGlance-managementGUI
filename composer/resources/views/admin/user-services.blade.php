@extends('app')

@section('title', 'User System Services - Admin - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <div>
            <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">Services</h1>
            <p style="color: var(--ag-muted); font-size: 14px;">User: {{ $user->name }} • System #{{ $system->id }} - {{ $system->system_name ?? 'N/A' }}</p>
        </div>
        <a class="ag-btn" href="{{ route('admin.users.show', ['user' => $user->id]) }}" style="text-decoration: none; transition: background 0.2s ease;">
            <i class="fas fa-arrow-left"></i> Back to Systems
        </a>
    </div>

    @if($services->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-cubes" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No services found for this system</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
            @foreach($services as $service)
                @php $isActive = strtolower((string) $service->status) === 'active'; @endphp
                <div style="background: linear-gradient(180deg, var(--ag-card) 0%, var(--ag-surface) 100%); border-radius: 16px; border: 2px solid #e6e9ee; box-shadow: var(--ag-shadow); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                    <div style="background: linear-gradient(135deg, var(--ag-ink) 0%, #5b626b 100%); padding: 20px; color: white;">
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

                        <button class="ag-btn" onclick="window.location.href='{{ route('admin.users.services.versions', ['user' => $user->id, 'serviceId' => $service->service_id]) }}'"
                                style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease;">
                            <i class="fas fa-folder-open"></i> View Configuration Files & Versions
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
