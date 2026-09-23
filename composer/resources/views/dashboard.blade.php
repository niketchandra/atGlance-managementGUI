@extends('app')

@section('title', 'Dashboard - AtGlance')

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
        background: white;
        padding: 20px;
        border-radius: 10px;
        border: 1px solid #b3b3b3;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        transition: transform 0.2s ease;
        display: block;
    }

    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 15px;
    }

    .recent-activity-table-wrap {
        width: 100%;
        overflow-x: auto;
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
    <div style="background: #000000; color: white; padding: 40px; border-radius: 10px; margin-bottom: 30px;">
        <h1 style="font-size: 32px; margin-bottom: 10px;">Welcome back, {{ auth()->user()->name }}! 👋</h1>
        <p style="font-size: 16px; opacity: 0.9;">Here's what's happening with your API Gateway today</p>
    </div>

    <!-- Top KPI Boxes -->
    <div class="kpi-grid">
        <a href="{{ route('configuration-backups') }}" class="kpi-card" style="border-left: 4px solid #000000;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="color: #999; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Total Configuration Backups</div>
            <div style="font-size: 28px; font-weight: bold; color: #333;">{{ $totalConfigBackups }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $configChange['direction'] === 'down' ? '#f44336' : ($configChange['direction'] === 'up' ? '#4caf50' : '#666') }};">
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

        <a href="{{ route('systems-registered') }}" class="kpi-card" style="border-left: 4px solid #333333;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="color: #999; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Total Systems Registered</div>
            <div style="font-size: 28px; font-weight: bold; color: #333;">{{ $totalSystemsRegistered }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $systemsChange['direction'] === 'down' ? '#f44336' : ($systemsChange['direction'] === 'up' ? '#4caf50' : '#666') }};">
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

        <a href="{{ route('live-service-monitoring') }}" class="kpi-card" style="border-left: 4px solid #666666;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="color: #999; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Total Services Monitored</div>
            <div style="font-size: 28px; font-weight: bold; color: #333;">{{ $totalServicesMonitored }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $servicesChange['direction'] === 'down' ? '#f44336' : ($servicesChange['direction'] === 'up' ? '#4caf50' : '#666') }};">
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

        <a href="{{ route('vulnerabilities-identified') }}" class="kpi-card" style="border-left: 4px solid #4d4d4d;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="color: #999; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Vulnerabilities Identified</div>
            <div style="font-size: 28px; font-weight: bold; color: #333;">{{ $totalPotentialVulnerabilities }}</div>
            <div style="font-size: 12px; margin-top: 8px; color: {{ $vulnerabilitiesChange['direction'] === 'down' ? '#4caf50' : ($vulnerabilitiesChange['direction'] === 'up' ? '#f44336' : '#666') }};">
                @if($vulnerabilitiesChange['direction'] === 'up')
                    <i class="fas fa-arrow-up"></i>
                @elseif($vulnerabilitiesChange['direction'] === 'down')
                    <i class="fas fa-arrow-down"></i>
                @else
                    <i class="fas fa-minus"></i>
                @endif
                {{ $vulnerabilitiesChange['percent'] }}% {{ $vulnerabilitiesChange['direction'] === 'flat' ? 'no change' : $vulnerabilitiesChange['direction'] }} from last week
            </div>
        </a>
    </div>

    <!-- Quick Actions -->
    <div style="background: white; padding: 30px; border-radius: 10px; border: 1px solid #b3b3b3; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><i class="fas fa-lightning-bolt"></i> Quick Actions</h2>
        <div class="quick-actions-grid">
            <button class="quick-action-btn" style="padding: 15px; background: #000000; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-plus-circle"></i> New API
            </button>
            <button class="quick-action-btn" style="padding: 15px; background: #000000; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-chart-line"></i> View Analytics
            </button>
            <button class="quick-action-btn" style="padding: 15px; background: #000000; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-key"></i> Manage Keys
            </button>
            <button class="quick-action-btn" style="padding: 15px; background: #000000; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-file-download"></i> Export Report
            </button>
        </div>
    </div>

    <!-- Recent Activity -->
    <div style="background: white; padding: 30px; border-radius: 10px; border: 1px solid #b3b3b3; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><i class="fas fa-history"></i> Recent Activity</h2>
        <div class="recent-activity-table-wrap">
        <table style="width: 100%; border-collapse: collapse; min-width: 720px;">
            <thead>
                <tr style="background: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                    <th style="padding: 15px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Timestamp</th>
                    <th style="padding: 15px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Event</th>
                    <th style="padding: 15px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Status</th>
                    <th style="padding: 15px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Details</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 15px; color: #333;">2026-03-01 14:32:10</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-plug" style="color: #333333;"></i> API Deployed</td>
                    <td style="padding: 15px;"><span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Success</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">User API v2.0 deployed</td>
                </tr>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 15px; color: #333;">2026-03-01 13:15:45</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-key" style="color: #333333;"></i> Key Rotated</td>
                    <td style="padding: 15px;"><span style="background: #cfe9fc; color: #004085; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Info</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">API key rotated for security</td>
                </tr>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 15px; color: #333;">2026-03-01 12:00:22</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-cog" style="color: #555555;"></i> Settings Updated</td>
                    <td style="padding: 15px;"><span style="background: #cfe9fc; color: #004085; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Info</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">Rate limits updated</td>
                </tr>
                <tr>
                    <td style="padding: 15px; color: #333;">2026-03-01 11:30:50</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-bell" style="color: #555555;"></i> Alert Triggered</td>
                    <td style="padding: 15px;"><span style="background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Warning</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">High response time detected</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Performance Chart Placeholder -->
    <div style="background: white; padding: 30px; border-radius: 10px; border: 1px solid #b3b3b3; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
        <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><i class="fas fa-chart-line"></i> Performance (Last 7 Days)</h2>
        <div style="height: 250px; background: #ededed; border: 1px solid #b3b3b3; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #555; font-size: 16px;">
            <i class="fas fa-chart-area"></i> Chart will be displayed here
        </div>
    </div>
</div>
@endsection
