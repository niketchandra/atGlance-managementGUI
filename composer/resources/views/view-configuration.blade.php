@extends('app')

@section('title', 'View Configuration - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h1 style="font-size: 28px; font-weight: bold; color: #333; margin-bottom: 8px;">Configuration Details</h1>
                <p style="color: #666; font-size: 14px;">Viewing: {{ $config->file_name }}</p>
            </div>
            <a href="{{ route('configuration-backups') }}" style="background: #667eea; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div style="background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); overflow: hidden;">
        <!-- File Info Header -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; color: white;">
            <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 16px;">
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
                    <div style="font-size: 15px; font-weight: 600;">{{ \Carbon\Carbon::parse($config->created_at)->format('M d, Y') }}</div>
                </div>
            </div>
        </div>

        <!-- Configuration Content -->
        <div style="padding: 24px;">
            <h3 style="font-size: 16px; font-weight: bold; color: #333; margin-bottom: 16px;">
                <i class="fas fa-code"></i> Configuration Content
            </h3>
            @if($config->data)
                <pre style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6; color: #333; max-height: 600px; overflow-y: auto;">{{ $config->data }}</pre>
            @else
                <div style="background: #fff3e0; padding: 16px; border-radius: 8px; border-left: 4px solid #ff9800; color: #e65100;">
                    <i class="fas fa-exclamation-triangle"></i> No configuration data available for this file.
                </div>
            @endif
        </div>

        <!-- Action Buttons -->
        <div style="padding: 0 24px 24px;">
            <div style="display: flex; gap: 12px;">
                <a href="{{ route('configuration-backups.download', $config->id) }}" 
                   style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-download"></i> Download Configuration
                </a>
                @if($config->validation_hash)
                    <button onclick="alert('Validation Hash:\n{{ $config->validation_hash }}')" 
                            style="background: #ffc107; color: #333; padding: 12px 24px; border-radius: 8px; border: none; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-fingerprint"></i> View Hash
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
