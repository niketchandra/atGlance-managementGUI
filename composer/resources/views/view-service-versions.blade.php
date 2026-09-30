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
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 24px;">
            @foreach($versions as $version)
                @php
                    $isActive = strtolower($version->status) === 'active';
                @endphp
                <div class="ag-card ag-item-card" style="padding: 0; overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                    
                    <!-- Version Header -->
                    <div class="ag-banner" style="border-radius: 0; padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: rgba(255,255,255,0.25); border-radius: 16px; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-file-code" style="font-size: 20px; color: white;"></i>
                                </div>
                                <div>
                                    <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 500;">VERSION</div>
                                    <div style="font-size: 20px; color: white; font-weight: bold;">{{ $version->display_version ?? $version->version ?? 'N/A' }}</div>
                                </div>
                            </div>
                            <div style="background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 20px; font-size: 11px; color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                {{ ucfirst($version->status) }}
                            </div>
                        </div>
                    </div>

                    <!-- Version Body -->
                    <div style="padding: 20px;">
                        <!-- File Name -->
                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-file" style="margin-right: 4px;"></i> File Name
                            </div>
                            <div style="font-size: 14px; color: var(--ag-text); font-weight: 600; word-break: break-all;">
                                {{ $version->file_name ?? 'N/A' }}
                            </div>
                        </div>

                        <!-- Config ID -->
                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 11px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-hashtag" style="margin-right: 4px;"></i> Config ID
                            </div>
                            <div style="font-size: 14px; color: var(--ag-subtle); font-weight: 500;">
                                #{{ $version->id }}
                            </div>
                        </div>

                        <!-- Validation Hash -->
                        @if($version->validation_hash)
                            <div class="ag-card" style="margin-bottom: 16px; padding: 10px;">
                                <div style="font-size: 11px; color: var(--ag-teal); font-weight: 600; margin-bottom: 6px;">Validation Hash</div>
                                <a href="javascript:void(0)" id="version-hash-link-{{ $version->id }}" onclick="toggleHash('version-hash-content-{{ $version->id }}', this)" style="font-size: 12px; color: var(--ag-teal); text-decoration: underline; cursor: pointer; font-weight: 600;">
                                    Show hash
                                </a>
                                <div class="ag-card" id="version-hash-content-{{ $version->id }}" style="display: none; margin-top: 8px; padding: 8px; font-size: 10px; color: var(--ag-text); word-break: break-all; line-height: 1.5; position: relative; padding-right: 70px;">
                                    <span id="version-hash-value-{{ $version->id }}">{{ $version->validation_hash }}</span>
                                    <button class="ag-btn" onclick="copyHashById('version-hash-value-{{ $version->id }}', this)" style="position: absolute; top: 6px; right: 6px; transition: background 0.2s;">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        @endif

                        <!-- Created & Updated -->
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

                        <!-- Action Buttons -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 16px; border-top: 1px solid var(--ag-line);">
                            <button class="ag-btn ag-btn--accent" onclick="window.location.href='{{ route('configuration-backups.view', ['id' => $version->id]) }}'" 
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
