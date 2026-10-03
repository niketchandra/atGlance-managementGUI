{{-- Access checkboxes for a User-role workspace admin. Params: $chosen (permission => bool), $idPrefix, $legend. --}}
<fieldset class="ws-perms">
    <legend>{{ $legend ?? 'Can change' }}</legend>
    @foreach(\App\Models\Workspace::PERMISSIONS as $permissionKey => $permission)
        <label for="perm-{{ $idPrefix }}-{{ $permissionKey }}">
            <input type="checkbox" id="perm-{{ $idPrefix }}-{{ $permissionKey }}" name="permissions[]" value="{{ $permissionKey }}" {{ ($chosen[$permissionKey] ?? false) ? 'checked' : '' }}>
            {{ $permission['label'] }}
        </label>
    @endforeach
    <span style="font-size: 11px; color: var(--ag-muted);">Admins with the Admin role always have full access. Workspace tags can be changed by every workspace admin.</span>
</fieldset>
