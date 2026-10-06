@extends('app')

@section('title', 'Workspace Details - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Workspace: {{ $workspace->name }}</h1>
        <a href="{{ $isSuperAdmin ? route('enterprise.console') : route('admin.workspaces') }}" style="text-decoration: none; color: var(--ag-text);">&larr; Back</a>
    </div>

    @if(session('success'))
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-success-soft); color: var(--ag-success); margin-bottom: 16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-danger-soft); color: var(--ag-danger); margin-bottom: 16px;">
            <ul style="margin-left: 16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $activeTab = request('tab', 'general');
        $workspaceTabs = [
            'general' => ['General', 'fa-circle-info'],
            'members' => ['Members', 'fa-users'],
            'tags' => ['Workspace Tags', 'fa-tags'],
            'vulnerability-checks' => ['Vulnerability Checks', 'fa-shield-alt'],
            'backups' => ['Backups', 'fa-clock-rotate-left'],
            'notifications' => ['Notifications', 'fa-bell'],
            'activity' => ['Recent Activity', 'fa-clock'],
        ];
    @endphp

    <div class="ag-side-layout">
    <nav class="ag-tabs ag-tabs--vertical" aria-label="Workspace sections">
        @foreach($workspaceTabs as $tabKey => [$tabLabel, $tabIcon])
            <button type="button" class="ag-tab workspace-tab-btn {{ $activeTab === $tabKey ? 'active' : '' }}" data-tab="{{ $tabKey }}"><i class="fas {{ $tabIcon }}"></i> {{ $tabLabel }}</button>
        @endforeach
    </nav>
    <div class="ag-side-content">

    <div id="tab-general" class="workspace-tab-content" style="display:{{ $activeTab === 'general' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-general')
    </div>

    <div id="tab-members" class="workspace-tab-content" style="display:{{ $activeTab === 'members' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-members')
    </div>

    <div id="tab-tags" class="workspace-tab-content" style="display:{{ $activeTab === 'tags' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-tags')
    </div>

    <div id="tab-vulnerability-checks" class="workspace-tab-content" style="display:{{ $activeTab === 'vulnerability-checks' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-ai')
    </div>

    <div id="tab-backups" class="workspace-tab-content" style="display:{{ $activeTab === 'backups' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-backups')
    </div>

    <div id="tab-notifications" class="workspace-tab-content" style="display:{{ $activeTab === 'notifications' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-notifications')
    </div>

    <div id="tab-activity" class="workspace-tab-content" style="display:{{ $activeTab === 'activity' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-activity')
    </div>

    </div>
    </div>
</div>

<style>
    .ws-config-fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
    .ws-config-fieldset--locked { opacity: 0.6; }
    .ws-config-fieldset--locked a { pointer-events: none; }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const workspaceTabButtons = document.querySelectorAll('.workspace-tab-btn');
        workspaceTabButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const tabName = button.dataset.tab;
                document.querySelectorAll('.workspace-tab-content').forEach(function (panel) {
                    panel.style.display = panel.id === 'tab-' + tabName ? 'block' : 'none';
                });
                workspaceTabButtons.forEach(function (other) {
                    other.classList.toggle('active', other === button);
                });
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabName);
                // Recent Activity is built on the server only when its tab is requested.
                if (document.querySelector('#tab-' + tabName + ' [data-activity-lazy]')) {
                    window.location.assign(url.toString());
                    return;
                }
                window.history.replaceState({}, '', url.toString());
            });
        });

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
                    empty.style.color = '#8a9099';
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
                        item.style.background = '#f4f6f8';
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
