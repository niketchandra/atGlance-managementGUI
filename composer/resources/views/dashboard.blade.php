@extends('app')

@section('title', 'Dashboard - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <!-- Dashboard Welcome Card -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px; border-radius: 10px; margin-bottom: 30px;">
        <h1 style="font-size: 32px; margin-bottom: 10px;">Welcome back, {{ auth()->user()->name }}! 👋</h1>
        <p style="font-size: 16px; opacity: 0.9;">Here's what's happening with your API Gateway today</p>
    </div>

    <!-- Top KPI Boxes -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px;">
        <a href="{{ route('configuration-backups') }}" style="text-decoration: none; background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #667eea; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
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

        <a href="{{ route('systems-registered') }}" style="text-decoration: none; background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #764ba2; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
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

        <a href="{{ route('live-service-monitoring') }}" style="text-decoration: none; background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #ff9800; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="color: #999; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Live Service Monitoring</div>
            <div style="font-size: 28px; font-weight: bold; color: #333;">Coming Soon</div>
            <div style="font-size: 12px; margin-top: 8px; color: #4caf50;"><i class="fas fa-arrow-up"></i> 12% up from last week</div>
        </a>

        <a href="{{ route('vulnerabilities-identified') }}" style="text-decoration: none; background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #2196f3; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="color: #999; font-size: 13px; text-transform: uppercase; margin-bottom: 10px;">Vulnerabilities Identified</div>
            <div style="font-size: 28px; font-weight: bold; color: #333;">Coming Soon</div>
            <div style="font-size: 12px; margin-top: 8px; color: #4caf50;"><i class="fas fa-arrow-up"></i> 12% up from last week</div>
        </a>
    </div>

    <!-- Quick Actions -->
    <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><i class="fas fa-lightning-bolt"></i> Quick Actions</h2>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
            <button class="quick-action-btn" style="padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-plus-circle"></i> New API
            </button>
            <button class="quick-action-btn" style="padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-chart-line"></i> View Analytics
            </button>
            <button class="quick-action-btn" style="padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-key"></i> Manage Keys
            </button>
            <button class="quick-action-btn" style="padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-file-download"></i> Export Report
            </button>
        </div>
    </div>

    <!-- Recent Activity -->
    <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
        <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><i class="fas fa-history"></i> Recent Activity</h2>
        <table style="width: 100%; border-collapse: collapse;">
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
                    <td style="padding: 15px; color: #333;"><i class="fas fa-plug" style="color: #667eea;"></i> API Deployed</td>
                    <td style="padding: 15px;"><span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Success</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">User API v2.0 deployed</td>
                </tr>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 15px; color: #333;">2026-03-01 13:15:45</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-key" style="color: #764ba2;"></i> Key Rotated</td>
                    <td style="padding: 15px;"><span style="background: #cfe9fc; color: #004085; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Info</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">API key rotated for security</td>
                </tr>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 15px; color: #333;">2026-03-01 12:00:22</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-cog" style="color: #ff9800;"></i> Settings Updated</td>
                    <td style="padding: 15px;"><span style="background: #cfe9fc; color: #004085; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Info</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">Rate limits updated</td>
                </tr>
                <tr>
                    <td style="padding: 15px; color: #333;">2026-03-01 11:30:50</td>
                    <td style="padding: 15px; color: #333;"><i class="fas fa-bell" style="color: #2196f3;"></i> Alert Triggered</td>
                    <td style="padding: 15px;"><span style="background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Warning</span></td>
                    <td style="padding: 15px; color: #666; font-size: 13px;">High response time detected</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Performance Chart Placeholder -->
    <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="font-size: 18px; font-weight: bold; margin-bottom: 20px;"><i class="fas fa-chart-line"></i> Performance (Last 7 Days)</h2>
        <div style="height: 250px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 16px;">
            <i class="fas fa-chart-area"></i> Chart will be displayed here
        </div>
    </div>
</div>
@endsection
