@extends('app')

@section('title', 'Workspace Details - AtGlance')

@section('dashboard-content')
<div style="padding:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:28px; color:#111827;">Workspace: {{ $workspace->name }}</h1>
        <a href="{{ $isSuperAdmin ? route('enterprise.console') : route('admin.workspaces') }}" style="text-decoration:none; color:#111827;">&larr; Back</a>
    </div>

    @if(session('success'))
        <div style="padding:12px; border-radius:8px; background:#dcfce7; color:#166534; margin-bottom:16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding:12px; border-radius:8px; background:#fee2e2; color:#991b1b; margin-bottom:16px;">
            <ul style="margin-left:16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px;">
        @if($canEditWorkspaceMetadata)
        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px;">
                <h2 style="font-size:18px; color:#111827; margin:0;">Edit Workspace</h2>
                <form method="POST" action="{{ route($workspaceDeleteRouteName, $workspace->id) }}" style="margin:0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" onclick="return confirm('Delete this workspace? This action cannot be undone.');" style="background:#dc2626; color:white; border:none; border-radius:8px; padding:8px 12px; font-weight:600; cursor:pointer;">Delete Workspace</button>
                </form>
            </div>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Update workspace name, description, and status.</p>

            <form method="POST" action="{{ route($workspaceUpdateRouteName, $workspace->id) }}">
                @csrf
                @method('PUT')
                
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Workspace Name</label>
                    <input type="text" name="name" value="{{ old('name', $workspace->name) }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Description</label>
                    <textarea name="description" rows="3" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">{{ old('description', $workspace->description) }}</textarea>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Status</label>
                    <select name="status" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:white;">
                        <option value="active" {{ old('status', $workspace->status) === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status', $workspace->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>

                <button type="submit" style="background:#111827; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Save Changes</button>
            </form>
        </div>
        @endif

        @if($canManageAdmins)
        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Add Admin to Workspace</h2>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Assign an existing admin to this workspace.</p>

            <form method="POST" action="{{ route('workspace.admins.add', $workspace->id) }}">
                @csrf
                
                <div style="margin-bottom:14px;">
                    <label for="adminSearchInput" style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Search Admin</label>
                    <input id="adminSearchInput" type="text" autocomplete="off" placeholder="Type name or email" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; margin-bottom:8px;">
                    <input id="adminIdInput" type="hidden" name="admin_id" required>

                    <div id="adminSelectedTag" style="display:none; margin-bottom:8px; font-size:12px; color:#1f2937; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:999px; padding:6px 10px; width:fit-content;"></div>
                    <div id="adminResults" style="border:1px solid #d1d5db; border-radius:8px; max-height:180px; overflow:auto; background:white; display:none;"></div>
                    <p style="margin-top:6px; font-size:12px; color:#6b7280;">Start typing to search admins quickly by name or email.</p>
                </div>

                <button type="submit" style="background:#111827; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Add Admin</button>
            </form>
        </div>
        @endif

        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Add User to Workspace</h2>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Assign a regular user to this workspace.</p>

            <form method="POST" action="{{ route($workspaceAddUserRouteName, $workspace->id) }}">
                @csrf

                <div style="margin-bottom:14px;">
                    <label for="userSearchInput" style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Search User</label>
                    <input id="userSearchInput" type="text" autocomplete="off" placeholder="Type name or email" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; margin-bottom:8px;">
                    <input id="userIdInput" type="hidden" name="user_id" required>

                    <div id="userSelectedTag" style="display:none; margin-bottom:8px; font-size:12px; color:#1f2937; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:999px; padding:6px 10px; width:fit-content;"></div>
                    <div id="userResults" style="border:1px solid #d1d5db; border-radius:8px; max-height:180px; overflow:auto; background:white; display:none;"></div>
                    <p style="margin-top:6px; font-size:12px; color:#6b7280;">Start typing to search users quickly by name or email.</p>
                </div>

                <button type="submit" style="background:#111827; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Add User</button>
            </form>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
        <!-- Workspace Admins -->
        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Workspace Admins</h2>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Admins assigned to this workspace.</p>

            @if($admins->isEmpty())
                <div style="padding:12px; background:#f3f4f6; border-radius:8px; color:#6b7280; text-align:center; font-size:13px;">
                    No admins assigned yet.
                </div>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
                                <th style="text-align:left; padding:10px; font-size:12px; color:#6b7280;">Name</th>
                                <th style="text-align:left; padding:10px; font-size:12px; color:#6b7280;">Email</th>
                                <th style="text-align:left; padding:10px; font-size:12px; color:#6b7280;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($admins as $admin)
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:10px; font-size:13px;">{{ $admin->name }}</td>
                                    <td style="padding:10px; font-size:13px;">{{ $admin->email }}</td>
                                    <td style="padding:10px;">
                                        @if($canManageAdmins)
                                            <form method="POST" action="{{ route($workspaceRemoveUserRouteName, [$workspace->id, $admin->id]) }}" style="display:inline;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" onclick="return confirm('Remove this admin from workspace?')" style="background:#dc2626; color:white; border:none; border-radius:6px; padding:4px 8px; font-size:12px; cursor:pointer;">Remove</button>
                                            </form>
                                        @else
                                            <span style="font-size:12px; color:#6b7280;">Read only</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <!-- Workspace Users -->
        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Workspace Users</h2>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Regular users assigned to this workspace.</p>

            @if($regularUsers->isEmpty())
                <div style="padding:12px; background:#f3f4f6; border-radius:8px; color:#6b7280; text-align:center; font-size:13px;">
                    No users assigned yet.
                </div>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
                                <th style="text-align:left; padding:10px; font-size:12px; color:#6b7280;">Name</th>
                                <th style="text-align:left; padding:10px; font-size:12px; color:#6b7280;">Email</th>
                                <th style="text-align:left; padding:10px; font-size:12px; color:#6b7280;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($regularUsers as $user)
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:10px; font-size:13px;">{{ $user->name }}</td>
                                    <td style="padding:10px; font-size:13px;">{{ $user->email }}</td>
                                    <td style="padding:10px;">
                                        <form method="POST" action="{{ route($workspaceRemoveUserRouteName, [$workspace->id, $user->id]) }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" onclick="return confirm('Remove this user from workspace?')" style="background:#dc2626; color:white; border:none; border-radius:6px; padding:4px 8px; font-size:12px; cursor:pointer;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        function wireTypeahead(config) {
            const {
                inputId,
                hiddenId,
                resultsId,
                selectedTagId,
                records,
            } = config;

            const input = document.getElementById(inputId);
            const hidden = document.getElementById(hiddenId);
            const results = document.getElementById(resultsId);
            const selectedTag = document.getElementById(selectedTagId);

            if (!input || !hidden || !results || !selectedTag) {
                return;
            }

            const MAX_RESULTS = 50;

            function scoreRecord(record, term) {
                const display = record.display.toLowerCase();
                const name = record.name.toLowerCase();
                const email = record.email.toLowerCase();

                if (name.startsWith(term)) {
                    return 0;
                }

                if (email.startsWith(term)) {
                    return 1;
                }

                if (name.includes(term)) {
                    return 2;
                }

                if (email.includes(term)) {
                    return 3;
                }

                if (display.includes(term)) {
                    return 4;
                }

                return 999;
            }

            function clearSelection() {
                hidden.value = '';
                selectedTag.style.display = 'none';
                selectedTag.textContent = '';
            }

            function selectRecord(record) {
                hidden.value = String(record.id);
                selectedTag.textContent = 'Selected: ' + record.display;
                selectedTag.style.display = 'inline-block';
                input.value = record.display;
                results.style.display = 'none';
                results.innerHTML = '';
            }

            function renderResults(term) {
                if (term.length < 1) {
                    results.style.display = 'none';
                    results.innerHTML = '';
                    return;
                }

                const sorted = records
                    .map(function (record) {
                        return {
                            record: record,
                            score: scoreRecord(record, term),
                        };
                    })
                    .filter(function (entry) { return entry.score < 999; })
                    .sort(function (a, b) {
                        if (a.score !== b.score) {
                            return a.score - b.score;
                        }

                        return a.record.display.localeCompare(b.record.display);
                    })
                    .slice(0, MAX_RESULTS)
                    .map(function (entry) { return entry.record; });

                results.innerHTML = '';

                if (sorted.length === 0) {
                    const empty = document.createElement('div');
                    empty.textContent = 'No matches found';
                    empty.style.padding = '10px';
                    empty.style.fontSize = '12px';
                    empty.style.color = '#6b7280';
                    results.appendChild(empty);
                    results.style.display = 'block';
                    return;
                }

                sorted.forEach(function (record) {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.textContent = record.display;
                    item.style.display = 'block';
                    item.style.width = '100%';
                    item.style.textAlign = 'left';
                    item.style.padding = '10px';
                    item.style.border = 'none';
                    item.style.background = 'white';
                    item.style.cursor = 'pointer';
                    item.style.fontSize = '13px';
                    item.style.borderBottom = '1px solid #f3f4f6';

                    item.addEventListener('mouseenter', function () {
                        item.style.background = '#f9fafb';
                    });

                    item.addEventListener('mouseleave', function () {
                        item.style.background = 'white';
                    });

                    item.addEventListener('click', function () {
                        selectRecord(record);
                    });

                    results.appendChild(item);
                });

                results.style.display = 'block';
            }

            input.addEventListener('input', function () {
                const term = input.value.trim().toLowerCase();

                if (!hidden.value || input.value !== selectedTag.textContent.replace('Selected: ', '')) {
                    clearSelection();
                }

                renderResults(term);
            });

            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                const firstButton = results.querySelector('button');
                if (!firstButton) {
                    return;
                }

                event.preventDefault();
                firstButton.click();
            });

            document.addEventListener('click', function (event) {
                if (!results.contains(event.target) && event.target !== input) {
                    results.style.display = 'none';
                }
            });
        }

        wireTypeahead({
            inputId: 'adminSearchInput',
            hiddenId: 'adminIdInput',
            resultsId: 'adminResults',
            selectedTagId: 'adminSelectedTag',
            records: [
                @foreach($allAdmins as $admin)
                    {
                        id: {{ (int) $admin->id }},
                        name: @json((string) $admin->name),
                        email: @json((string) $admin->email),
                        display: @json((string) $admin->name . ' (' . (string) $admin->email . ')'),
                    },
                @endforeach
            ],
        });

        wireTypeahead({
            inputId: 'userSearchInput',
            hiddenId: 'userIdInput',
            resultsId: 'userResults',
            selectedTagId: 'userSelectedTag',
            records: [
                @foreach($allRegularUsers as $workspaceUser)
                    {
                        id: {{ (int) $workspaceUser->id }},
                        name: @json((string) $workspaceUser->name),
                        email: @json((string) $workspaceUser->email),
                        display: @json((string) $workspaceUser->name . ' (' . (string) $workspaceUser->email . ')'),
                    },
                @endforeach
            ],
        });
    });
</script>
@endsection
