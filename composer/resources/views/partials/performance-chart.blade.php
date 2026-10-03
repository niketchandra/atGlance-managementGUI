{{-- Performance (Last 7 Days): configs by latest AI validation result. Needs $validationTrend from DashboardController::validationTrend(). --}}
    <!-- Performance: AI validation results per day -->
    @php
        $lastCheck = $validationTrend['last_check'];
        $validationTrend = $validationTrend['days'];
        $trendSeries = ['error' => ['Errors', '#e45757'], 'warning' => ['Warnings', '#e0a030'], 'ok' => ['No Issues', '#1fa874']];
    @endphp
    <div class="ag-card" style="padding: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 500;"><i class="fas fa-chart-line"></i> Performance (Last 7 Days)</h2>
                <div style="font-size: 12px; color: var(--ag-muted); margin-top: 4px;">
                    Configs by latest AI validation result{{ (int) (auth()->user()->rbac_id ?? 0) === 100 && request()->routeIs('admin.dashboard') ? ' · All workspaces' : '' }}{{ $lastCheck ? ' · Last check: ' . $lastCheck : '' }}
                </div>
            </div>
            <div style="display: flex; gap: 14px; font-size: 12px; color: var(--ag-muted);">
                @foreach ($trendSeries as [$label, $color])
                    <span><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: {{ $color }}; margin-right: 4px;"></span>{{ $label }}</span>
                @endforeach
            </div>
        </div>
        @php
            $chartW = 700; $chartH = 220; $padL = 36; $padR = 16; $padT = 12; $padB = 28;
            $plotW = $chartW - $padL - $padR; $plotH = $chartH - $padT - $padB;
            $trendMax = max(1, max(array_map(fn ($d) => max($d['error'], $d['warning'], $d['ok']), $validationTrend)));
            $stepX = $plotW / max(1, count($validationTrend) - 1);
            $pointX = fn ($i) => round($padL + $i * $stepX, 1);
            $pointY = fn ($v) => round($padT + $plotH - ($v / $trendMax) * $plotH, 1);
        @endphp
        <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" style="width: 100%; height: auto; display: block;" role="img" aria-label="AI validation results per day">
            @foreach ([0, 0.5, 1] as $fraction)
                @php($gridValue = round($trendMax * $fraction))
                <line x1="{{ $padL }}" x2="{{ $chartW - $padR }}" y1="{{ $pointY($gridValue) }}" y2="{{ $pointY($gridValue) }}" stroke="currentColor" stroke-opacity="0.12" />
                <text x="{{ $padL - 8 }}" y="{{ $pointY($gridValue) + 4 }}" text-anchor="end" font-size="11" fill="currentColor" fill-opacity="0.55">{{ $gridValue }}</text>
            @endforeach
            @foreach ($validationTrend as $i => $day)
                <text x="{{ $pointX($i) }}" y="{{ $chartH - 8 }}" text-anchor="middle" font-size="11" fill="currentColor" fill-opacity="0.55">{{ $day['label'] }}</text>
            @endforeach
            @foreach ($trendSeries as $key => [$label, $color])
                <polyline fill="none" stroke="{{ $color }}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"
                          points="{{ collect($validationTrend)->map(fn ($d, $i) => $pointX($i) . ',' . $pointY($d[$key]))->implode(' ') }}" />
                @foreach ($validationTrend as $i => $day)
                    <circle cx="{{ $pointX($i) }}" cy="{{ $pointY($day[$key]) }}" r="4" fill="{{ $color }}">
                        <title>{{ $day['label'] }}: {{ $day[$key] }} {{ strtolower($label) }}</title>
                    </circle>
                @endforeach
            @endforeach
        </svg>
        @unless ($lastCheck)
            <p style="text-align: center; color: var(--ag-muted); font-size: 13px; margin-top: 10px;">No configuration has been validated with AI yet.</p>
        @endunless
    </div>
