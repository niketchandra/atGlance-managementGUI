@extends('app')

@section('title', 'Dashboard - ' . $brandName)

@section('dashboard-content')
<style>
    .dashboard-page {
        padding: 40px;
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .kpi-card {
        text-decoration: none;
        background: var(--ag-card);
        padding: 20px;
        border-radius: 16px;
        box-shadow: var(--ag-shadow);
        transition: transform 0.2s ease;
        display: block;
    }

    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 15px;
    }

    @media (max-width: 992px) {
        .kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .quick-actions-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 576px) {
        .dashboard-page {
            padding: 20px;
        }

        .kpi-grid,
        .quick-actions-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dashboard-page">
    <!-- Dashboard Welcome Card -->
    <div class="ag-banner" style="padding: 40px; margin-bottom: 30px;">
        <h1 style="font-size: 30px; margin-bottom: 10px;">Welcome back, {{ auth()->user()->name }}! 👋</h1>
        <p style="font-size: 16px; opacity: 0.9;">Here's the latest on your systems, configuration backups and vulnerabilities</p>
    </div>

    <!-- Top KPI Boxes -->
    <div class="kpi-grid">
        <a href="{{ route('configuration-backups') }}" class="kpi-card">
            <div style="color: var(--ag-muted); font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Total Configuration Backups</div>
            <div style="font-size: 28px; font-weight: bold; color: var(--ag-text);">{{ $totalConfigBackups }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $configChange['direction'] === 'down' ? '#e45757' : ($configChange['direction'] === 'up' ? '#1fa874' : '#8a9099') }};">
                @if($configChange['direction'] === 'up')
                    <i class="fas fa-arrow-up"></i>
                @elseif($configChange['direction'] === 'down')
                    <i class="fas fa-arrow-down"></i>
                @else
                    <i class="fas fa-minus"></i>
                @endif
                {{ $configChange['percent'] }}% {{ $configChange['direction'] === 'flat' ? 'no change' : $configChange['direction'] }} from last week
            </div>
        </a>

        <a href="{{ route('systems-registered') }}" class="kpi-card">
            <div style="color: var(--ag-muted); font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Total Systems Registered</div>
            <div style="font-size: 28px; font-weight: bold; color: var(--ag-text);">{{ $totalSystemsRegistered }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $systemsChange['direction'] === 'down' ? '#e45757' : ($systemsChange['direction'] === 'up' ? '#1fa874' : '#8a9099') }};">
                @if($systemsChange['direction'] === 'up')
                    <i class="fas fa-arrow-up"></i>
                @elseif($systemsChange['direction'] === 'down')
                    <i class="fas fa-arrow-down"></i>
                @else
                    <i class="fas fa-minus"></i>
                @endif
                {{ $systemsChange['percent'] }}% {{ $systemsChange['direction'] === 'flat' ? 'no change' : $systemsChange['direction'] }} from last week
            </div>
        </a>

        <a href="{{ route('live-service-monitoring') }}" class="kpi-card">
            <div style="color: var(--ag-muted); font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Total Services Monitored</div>
            <div style="font-size: 28px; font-weight: bold; color: var(--ag-text);">{{ $totalServicesMonitored }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $servicesChange['direction'] === 'down' ? '#e45757' : ($servicesChange['direction'] === 'up' ? '#1fa874' : '#8a9099') }};">
                @if($servicesChange['direction'] === 'up')
                    <i class="fas fa-arrow-up"></i>
                @elseif($servicesChange['direction'] === 'down')
                    <i class="fas fa-arrow-down"></i>
                @else
                    <i class="fas fa-minus"></i>
                @endif
                {{ $servicesChange['percent'] }}% {{ $servicesChange['direction'] === 'flat' ? 'no change' : $servicesChange['direction'] }} from last week
            </div>
        </a>

        @include('partials.vulnerability-kpi')
    </div>

    {{-- Quick Actions: hidden for now; remove the @if to show it again. --}}
    @if (false)
    <div class="ag-card" style="padding: 30px; margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: 500; margin-bottom: 20px;"><i class="fas fa-bolt"></i> Quick Actions</h2>
        <div class="quick-actions-grid">
            <a href="{{ route('configuration-backups') }}" class="ag-btn quick-action-btn" style="text-decoration: none; text-align: center;">
                <i class="fas fa-file-code"></i> Configuration Backups
            </a>
            <a href="{{ route('systems-registered') }}" class="ag-btn quick-action-btn" style="text-decoration: none; text-align: center;">
                <i class="fas fa-server"></i> Registered Systems
            </a>
            <a href="{{ route('vulnerabilities-identified') }}" class="ag-btn quick-action-btn" style="text-decoration: none; text-align: center;">
                <i class="fas fa-shield-alt"></i> Vulnerabilities
            </a>
            <a href="{{ route('settings') }}#api" class="ag-btn quick-action-btn" style="text-decoration: none; text-align: center;">
                <i class="fas fa-key"></i> Manage API Keys
            </a>
        </div>
    </div>

    @endif

    @include('partials.performance-chart')
</div>
@endsection
