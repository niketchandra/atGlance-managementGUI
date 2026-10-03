@extends('app')

@section('title', 'Edit System - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    @if($errors->any())
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-danger-soft); color: var(--ag-danger); margin-bottom: 16px; border: 1px solid #f9d6d6;">
            <strong>Unable to save:</strong>
            <ul style="margin: 8px 0 0 18px; padding: 0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
        <div>
            <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">System Info</h1>
            <p style="color: var(--ag-muted); font-size: 14px;">Edit details for system #{{ $system->id }}.</p>
        </div>
        <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered') }}" style="text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Systems
        </a>
    </div>

    <div style="background: var(--ag-card); border-radius: 16px; box-shadow: var(--ag-shadow); overflow: hidden;">
        <div class="ag-banner" style="border-radius: 0; padding: 20px; color: white; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
            <div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.8);">SYSTEM NAME</div>
                <div style="font-size: 18px; font-weight: 700;">{{ $system->system_name }}</div>
                <div style="margin-top: 8px; font-size: 11px; color: rgba(255,255,255,0.8);">VALIDATION HASH</div>
                <div style="font-size: 12px; word-break: break-all; color: #f3f4f6; max-width: 640px;">
                    {{ $system->validation_hash ?: 'N/A' }}
                </div>
            </div>
            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                <div style="background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 20px; font-size: 11px; color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                    {{ ucfirst($system->status) }}
                </div>
                @if($isAdmin)
                    <form method="POST" action="{{ route('systems-registered.delete', ['systemId' => $system->id]) }}" style="margin: 0;">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                onclick="return confirm('Delete this registered system? This will remove its services as well.');"
                                style="background: #b33b3b; color: white; border: none; border-radius: 12px; padding: 8px 12px; font-size: 12px; font-weight: 600; cursor: pointer;">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('systems-registered.update', ['systemId' => $system->id]) }}" style="padding: 24px;">
            @csrf
            @method('PUT')

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="ag-label">Workspace</label>
                    <select class="ag-select" name="workspace_id" style="width: 100%;">
                        <option value="0" {{ (string) old('workspace_id', $system->workspace_id ?? 0) === '0' ? 'selected' : '' }}>Not assigned</option>
                        @foreach($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" {{ (string) old('workspace_id', $system->workspace_id) === (string) $workspace->id ? 'selected' : '' }}>
                                {{ $workspace->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="ag-label">Public Facing</label>
                    <select class="ag-select" name="public_facing" style="width: 100%;">
                        <option value="0" {{ (string) old('public_facing', (int) ($system->public_facing ?? 0)) === '0' ? 'selected' : '' }}>No</option>
                        <option value="1" {{ (string) old('public_facing', (int) ($system->public_facing ?? 0)) === '1' ? 'selected' : '' }}>Yes</option>
                    </select>
                </div>

                <div>
                    <label class="ag-label">Public IP</label>
                    <input class="ag-input" type="text" name="public_ip" value="{{ old('public_ip', $system->public_ip) }}" placeholder="Optional public IP" style="width: 100%;">
                </div>

                <div>
                    <label class="ag-label">Distro</label>
                    <input class="ag-input" type="text" name="distro" value="{{ old('distro', $system->distro) }}" placeholder="Ubuntu, Debian, RHEL..." style="width: 100%;">
                </div>

                <div>
                    <label class="ag-label">OS Version</label>
                    <input class="ag-input" type="text" name="version" value="{{ old('version', $system->version) }}" placeholder="22.04, 9, etc." style="width: 100%;">
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="ag-label">Description</label>
                <textarea class="ag-textarea" name="description" rows="4" style="width: 100%;">{{ old('description', $system->description) }}</textarea>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="ag-label">Tags</label>
                <textarea class="ag-textarea" id="systemTags" name="tags" rows="3" style="width: 100%;" placeholder="Comma-separated tags">{{ old('tags', $system->tags) }}</textarea>
                @if(!empty($tagCatalogue))
                    <div style="margin-top: 8px; font-size: 12px; color: var(--ag-muted);">Workspace tags (click to add):</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
                        @foreach($tagCatalogue as $catalogueTag)
                            <button type="button" class="ag-btn ag-btn--ghost" data-add-tag="{{ $catalogueTag }}" style="padding: 3px 10px; font-size: 12px; border-radius: 999px;">+ {{ $catalogueTag }}</button>
                        @endforeach
                    </div>
                    <script>
                        document.querySelectorAll('[data-add-tag]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                const field = document.getElementById('systemTags');
                                const tags = field.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
                                const tag = button.dataset.addTag;
                                if (!tags.some(function (t) { return t.toLowerCase() === tag.toLowerCase(); })) {
                                    tags.push(tag);
                                }
                                field.value = tags.join(', ');
                            });
                        });
                    </script>
                @endif
            </div>

            @if($isAdmin)
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px; padding-top: 8px; border-top: 1px solid var(--ag-line);">
                    <div>
                        <label class="ag-label">System Status</label>
                        <select class="ag-select" name="status" style="width: 100%;">
                            <option value="active" {{ old('status', $system->status) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status', $system->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="ag-label">Lock System Details</label>
                        <select class="ag-select" name="is_locked" style="width: 100%;">
                            <option value="0" {{ (string) old('is_locked', (int) ($system->is_locked ?? 0)) === '0' ? 'selected' : '' }}>Unlocked</option>
                            <option value="1" {{ (string) old('is_locked', (int) ($system->is_locked ?? 0)) === '1' ? 'selected' : '' }}>Locked</option>
                        </select>
                    </div>
                </div>

            @endif

            <div style="display: flex; gap: 12px;">
                <button class="ag-btn ag-btn--accent" type="submit">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a class="ag-btn ag-btn--ghost" href="{{ route('systems-registered') }}" style="text-decoration: none;">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
