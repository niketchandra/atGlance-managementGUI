@extends('app')

@section('title', 'Service Versions - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h1 style="font-size: 28px; font-weight: bold; color: #333; margin-bottom: 8px;">
                    <i class="fas fa-code-branch"></i> {{ $serviceName }} - All Versions
                </h1>
                <p style="color: #666; font-size: 14px;">View and download all configuration versions for this service</p>
            </div>
            <a href="{{ route('systems-registered.services', ['systemId' => $systemId]) }}" style="background: #667eea; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;">
                <i class="fas fa-arrow-left"></i> Back to Services
            </a>
        </div>
    </div>

    <div style="background: white; padding: 24px; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 14px; color: #999; margin-bottom: 4px;">Total Versions</div>
                <div style="font-size: 32px; font-weight: bold; color: #667eea;">{{ count($versions) }}</div>
            </div>
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 12px; text-align: center; min-width: 150px;">
                <div style="font-size: 12px; opacity: 0.9; margin-bottom: 4px;">SERVICE NAME</div>
                <div style="font-size: 16px; font-weight: bold;">{{ $serviceName }}</div>
            </div>
        </div>
    </div>

    @if($versions->isEmpty())
        <div style="background: white; padding: 60px; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center;">
            <i class="fas fa-folder-open" style="font-size: 48px; color: #ddd; margin-bottom: 16px;"></i>
            <p style="color: #999; font-size: 16px;">No versions found for this service</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 24px;">
            @foreach($versions as $version)
                @php
                    $isActive = strtolower($version->status) === 'active';
                @endphp
                <div style="background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%); border-radius: 14px; border: 2px solid {{ $isActive ? '#4caf50' : '#f44336' }}; box-shadow: 0 8px 20px rgba(0,0,0,0.08); overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease;" 
                     onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.15)';" 
                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)';">
                    
                    <!-- Version Header -->
                    <div style="background: {{ $isActive ? 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)' : 'linear-gradient(135deg, #eb3349 0%, #f45c43 100%)' }}; padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: rgba(255,255,255,0.25); border-radius: 10px; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center;">
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

                    <!-- Version Body -->
                    <div style="padding: 20px;">
                        <!-- File Name -->
                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-file" style="margin-right: 4px;"></i> File Name
                            </div>
                            <div style="font-size: 14px; color: #333; font-weight: 600; word-break: break-all;">
                                {{ $version->file_name ?? 'N/A' }}
                            </div>
                        </div>

                        <!-- Config ID -->
                        <div style="margin-bottom: 16px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-hashtag" style="margin-right: 4px;"></i> Config ID
                            </div>
                            <div style="font-size: 14px; color: #555; font-weight: 500;">
                                #{{ $version->id }}
                            </div>
                        </div>

                        <!-- Validation Hash -->
                        @if($version->validation_hash)
                            <div style="margin-bottom: 16px; padding: 10px; background: #fff3e0; border-radius: 8px; border-left: 3px solid #ff9800;">
                                <div style="font-size: 11px; color: #e65100; font-weight: 600; margin-bottom: 6px;">Validation Hash</div>
                                <a href="javascript:void(0)" id="version-hash-link-{{ $version->id }}" onclick="toggleHash('version-hash-content-{{ $version->id }}', this)" style="font-size: 12px; color: #f57c00; text-decoration: underline; cursor: pointer; font-weight: 600;">
                                    Show hash
                                </a>
                                <div id="version-hash-content-{{ $version->id }}" style="display: none; margin-top: 8px; background: white; padding: 8px; border-radius: 6px; border: 1px solid #ffe0b2; font-family: 'Courier New', monospace; font-size: 10px; color: #333; word-break: break-all; line-height: 1.5; position: relative; padding-right: 70px;">
                                    <span id="version-hash-value-{{ $version->id }}">{{ $version->validation_hash }}</span>
                                    <button onclick="copyHashById('version-hash-value-{{ $version->id }}', this)" style="position: absolute; top: 6px; right: 6px; background: #ff9800; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 10px; cursor: pointer; font-weight: 600; transition: background 0.2s;" onmouseover="this.style.background='#f57c00'" onmouseout="this.style.background='#ff9800'">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        @endif

                        <!-- Created & Updated -->
                        <div style="margin-bottom: 18px;">
                            <div style="font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fas fa-clock" style="margin-right: 4px;"></i> Timeline
                            </div>
                            <div style="font-size: 12px; color: #666; line-height: 1.6;">
                                <div><strong>Created:</strong> {{ \Carbon\Carbon::parse($version->created_at)->format('M d, Y H:i A') }}</div>
                                @if($version->updated_at && $version->updated_at != $version->created_at)
                                    <div><strong>Updated:</strong> {{ \Carbon\Carbon::parse($version->updated_at)->format('M d, Y H:i A') }}</div>
                                @endif
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 16px; border-top: 1px solid #f0f0f0;">
                            <button onclick="window.location.href='{{ route('configuration-backups.view', ['id' => $version->id]) }}'" 
                                    style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 10px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 6px;"
                                    onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.4)';" 
                                    onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none';">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button onclick="window.location.href='{{ route('configuration-backups.download', ['id' => $version->id]) }}'" 
                                    style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; padding: 10px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 6px;"
                                    onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 12px rgba(245, 87, 108, 0.4)';" 
                                    onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none';">
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
