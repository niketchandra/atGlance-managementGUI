@extends('app')

@section('title', 'User Service Versions - Admin - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">
                    <i class="fas fa-code-branch"></i> {{ $service->service_name }} - All Versions
                </h1>
                <p style="color: var(--ag-muted); font-size: 14px;">User: {{ $user->name }} • View and download all configuration versions for this service</p>
            </div>
            <a href="{{ route('admin.users.systems.services', ['user' => $user->id, 'systemId' => $service->system_id]) }}" style="background: #2cb7d9; color: white; padding: 10px 20px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 14px;">
                <i class="fas fa-arrow-left"></i> Back to Services
            </a>
        </div>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 14px; color: var(--ag-muted); margin-bottom: 4px;">Total Versions</div>
                <div style="font-size: 32px; font-weight: bold; color: #2cb7d9;">{{ count($versions) }}</div>
            </div>
            <div style="background: linear-gradient(135deg, #2cb7d9 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 16px; text-align: center; min-width: 150px;">
                <div style="font-size: 12px; opacity: 0.9; margin-bottom: 4px;">SERVICE ID</div>
                <div style="font-size: 16px; font-weight: bold;">#{{ $service->service_id }}</div>
            </div>
        </div>
    </div>

    @if($versions->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-folder-open" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No versions found for this service</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 24px;">
            @foreach($versions as $version)
                @php
                    $isActive = strtolower((string) $version->status) === 'active';
                @endphp
                <div style="background: linear-gradient(180deg, #ffffff 0%, #f7fafc 100%); border-radius: 14px; border: 2px solid {{ $isActive ? '#1fa874' : '#e45757' }}; box-shadow: 0 8px 20px rgba(0,0,0,0.08); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">

                    <div style="background: {{ $isActive ? 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)' : 'linear-gradient(135deg, #eb3349 0%, #f45c43 100%)' }}; padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: rgba(255,255,255,0.25); border-radius: 16px; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-file-code" style="font-size: 20px; color: white;"></i>
                                </div>
                                <div>
                                    <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 500;">VERSION</div>
                                    <div style="font-size: 20px; color: white; font-weight: bold;">{{ $version->version ?? 'N/A' }}</div>
                                </div>
                            </div>
                            <div style="background: {{ $isActive ? 'rgba(76, 175, 80, 0.95)' : 'rgba(244, 67, 54, 0.95)' }}; padding: 5px 12px; border-radius: 16px; font-size: 10px; color: white; font-weight: 600; text-transform: uppercase;">
                                {{ ucfirst($version->status) }}
                            </div>
                        </div>
                    </div>

                    <div style="padding: 20px;">
                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-file" style="margin-right: 4px;"></i> File Name
                            </div>
                            <div style="font-size: 14px; color: var(--ag-text); font-weight: 600; word-break: break-all;">
                                {{ $version->file_name ?? 'N/A' }}
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-hashtag" style="margin-right: 4px;"></i> Config ID
                            </div>
                            <div style="font-size: 14px; color: var(--ag-subtle); font-weight: 500;">
                                #{{ $version->id }}
                            </div>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-clock" style="margin-right: 4px;"></i> Timeline
                            </div>
                            <div style="font-size: 12px; color: var(--ag-muted); line-height: 1.6;">
                                <div><strong>Created:</strong> {{ \App\Support\UserPreferences::datetime($version->created_at) }}</div>
                                @if($version->updated_at && $version->updated_at != $version->created_at)
                                    <div><strong>Updated:</strong> {{ \App\Support\UserPreferences::datetime($version->updated_at) }}</div>
                                @endif
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 16px; border-top: 1px solid var(--ag-line);">
                            <button class="ag-btn ag-btn--ghost" onclick="window.location.href='{{ route('configuration-backups.view', ['id' => $version->id]) }}'"
                                    style="transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="ag-btn ag-btn--ghost" onclick="window.location.href='{{ route('configuration-backups.download', ['id' => $version->id]) }}'"
                                    style="transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                <i class="fas fa-download"></i> Download
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
