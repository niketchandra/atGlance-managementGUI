{{-- General tab: name and description; status and delete are super-admin only. --}}
<div class="ag-card" style="padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin: 0;">General</h2>
        @if($canEditWorkspaceMetadata)
            <form method="POST" action="{{ route($workspaceDeleteRouteName, $workspace->id) }}" style="margin: 0;">
                @csrf
                @method('DELETE')
                <button class="ag-btn ag-btn--danger" type="submit" onclick="return confirm('Delete this workspace? This action cannot be undone.');">Delete Workspace</button>
            </form>
        @endif
    </div>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">Name and description of this workspace.</p>

    @unless($permissions['general'])
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 12px;"><i class="fas fa-lock"></i> Read only. You do not have access to change General settings.</p>
    @endunless
    <form method="POST" action="{{ route('admin.workspaces.settings.general', $workspace->id) }}">
        <fieldset {{ $permissions['general'] ? '' : 'disabled' }} class="ws-config-fieldset {{ $permissions['general'] ? '' : 'ws-config-fieldset--locked' }}">
        @csrf
        @method('PUT')

        <div style="margin-bottom: 14px;">
            <label class="ag-label" for="wsName">Workspace Name</label>
            <input class="ag-input" id="wsName" type="text" name="name" value="{{ old('name', $workspace->name) }}" required maxlength="255" style="width: 100%;">
        </div>

        <div style="margin-bottom: 14px;">
            <label class="ag-label" for="wsDescription">Description</label>
            <textarea class="ag-textarea" id="wsDescription" name="description" rows="3" maxlength="512" style="width: 100%;">{{ old('description', $workspace->description) }}</textarea>
        </div>

        @if($canEditWorkspaceMetadata)
            <div style="margin-bottom: 16px;">
                <label class="ag-label" for="wsStatus">Status</label>
                <select class="ag-select" id="wsStatus" name="status" required style="width: 100%;">
                    <option value="active" {{ old('status', $workspace->status) === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $workspace->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
        @endif

        <button class="ag-btn" type="submit">Save Changes</button>
        </fieldset>
    </form>
</div>
