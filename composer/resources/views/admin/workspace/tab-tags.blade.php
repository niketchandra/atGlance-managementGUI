{{-- Workspace Tags tab: key/value tags (env = prod). Any workspace admin can change them. --}}
@php($tagRows = old('tags', $settings['tags']))
<div class="ag-card" style="padding: 24px;">
    <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Workspace Tags</h2>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">
        Key/value tags for this workspace, for example <code>env = prod</code> or <code>team = platform</code>.
        They show on the workspace list, and systems in this workspace can pick them (as <code>key=value</code>) when you edit a system.
    </p>

    <form method="POST" action="{{ route('admin.workspaces.settings.tags', $workspace->id) }}">
        @csrf
        @method('PUT')

        <div class="ws-kv-head">
            <span>Key</span>
            <span>Value</span>
            <span></span>
        </div>
        <div id="wsTagRows">
            @foreach($tagRows as $index => $tag)
                <div class="ws-kv-row">
                    <input class="ag-input" type="text" name="tags[{{ $index }}][key]" value="{{ $tag['key'] ?? '' }}" maxlength="{{ \App\Support\WorkspaceSettings::MAX_TAG_KEY_LENGTH }}" placeholder="env" aria-label="Tag key">
                    <input class="ag-input" type="text" name="tags[{{ $index }}][value]" value="{{ $tag['value'] ?? '' }}" maxlength="{{ \App\Support\WorkspaceSettings::MAX_TAG_VALUE_LENGTH }}" placeholder="prod" aria-label="Tag value">
                    <button type="button" class="ag-btn ag-btn--ghost" data-remove-tag aria-label="Remove tag"><i class="fas fa-times"></i></button>
                </div>
            @endforeach
        </div>
        <p id="wsTagEmpty" style="font-size: 13px; color: var(--ag-muted); margin: 8px 0; {{ count($tagRows) ? 'display: none;' : '' }}">No tags yet.</p>

        <div style="display: flex; gap: 10px; margin-top: 12px;">
            <button type="button" class="ag-btn ag-btn--ghost" id="wsTagAdd"><i class="fas fa-plus"></i> Add tag</button>
            <button class="ag-btn" type="submit">Save Tags</button>
        </div>
    </form>
</div>

<template id="wsTagTemplate">
    <div class="ws-kv-row">
        <input class="ag-input" type="text" data-field="key" maxlength="{{ \App\Support\WorkspaceSettings::MAX_TAG_KEY_LENGTH }}" placeholder="env" aria-label="Tag key">
        <input class="ag-input" type="text" data-field="value" maxlength="{{ \App\Support\WorkspaceSettings::MAX_TAG_VALUE_LENGTH }}" placeholder="prod" aria-label="Tag value">
        <button type="button" class="ag-btn ag-btn--ghost" data-remove-tag aria-label="Remove tag"><i class="fas fa-times"></i></button>
    </div>
</template>

<style>
    .ws-kv-head, .ws-kv-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr) auto; gap: 10px; align-items: center; }
    .ws-kv-head { font-size: 12px; color: var(--ag-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
    .ws-kv-row { margin-bottom: 8px; }
    .ws-kv-row .ag-input { width: 100%; }
</style>

<script>
    (function () {
        const rows = document.getElementById('wsTagRows');
        const empty = document.getElementById('wsTagEmpty');
        const template = document.getElementById('wsTagTemplate');
        let next = rows.children.length;

        function refresh() {
            empty.style.display = rows.children.length ? 'none' : 'block';
        }

        document.getElementById('wsTagAdd').addEventListener('click', function () {
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelectorAll('[data-field]').forEach(function (input) {
                input.name = 'tags[' + next + '][' + input.dataset.field + ']';
            });
            next++;
            rows.appendChild(row);
            row.querySelector('input').focus();
            refresh();
        });

        rows.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-tag]');
            if (button) {
                button.closest('.ws-kv-row').remove();
                refresh();
            }
        });
    })();
</script>
