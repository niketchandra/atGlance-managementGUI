@extends('app')

@section('title', 'Service Versions - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">
                    <i class="fas fa-code-branch"></i> {{ $serviceName }} - All Versions
                </h1>
                <p style="color: var(--ag-muted); font-size: 14px;">View and download all configuration versions for this service</p>
            </div>
            <a class="ag-btn" href="{{ route('systems-registered.services', ['systemId' => $systemId]) }}" style="text-decoration: none; transition: background 0.2s ease;">
                <i class="fas fa-arrow-left"></i> Back to Services
            </a>
        </div>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 14px; color: var(--ag-muted); margin-bottom: 4px; font-weight: 600;">Total Versions</div>
                <div style="font-size: 32px; font-weight: bold; color: var(--ag-text);">{{ count($versions) }}</div>
            </div>
            <div style="background: var(--ag-surface); color: var(--ag-text); padding: 20px; border-radius: 16px; text-align: center; min-width: 150px;">
                <div style="font-size: 12px; color: var(--ag-subtle); margin-bottom: 4px; font-weight: 600;">SERVICE NAME</div>
                <div style="font-size: 16px; font-weight: bold;">{{ $serviceName }}</div>
            </div>
        </div>
    </div>

    @if($versions->isEmpty())
        <div class="ag-card" style="padding: 60px; text-align: center;">
            <i class="fas fa-folder-open" style="font-size: 48px; color: #e6e9ee; margin-bottom: 16px;"></i>
            <p style="color: var(--ag-muted); font-size: 16px;">No versions found for this service</p>
        </div>
    @else
        @php
            // Versions are newest first. The newest version of each file on each system is the active one;
            // older uploads are kept as history.
            $seenFiles = [];
        @endphp
        <div class="ag-card" style="padding: 0; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px; min-width: 760px;">
                <thead>
                    <tr style="text-align: left; color: var(--ag-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--ag-line);">
                        <th style="padding: 14px 20px;">Version</th>
                        <th style="padding: 14px 12px;">Status</th>
                        <th style="padding: 14px 12px;">File</th>
                        <th style="padding: 14px 12px;">System</th>
                        <th style="padding: 14px 12px;">Uploaded</th>
                        <th style="padding: 14px 12px;">Hash</th>
                        <th style="padding: 14px 20px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($versions as $version)
                        @php
                            $fileKey = ($version->system_register_id ?? 0) . '|' . $version->file_name;
                            $isActive = !isset($seenFiles[$fileKey]);
                            $seenFiles[$fileKey] = true;
                        @endphp
                        <tr style="border-bottom: 1px solid var(--ag-line); {{ $isActive ? 'background: rgba(31, 168, 116, 0.06);' : '' }}">
                            <td style="padding: 14px 20px; white-space: nowrap;">
                                <i class="fas fa-code-branch" style="color: {{ $isActive ? '#1fa874' : 'var(--ag-subtle)' }}; margin-right: 6px;"></i>
                                <strong style="color: var(--ag-text);">{{ $version->display_version ?? $version->version ?? 'N/A' }}</strong>
                                <div style="font-size: 11px; color: var(--ag-subtle); margin-top: 2px;">#{{ $version->id }}</div>
                            </td>
                            <td style="padding: 14px 12px;">
                                @if($isActive)
                                    <span style="background: #1fa874; color: #fff; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600;">Active</span>
                                @else
                                    <span style="background: var(--ag-surface); color: var(--ag-muted); padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600;">Previous</span>
                                @endif
                            </td>
                            <td style="padding: 14px 12px; color: var(--ag-text); word-break: break-all;">{{ $version->file_name ?? 'N/A' }}</td>
                            <td style="padding: 14px 12px; color: var(--ag-muted);">{{ $version->system_name ?? '-' }}</td>
                            <td style="padding: 14px 12px; color: var(--ag-muted); white-space: nowrap;">{{ \App\Support\UserPreferences::datetime($version->created_at) }}</td>
                            <td style="padding: 14px 12px; white-space: nowrap;">
                                @if($version->validation_hash)
                                    <code id="version-hash-value-{{ $version->id }}" title="{{ $version->validation_hash }}" style="font-size: 12px; color: var(--ag-muted);">{{ \Illuminate\Support\Str::limit($version->validation_hash, 10, '…') }}</code>
                                    <span style="display: none;" id="version-hash-full-{{ $version->id }}">{{ $version->validation_hash }}</span>
                                    <button type="button" class="ag-btn ag-btn--ghost" onclick="copyHashById('version-hash-full-{{ $version->id }}', this)" style="padding: 2px 8px; font-size: 11px; margin-left: 4px;" title="Copy hash">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                @else
                                    <span style="color: var(--ag-subtle);">-</span>
                                @endif
                            </td>
                            <td style="padding: 14px 20px; text-align: right; white-space: nowrap;">
                                <a class="ag-btn ag-btn--accent" href="{{ route('configuration-backups.view', ['id' => $version->id]) }}" style="text-decoration: none; padding: 6px 12px; font-size: 12px;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a class="ag-btn ag-btn--ghost" href="{{ route('configuration-backups.download', ['id' => $version->id]) }}" style="text-decoration: none; padding: 6px 12px; font-size: 12px;">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
