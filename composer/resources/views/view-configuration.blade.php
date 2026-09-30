@extends('app')

@section('title', 'View Configuration - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">Configuration Details</h1>
                <p style="color: var(--ag-muted); font-size: 14px;">Viewing: {{ $config->file_name }}</p>
            </div>
            <a class="ag-btn" href="{{ route('configuration-backups') }}" style="text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div style="background: var(--ag-card); border-radius: 16px; box-shadow: var(--ag-shadow); overflow: hidden;">
        <!-- File Info Header -->
        <div class="ag-banner" style="border-radius: 0; padding: 24px; color: white;">
            <h2 style="font-size: 20px; font-weight: 500; margin-bottom: 16px;">
                <i class="fas fa-file-code"></i> {{ $config->file_name }}
            </h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Config ID</div>
                    <div style="font-size: 15px; font-weight: 600;">#{{ $config->id }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Service Name</div>
                    <div style="font-size: 15px; font-weight: 600;">{{ $config->service_name ?? 'N/A' }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Status</div>
                    <div style="font-size: 15px; font-weight: 600;">{{ ucfirst($config->status) }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Created At</div>
                    <div style="font-size: 15px; font-weight: 600;">{{ \App\Support\UserPreferences::date($config->created_at) }}</div>
                </div>
            </div>
        </div>

        <!-- Configuration Content -->
        <div style="padding: 24px;">
            <h3 style="font-size: 16px; font-weight: 500; color: var(--ag-text); margin-bottom: 16px;">
                <i class="fas fa-code"></i> Configuration Content
            </h3>
            @if($config->data)
                <pre style="background: var(--ag-surface); padding: 20px; border-radius: 12px; overflow-x: auto; font-size: 13px; line-height: 1.6; color: var(--ag-text); max-height: 600px; overflow-y: auto;">{{ $config->data }}</pre>
            @else
                <div class="ag-card" style="padding: 16px; color: var(--ag-teal);">
                    <i class="fas fa-exclamation-triangle"></i> No configuration data available for this file.
                </div>
            @endif
        </div>

        <!-- Action Buttons -->
        <div style="padding: 0 24px 24px;">
            <div style="display: flex; gap: 12px;">
                <a class="ag-btn" href="{{ route('configuration-backups.download', $config->id) }}" 
                   style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s ease, box-shadow 0.2s ease;">
                    <i class="fas fa-download"></i> Download Configuration
                </a>
                @if($config->validation_hash)
                    <button class="ag-btn ag-btn--ghost" onclick="alert('Validation Hash:\n{{ $config->validation_hash }}')" 
                            style="display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s ease, box-shadow 0.2s ease;">
                        <i class="fas fa-fingerprint"></i> View Hash
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
