@extends('app')

@section('title', 'View Configuration - ' . $brandName)

@section('dashboard-content')
@php
    // Saved results stay readable even after AI Connect is turned off.
    $aiShowPanel = ($aiEnabled && $config->data) || $aiHistory->isNotEmpty();
@endphp
<style>
    .ag-config-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; align-items: start; }
    @media (min-width: 1100px) { .ag-config-layout.has-history { grid-template-columns: minmax(0, 1fr) 320px; } }
    .ag-ai-history-item { display: block; width: 100%; text-align: left; border: 1px solid var(--ag-line); border-radius: 10px; padding: 10px 12px; background: transparent; color: var(--ag-text); cursor: pointer; }
    .ag-ai-history-item:hover, .ag-ai-history-item.is-active { border-color: var(--ag-text); background: var(--ag-surface); }
    .ag-ai-history-row { position: relative; }
    .ag-ai-history-row .ag-ai-history-item { padding-right: 40px; }
    .ag-ai-history-delete { position: absolute; right: 8px; bottom: 8px; border: 0; background: transparent; color: var(--ag-muted); cursor: pointer; padding: 4px 6px; border-radius: 6px; }
    .ag-ai-history-delete:hover { color: #c0392b; background: var(--ag-surface); }
</style>
<div style="padding: 40px;">
    <div style="margin-bottom: 30px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 8px;">Configuration Details</h1>
                <p style="color: var(--ag-muted); font-size: 14px;">Viewing: {{ $config->file_name }}</p>
            </div>
            <a class="ag-btn" href="{{ route('configuration-backups') }}" style="text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div class="ag-config-layout {{ $aiShowPanel ? 'has-history' : '' }}">
    <div style="background: var(--ag-card); border-radius: 16px; box-shadow: var(--ag-shadow); overflow: hidden;">
        <!-- File Info Header -->
        <div class="ag-banner" style="border-radius: 0; padding: 24px; color: white;">
            <h2 style="font-size: 20px; font-weight: 500; margin-bottom: 16px;">
                <i class="fas fa-file-code"></i> {{ $config->file_name }}
            </h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Config ID</div>
                    <div style="font-size: 15px; font-weight: 600;">#{{ $config->id }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Service Name</div>
                    <div style="font-size: 15px; font-weight: 600;">{{ $config->service_name ?? 'N/A' }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Status</div>
                    <div style="font-size: 15px; font-weight: 600;">{{ ucfirst($config->status) }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px;">Created At</div>
                    <div style="font-size: 15px; font-weight: 600;">{{ \App\Support\UserPreferences::date($config->created_at) }}</div>
                </div>
            </div>
        </div>

        <!-- Configuration Content -->
        <div style="padding: 24px;">
            <h3 style="font-size: 16px; font-weight: 500; color: var(--ag-text); margin-bottom: 16px;">
                <i class="fas fa-code"></i> Configuration Content
            </h3>
            @if($config->data)
                <pre style="background: var(--ag-surface); padding: 20px; border-radius: 12px; overflow-x: auto; font-size: 13px; line-height: 1.6; color: var(--ag-text); max-height: 600px; overflow-y: auto;">{{ $config->data }}</pre>
            @else
                <div class="ag-card" style="padding: 16px; color: var(--ag-teal);">
                    <i class="fas fa-exclamation-triangle"></i> No configuration data available for this file.
                </div>
            @endif
        </div>

        <!-- Action Buttons -->
        <div style="padding: 0 24px 24px;">
            <div style="display: flex; gap: 12px;">
                <a class="ag-btn" href="{{ route('configuration-backups.download', $config->id) }}" 
                   style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s ease, box-shadow 0.2s ease;">
                    <i class="fas fa-download"></i> Download Configuration
                </a>
                @if($config->validation_hash)
                    <button class="ag-btn ag-btn--ghost" onclick="alert('Validation Hash:\n{{ $config->validation_hash }}')"
                            style="display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s ease, box-shadow 0.2s ease;">
                        <i class="fas fa-fingerprint"></i> View Hash
                    </button>
                @endif
                @if($aiEnabled && $config->data)
                    <button type="button" id="aiValidateBtn" class="ag-btn ag-btn--accent"
                            data-url="{{ route('configuration-backups.ai-validate', $config->id) }}"
                            style="display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-robot"></i> <span>Validate with AI</span>
                    </button>
                @endif
            </div>
            @if($aiEnabled && $config->data)
                <p style="color: var(--ag-muted); font-size: 12px; margin-top: 10px;">
                    <i class="fas fa-info-circle"></i>
                    Validate with AI sends this file to {{ $aiProviderLabel }} ({{ $aiModel }}). Values that look like passwords, tokens or keys are masked first.
                </p>
            @endif
        </div>

        @if($aiShowPanel)
            <div id="aiValidateResult" style="display: none; padding: 0 24px 24px;">
                <div class="ag-card" style="padding: 20px;">
                    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 4px;">
                        <h3 style="font-size: 16px; font-weight: 500; color: var(--ag-text);"><i class="fas fa-robot"></i> AI Validation <span style="color: var(--ag-muted); font-size: 12px; font-weight: 400;">CIS, DISA STIG, NIST, Mozilla TLS, vendor docs</span></h3>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" id="aiShareBtn" class="ag-btn ag-btn--ghost ag-btn--sm" style="display: none;" title="Copy a link to this result. Only people who can open this file can view it.">
                                <i class="fas fa-share-alt"></i> <span>Share</span>
                            </button>
                            <span id="aiValidateStatus" class="ag-badge"></span>
                        </div>
                    </div>
                    <p id="aiValidateSaved" style="color: var(--ag-muted); font-size: 12px; margin-bottom: 12px;"></p>
                    <p id="aiValidateSummary" style="color: var(--ag-text); font-size: 14px; line-height: 1.6; white-space: pre-wrap;"></p>
                    <div id="aiValidateFindings" style="margin-top: 16px;"></div>
                    <div id="aiValidateDescription" style="margin-top: 20px;"></div>
                    <div id="aiValidateThreats" style="margin-top: 20px;"></div>
                    <p id="aiValidateMeta" style="color: var(--ag-muted); font-size: 12px; margin-top: 16px;"></p>
                </div>
            </div>
        @endif
    </div>

    @if($aiShowPanel)
        <aside class="ag-card" style="padding: 18px; position: sticky; top: 20px;">
            <h3 style="font-size: 15px; font-weight: 600; color: var(--ag-text); margin-bottom: 4px;"><i class="fas fa-history"></i> AI validation history</h3>
            <p style="color: var(--ag-muted); font-size: 12px; margin-bottom: 12px;">Every run is saved automatically. Open one to read it again without a new AI call.</p>
            <p id="aiHistoryEmpty" style="color: var(--ag-muted); font-size: 13px; {{ $aiHistory->isEmpty() ? '' : 'display: none;' }}">No validations yet.</p>
            <div id="aiHistoryList" style="display: flex; flex-direction: column; gap: 8px; max-height: 70vh; overflow-y: auto;">
                @foreach($aiHistory as $run)
                    <div class="ag-ai-history-row" data-id="{{ $run->id }}">
                        <button type="button" class="ag-ai-history-item" data-id="{{ $run->id }}"
                                data-url="{{ route('configuration-backups.ai-validations.show', ['id' => $config->id, 'validationId' => $run->id]) }}">
                            <span style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                <strong style="font-size: 13px;">{{ \App\Support\UserPreferences::datetime($run->created_at) }}</strong>
                                <span class="ag-badge {{ ['ok' => 'ag-badge--success', 'warning' => 'ag-badge--warning', 'error' => 'ag-badge--danger'][$run->status] ?? '' }}">{{ ['ok' => 'No issues', 'warning' => 'Warnings', 'error' => 'Errors'][$run->status] ?? 'Review' }}</span>
                            </span>
                            <span style="display: block; color: var(--ag-muted); font-size: 12px; margin-top: 4px;">{{ $run->user?->name ?? 'Deleted user' }} · {{ $run->model }}</span>
                        </button>
                        @if((int) $run->user_id === (int) auth()->id() || auth()->user()->isAdmin())
                            <button type="button" class="ag-ai-history-delete" title="Delete this validation" aria-label="Delete this validation"
                                    data-url="{{ route('configuration-backups.ai-validations.delete', ['id' => $config->id, 'validationId' => $run->id]) }}">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </aside>
    @endif
    </div>
</div>

@if($aiShowPanel)
<script>
(function () {
    const button = document.getElementById('aiValidateBtn');
    const panel = document.getElementById('aiValidateResult');
    const statusEl = document.getElementById('aiValidateStatus');
    const summaryEl = document.getElementById('aiValidateSummary');
    const findingsEl = document.getElementById('aiValidateFindings');
    const descriptionEl = document.getElementById('aiValidateDescription');
    const threatsEl = document.getElementById('aiValidateThreats');
    const metaEl = document.getElementById('aiValidateMeta');
    const savedEl = document.getElementById('aiValidateSaved');
    const shareBtn = document.getElementById('aiShareBtn');
    const historyList = document.getElementById('aiHistoryList');
    const historyEmpty = document.getElementById('aiHistoryEmpty');
    const label = button ? button.querySelector('span') : null;
    const historyUrl = @json(route('configuration-backups.ai-validations.show', ['id' => $config->id, 'validationId' => '__ID__']));
    const initial = @json($aiSelected);
    let shareUrl = null;
    let currentId = null;
    const badge = { ok: ['ag-badge--success', 'No issues'], warning: ['ag-badge--warning', 'Warnings'], error: ['ag-badge--danger', 'Errors'], unknown: ['', 'Review'] };
    const severityBadge = { error: 'ag-badge--danger', warning: 'ag-badge--warning', info: 'ag-badge--info' };
    const codeStyle = 'background: var(--ag-surface); padding: 10px 12px; border-radius: 8px; margin-top: 6px; font-size: 12px; white-space: pre-wrap; overflow-x: auto;';

    // Every value from the AI is set with textContent, never as HTML.
    function el(tag, text, style) {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = text;
        if (style) node.style.cssText = style;
        return node;
    }

    function heading(icon, text) {
        const h = el('h4', null, 'font-size: 15px; font-weight: 600; color: var(--ag-text); margin-bottom: 10px; padding-top: 16px; border-top: 1px solid var(--ag-line);');
        const i = document.createElement('i');
        i.className = 'fas ' + icon;
        h.append(i, ' ' + text);
        return h;
    }

    function labelled(name, text) {
        const p = el('p', null, 'font-size: 13px; line-height: 1.6; color: var(--ag-text); margin-top: 6px;');
        p.append(el('strong', name + ': '), text);
        return p;
    }

    function setStatus(status) {
        const [cls, text] = badge[status] || badge.unknown;
        statusEl.className = 'ag-badge ' + cls;
        statusEl.textContent = text;
    }

    function renderFindings(findings) {
        findingsEl.replaceChildren();
        if (!findings.length) {
            return;
        }
        findingsEl.appendChild(heading('fa-exclamation-triangle', 'Issues found (' + findings.length + ')'));
        findings.forEach((f, index) => {
            const card = el('div', null, 'border: 1px solid var(--ag-line); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px;');
            const top = el('div', null, 'display: flex; flex-wrap: wrap; align-items: center; gap: 8px;');
            const sev = el('span', f.severity);
            sev.className = 'ag-badge ' + (severityBadge[f.severity] || '');
            top.append(sev, el('strong', (index + 1) + '. ' + f.issue, 'font-size: 14px;'));
            card.appendChild(top);
            const where = [f.line ? 'Line ' + f.line : null, f.standard || null].filter(Boolean).join(' · ');
            if (where) card.appendChild(el('div', where, 'color: var(--ag-muted); font-size: 12px; margin-top: 4px;'));
            if (f.details) card.appendChild(labelled('Details', f.details));
            if (f.impact) card.appendChild(labelled('Impact', f.impact));
            if (f.suggestion) card.appendChild(labelled('Recommendation', f.suggestion));
            if (f.fix) {
                card.appendChild(el('div', 'Suggested change', 'font-size: 13px; font-weight: 600; margin-top: 10px;'));
                card.appendChild(el('pre', f.fix, codeStyle));
            }
            if (f.steps && f.steps.length) {
                card.appendChild(el('div', 'How to apply and verify', 'font-size: 13px; font-weight: 600; margin-top: 10px;'));
                const ol = el('ol', null, 'margin: 6px 0 0 20px; font-size: 13px; line-height: 1.6; list-style: decimal;');
                f.steps.forEach((step) => ol.appendChild(el('li', step)));
                card.appendChild(ol);
            }
            findingsEl.appendChild(card);
        });
    }

    function renderDescription(description) {
        descriptionEl.replaceChildren();
        if (!description || (!description.overview && !(description.settings || []).length)) {
            return;
        }
        descriptionEl.appendChild(heading('fa-book-open', 'About this configuration'));
        if (description.overview) {
            descriptionEl.appendChild(el('p', description.overview, 'font-size: 13px; line-height: 1.6; color: var(--ag-text); white-space: pre-wrap;'));
        }
        if ((description.settings || []).length) {
            const table = el('table', null, 'width: 100%; margin-top: 10px; font-size: 13px;');
            table.className = 'ag-table';
            const head = table.createTHead().insertRow();
            ['Setting', 'What it does'].forEach((t) => head.appendChild(el('th', t, 'text-align: left; padding: 8px;')));
            const body = table.createTBody();
            description.settings.forEach((item) => {
                const row = body.insertRow();
                row.insertCell().appendChild(el('code', item.setting, 'font-size: 12px; word-break: break-all;'));
                row.insertCell().textContent = item.meaning;
                [...row.cells].forEach((c) => { c.style.padding = '8px'; c.style.verticalAlign = 'top'; });
            });
            descriptionEl.appendChild(table);
        }
    }

    function renderThreats(threats) {
        threatsEl.replaceChildren();
        if (!threats || !threats.length) {
            return;
        }
        threatsEl.appendChild(heading('fa-shield-alt', 'Potential threats to this type of service'));
        threats.forEach((t) => {
            const card = el('div', null, 'border: 1px solid var(--ag-line); border-radius: 12px; padding: 12px 16px; margin-bottom: 10px;');
            const top = el('div', null, 'display: flex; flex-wrap: wrap; align-items: center; gap: 8px;');
            top.appendChild(el('strong', t.threat, 'font-size: 14px;'));
            if (t.mitigated_here !== null && t.mitigated_here !== undefined) {
                const tag = el('span', t.mitigated_here ? 'Mitigated in this file' : 'Not mitigated in this file');
                tag.className = 'ag-badge ' + (t.mitigated_here ? 'ag-badge--success' : 'ag-badge--warning');
                top.appendChild(tag);
            }
            card.appendChild(top);
            if (t.impact) card.appendChild(labelled('Impact', t.impact));
            if (t.mitigation) card.appendChild(labelled('Mitigation', t.mitigation));
            threatsEl.appendChild(card);
        });
    }

    function clearSections() {
        [findingsEl, descriptionEl, threatsEl].forEach((node) => node.replaceChildren());
        metaEl.textContent = '';
        savedEl.textContent = '';
        shareBtn.style.display = 'none';
        shareUrl = null;
    }

    function showMessage(status, text) {
        panel.style.display = 'block';
        setStatus(status);
        clearSections();
        summaryEl.textContent = text;
    }

    function markActive(id) {
        historyList.querySelectorAll('.ag-ai-history-item').forEach((item) => {
            item.classList.toggle('is-active', String(item.dataset.id) === String(id));
        });
    }

    // Renders a new or a saved result; both have the same shape.
    function showResult(data) {
        panel.style.display = 'block';
        clearSections();
        setStatus(data.status);
        summaryEl.textContent = data.summary || '';
        renderFindings(data.findings || []);
        renderDescription(data.description);
        renderThreats(data.threats || []);
        const notes = [data.provider + ' (' + data.model + ')'];
        if (data.masked) notes.push(data.masked + ' secret value(s) masked');
        if (data.truncated) notes.push('file was cut for length');
        metaEl.textContent = notes.join(' · ') + '. AI output can be wrong; check before changing a live service.';
        savedEl.textContent = 'Saved ' + (data.created_at_display || '') + (data.created_by ? ' by ' + data.created_by : '');
        shareUrl = data.share_url || null;
        shareBtn.style.display = shareUrl ? 'inline-flex' : 'none';
        currentId = data.id;
        markActive(data.id);
    }

    function addHistoryItem(data) {
        const row = el('div');
        row.className = 'ag-ai-history-row';
        row.dataset.id = data.id;
        const item = el('button');
        item.type = 'button';
        item.className = 'ag-ai-history-item';
        item.dataset.id = data.id;
        item.dataset.url = historyUrl.replace('__ID__', data.id);
        const top = el('span', null, 'display: flex; align-items: center; justify-content: space-between; gap: 8px;');
        const [cls, text] = badge[data.status] || badge.unknown;
        const tag = el('span', text);
        tag.className = 'ag-badge ' + cls;
        top.append(el('strong', data.created_at_display || 'Just now', 'font-size: 13px;'), tag);
        item.append(top, el('span', (data.created_by || '') + ' · ' + data.model, 'display: block; color: var(--ag-muted); font-size: 12px; margin-top: 4px;'));
        row.appendChild(item);
        if (data.can_delete) {
            const remove = el('button');
            remove.type = 'button';
            remove.className = 'ag-ai-history-delete';
            remove.title = 'Delete this validation';
            remove.setAttribute('aria-label', 'Delete this validation');
            remove.dataset.url = item.dataset.url;
            const icon = document.createElement('i');
            icon.className = 'fas fa-trash-alt';
            remove.appendChild(icon);
            row.appendChild(remove);
        }
        historyList.prepend(row);
        historyEmpty.style.display = 'none';
    }

    async function deleteHistoryItem(remove) {
        if (!window.confirm('Delete this AI validation? This cannot be undone.')) return;
        const row = remove.closest('.ag-ai-history-row');
        remove.disabled = true;
        try {
            const response = await fetch(remove.dataset.url, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': @json(csrf_token()) },
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                window.alert(data.message || ('Could not delete this validation (HTTP ' + response.status + ').'));
                remove.disabled = false;
                return;
            }
            if (String(currentId) === String(row.dataset.id)) {
                panel.style.display = 'none';
                clearSections();
                currentId = null;
                history.replaceState(null, '', window.location.pathname);
            }
            row.remove();
            historyEmpty.style.display = historyList.children.length ? 'none' : '';
        } catch (error) {
            window.alert('Could not reach the console. Check your connection and try again.');
            remove.disabled = false;
        }
    }

    historyList.addEventListener('click', async function (event) {
        const remove = event.target.closest('.ag-ai-history-delete');
        if (remove) {
            deleteHistoryItem(remove);
            return;
        }
        const item = event.target.closest('.ag-ai-history-item');
        if (!item) return;
        markActive(item.dataset.id);
        try {
            const response = await fetch(item.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                showMessage('error', data.message || ('Could not open this result (HTTP ' + response.status + ').'));
                return;
            }
            showResult(data);
            history.replaceState(null, '', data.share_url);
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showMessage('error', 'Could not reach the console. Check your connection and try again.');
        }
    });

    shareBtn.addEventListener('click', async function () {
        if (!shareUrl) return;
        const text = shareBtn.querySelector('span');
        try {
            await navigator.clipboard.writeText(shareUrl);
            text.textContent = 'Link copied';
        } catch (error) {
            window.prompt('Copy this link. Only people who can open this file can view it.', shareUrl);
        }
        setTimeout(() => { text.textContent = 'Share'; }, 2000);
    });

    if (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            label.textContent = 'Validating...';
            showMessage('unknown', 'Waiting for the AI provider. A full review can take one to three minutes.');
            statusEl.textContent = 'Running';

            try {
                const response = await fetch(button.dataset.url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    credentials: 'same-origin',
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    showMessage('error', data.message || ('Request failed (HTTP ' + response.status + ').'));
                    statusEl.textContent = 'Failed';
                    return;
                }
                addHistoryItem(data);
                showResult(data);
            } catch (error) {
                showMessage('error', 'Could not reach the console. Check your connection and try again.');
                statusEl.textContent = 'Failed';
            } finally {
                button.disabled = false;
                label.textContent = 'Validate with AI';
            }
        });
    }

    if (initial) {
        showResult(Object.assign({ share_url: window.location.href }, initial));
    }
})();
</script>
@endif
@endsection
