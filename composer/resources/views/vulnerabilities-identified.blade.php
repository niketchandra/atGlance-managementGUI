@extends('app')

@section('title', 'Vulnerabilities Identified - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div class="ag-card" style="padding: 30px;">
        <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 10px;">Vulnerabilities Identified</h1>
        <p style="color: var(--ag-muted); font-size: 14px; margin-bottom: 20px;">
            Configuration files whose latest AI validation reported an error (high) or a warning (medium).
        </p>

        <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
            @foreach ([null => 'All', 'error' => 'Error', 'warning' => 'Warning'] as $value => $label)
                <a href="{{ route('vulnerabilities-identified', $value ? ['severity' => $value] : []) }}"
                   class="ag-btn"
                   style="text-decoration: none; padding: 6px 14px; {{ ($severity ?? '') === ($value ?: '') ? '' : 'opacity: 0.55;' }}">
                    {{ $label }}
                    @if ($value)
                        ({{ $vulnerabilityStats[$value]['total'] }})
                    @else
                        ({{ $vulnerabilityStats['error']['total'] + $vulnerabilityStats['warning']['total'] }})
                    @endif
                </a>
            @endforeach
        </div>

        @if ($findings->isEmpty())
            <p style="color: var(--ag-muted);">No vulnerable configurations found.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="text-align: left; color: var(--ag-muted); border-bottom: 1px solid var(--ag-border, #ddd);">
                            <th style="padding: 10px;">Severity</th>
                            <th style="padding: 10px;">Service</th>
                            <th style="padding: 10px;">File</th>
                            <th style="padding: 10px;">Version</th>
                            <th style="padding: 10px;">System</th>
                            <th style="padding: 10px;">Summary</th>
                            <th style="padding: 10px;">Validated</th>
                            <th style="padding: 10px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($findings as $row)
                            <tr style="border-bottom: 1px solid var(--ag-border, #eee); color: var(--ag-text);">
                                <td style="padding: 10px;">
                                    <span style="padding: 2px 10px; border-radius: 999px; font-size: 12px; color: #fff; background: {{ $row->severity === 'error' ? '#e45757' : '#e0a030' }};">
                                        {{ ucfirst($row->severity) }}
                                    </span>
                                </td>
                                <td style="padding: 10px;">
                                    <a href="{{ route('configuration-backups.service-versions-by-name', ['serviceName' => $row->service_name]) }}">{{ $row->service_name }}</a>
                                </td>
                                <td style="padding: 10px;">{{ $row->file_name }}</td>
                                <td style="padding: 10px;">{{ $row->version }}</td>
                                <td style="padding: 10px;">{{ $row->system_name ?? '-' }}</td>
                                <td style="padding: 10px; max-width: 360px;">{{ \Illuminate\Support\Str::limit($row->summary, 140) }}</td>
                                <td style="padding: 10px; white-space: nowrap;">{{ \App\Support\UserPreferences::datetime($row->validated_at) }}</td>
                                <td style="padding: 10px; white-space: nowrap;">
                                    <a href="{{ route('configuration-backups.view', $row->id) }}">View</a>
                                    &middot;
                                    <a href="{{ route('configuration-backups.service-versions-by-name', ['serviceName' => $row->service_name]) }}">Versions</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 16px;">{{ $findings->links() }}</div>
        @endif
    </div>
</div>
@endsection
