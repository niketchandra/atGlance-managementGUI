@extends('app')

@section('title', 'Edit System - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    @if($errors->any())
        <div style="padding:12px; border-radius:8px; background:#fee2e2; color:#991b1b; margin-bottom:16px; border:1px solid #fecaca;">
            <strong>Unable to save:</strong>
            <ul style="margin:8px 0 0 18px; padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="margin-bottom: 24px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <div>
            <h1 style="font-size: 28px; font-weight: bold; color: #333; margin-bottom: 8px;">System Info</h1>
            <p style="color: #666; font-size: 14px;">Edit details for system #{{ $system->id }}.</p>
        </div>
        <a href="{{ route('systems-registered') }}" style="background:#111827; color:white; padding:10px 20px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; transition:background 0.2s ease;" onmouseover="this.style.background='#1f2937'" onmouseout="this.style.background='#111827'">
            <i class="fas fa-arrow-left"></i> Back to Systems
        </a>
    </div>

    <div style="background: white; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); overflow: hidden;">
        <div style="background: linear-gradient(135deg, #1f2937 0%, #374151 100%); padding: 20px; color: white; display:flex; align-items:center; justify-content:space-between; gap:12px;">
            <div>
                <div style="font-size:11px; color: rgba(255,255,255,0.8);">SYSTEM NAME</div>
                <div style="font-size:18px; font-weight:700;">{{ $system->system_name }}</div>
                <div style="margin-top:8px; font-size:11px; color: rgba(255,255,255,0.8);">VALIDATION HASH</div>
                <div style="font-size:12px; font-family:'Courier New', monospace; word-break:break-all; color:#f3f4f6; max-width:640px;">
                    {{ $system->validation_hash ?: 'N/A' }}
                </div>
            </div>
            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                <div style="background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 20px; font-size: 11px; color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                    {{ ucfirst($system->status) }}
                </div>
                @if($isAdmin)
                    <form method="POST" action="{{ route('systems-registered.delete', ['systemId' => $system->id]) }}" style="margin:0;">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                onclick="return confirm('Delete this registered system? This will remove its services as well.');"
                                style="background:#991b1b; color:white; border:none; border-radius:8px; padding:8px 12px; font-size:12px; font-weight:600; cursor:pointer;">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('systems-registered.update', ['systemId' => $system->id]) }}" style="padding:24px;">
            @csrf
            @method('PUT')

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px; margin-bottom:16px;">
                <div>
                    <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Workspace</label>
                    <select name="workspace_id" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:white;">
                        <option value="0" {{ (string) old('workspace_id', $system->workspace_id ?? 0) === '0' ? 'selected' : '' }}>Not assigned</option>
                        @foreach($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" {{ (string) old('workspace_id', $system->workspace_id) === (string) $workspace->id ? 'selected' : '' }}>
                                {{ $workspace->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Public Facing</label>
                    <select name="public_facing" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:white;">
                        <option value="0" {{ (string) old('public_facing', (int) ($system->public_facing ?? 0)) === '0' ? 'selected' : '' }}>No</option>
                        <option value="1" {{ (string) old('public_facing', (int) ($system->public_facing ?? 0)) === '1' ? 'selected' : '' }}>Yes</option>
                    </select>
                </div>

                <div>
                    <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Public IP</label>
                    <input type="text" name="public_ip" value="{{ old('public_ip', $system->public_ip) }}" placeholder="Optional public IP" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">
                </div>

                <div>
                    <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Distro</label>
                    <input type="text" name="distro" value="{{ old('distro', $system->distro) }}" placeholder="Ubuntu, Debian, RHEL..." style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">
                </div>

                <div>
                    <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">OS Version</label>
                    <input type="text" name="version" value="{{ old('version', $system->version) }}" placeholder="22.04, 9, etc." style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Description</label>
                <textarea name="description" rows="4" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">{{ old('description', $system->description) }}</textarea>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Tags</label>
                <textarea name="tags" rows="3" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;" placeholder="Comma-separated tags">{{ old('tags', $system->tags) }}</textarea>
            </div>

            @if($isAdmin)
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px; margin-bottom:20px; padding-top:8px; border-top:1px solid #e5e7eb;">
                    <div>
                        <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">System Status</label>
                        <select name="status" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:white;">
                            <option value="active" {{ old('status', $system->status) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status', $system->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px; color:#6b7280; font-weight:600; display:block; margin-bottom:6px;">Lock System Details</label>
                        <select name="is_locked" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:white;">
                            <option value="0" {{ (string) old('is_locked', (int) ($system->is_locked ?? 0)) === '0' ? 'selected' : '' }}>Unlocked</option>
                            <option value="1" {{ (string) old('is_locked', (int) ($system->is_locked ?? 0)) === '1' ? 'selected' : '' }}>Locked</option>
                        </select>
                    </div>
                </div>

            @endif

            <div style="display:flex; gap:12px;">
                <button type="submit" style="background:#111827; color:white; border:none; padding:12px 24px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:background 0.2s ease;" onmouseover="this.style.background='#1f2937'" onmouseout="this.style.background='#111827'">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="{{ route('systems-registered') }}" style="background:#f3f4f6; border:1px solid #d1d5db; color:#111827; padding:12px 24px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; transition:background 0.2s ease;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
