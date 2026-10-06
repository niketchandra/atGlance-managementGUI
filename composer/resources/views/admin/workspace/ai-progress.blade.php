{{-- Progress of the latest Vulnerability Checks run; polls admin.workspaces.ai.progress while it runs. --}}
@php($progress = $aiRun?->progress())
<div class="ag-card" style="padding: 20px 24px; margin-top: 18px;" id="aiProgressCard"
     data-url="{{ route('admin.workspaces.ai.progress', $workspace->id) }}"
     data-running="{{ $progress && !$progress['finished'] ? '1' : '0' }}">
    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 10px;">
        <h3 style="font-size: 16px; color: var(--ag-text); margin: 0;">Check Queue</h3>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span id="aiProgressLabel" style="font-size: 13px; color: var(--ag-muted);">
                @if(!$progress)
                    No check has been queued yet.
                @else
                    {{ $progress['cancelled'] ? 'Reset' : ($progress['finished'] ? 'Finished' : 'In progress') }} &middot; {{ $progress['processed'] }} of {{ $progress['total'] }} files
                @endif
            </span>
            @if($progress && !$progress['finished'] && $permissions['vulnerability_checks'])
                <form method="POST" action="{{ route('admin.workspaces.ai.reset', $workspace->id) }}" id="aiResetForm" style="margin: 0;">
                    @csrf
                    <button class="ag-btn ag-btn--danger" type="submit" style="padding: 5px 12px; font-size: 12px;"
                            onclick="return confirm('Reset the check queue? Files not reviewed yet are skipped. Finished reviews are kept.')">
                        <i class="fas fa-rotate-left"></i> Reset queue
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="ai-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] ?? 0 }}" aria-labelledby="aiProgressLabel">
        <div id="aiProgressBar" class="ai-progress-bar {{ $progress && !$progress['finished'] ? 'ai-progress-bar--running' : '' }}" style="width: {{ $progress['percent'] ?? 0 }}%;"></div>
        <span id="aiProgressPercent" class="ai-progress-percent">{{ $progress['percent'] ?? 0 }}%</span>
    </div>

    <div id="aiProgressCounts" style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: var(--ag-muted);">
        @if($progress)
            <span>Reviewed: <strong>{{ $progress['done'] }}</strong></span>
            <span>Skipped: <strong>{{ $progress['skipped'] }}</strong></span>
            <span>Failed: <strong style="color: {{ $progress['failed'] > 0 ? '#e45757' : 'inherit' }};">{{ $progress['failed'] }}</strong></span>
            <span>Started {{ \App\Support\UserPreferences::datetime($aiRun->created_at) }}</span>
        @endif
    </div>
    <p style="margin-top: 8px; font-size: 11px; color: var(--ag-muted);">Skipped: a newer upload arrived, or AI Connect was off. Failed: the AI provider returned an error (see the queue worker log). Reset queue stops the run: files not reviewed yet are skipped, finished reviews are kept.</p>
</div>

<style>
    .ai-progress-track { position: relative; height: 22px; border-radius: 999px; background: var(--ag-surface); overflow: hidden; }
    .ai-progress-bar { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #1fa874, #2fbaf2); transition: width 0.6s ease; }
    .ai-progress-bar--running { background-size: 40px 40px; background-image: linear-gradient(45deg, rgba(255,255,255,0.18) 25%, transparent 25%, transparent 50%, rgba(255,255,255,0.18) 50%, rgba(255,255,255,0.18) 75%, transparent 75%), linear-gradient(90deg, #1fa874, #2fbaf2); animation: ai-progress-stripes 1s linear infinite; }
    .ai-progress-percent { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: var(--ag-text); }
    @keyframes ai-progress-stripes { from { background-position: 40px 0, 0 0; } to { background-position: 0 0, 0 0; } }
    @media (prefers-reduced-motion: reduce) { .ai-progress-bar--running { animation: none; } }
</style>

<script>
    (function () {
        const card = document.getElementById('aiProgressCard');
        if (!card || card.dataset.running !== '1') {
            return;
        }

        const bar = document.getElementById('aiProgressBar');
        const percent = document.getElementById('aiProgressPercent');
        const label = document.getElementById('aiProgressLabel');
        const counts = document.getElementById('aiProgressCounts');
        const track = card.querySelector('.ai-progress-track');

        function render(run) {
            bar.style.width = run.percent + '%';
            percent.textContent = run.percent + '%';
            track.setAttribute('aria-valuenow', run.percent);
            label.textContent = (run.cancelled ? 'Reset' : (run.finished ? 'Finished' : 'In progress')) + ' · ' + run.processed + ' of ' + run.total + ' files';
            const resetForm = document.getElementById('aiResetForm');
            if (resetForm && run.finished) {
                resetForm.remove();
            }
            counts.children[0].querySelector('strong').textContent = run.done;
            counts.children[1].querySelector('strong').textContent = run.skipped;
            counts.children[2].querySelector('strong').textContent = run.failed;
            bar.classList.toggle('ai-progress-bar--running', !run.finished);
        }

        const timer = setInterval(function () {
            fetch(card.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (run) {
                    if (!run) {
                        return;
                    }
                    render(run);
                    if (run.finished) {
                        clearInterval(timer);
                    }
                })
                .catch(function () { /* try again on the next tick */ });
        }, 3000);
    })();
</script>
