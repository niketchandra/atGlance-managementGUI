@extends('app')

@section('title', 'Profile - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <!-- Banner Background -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 200px; border-radius: 10px; margin-bottom: 50px; position: relative;"></div>

    <!-- Profile Header -->
    <div style="display: grid; grid-template-columns: auto 1fr auto; gap: 30px; align-items: start; margin-bottom: 40px;">
        <!-- Profile Picture and Name -->
        <div style="text-align: center; margin-top: -100px;">
            <div style="width: 150px; height: 150px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; border: 5px solid white; box-shadow: 0 5px 15px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; font-size: 60px; color: white; margin: 0 auto;">
                <i class="fas fa-user"></i>
            </div>
        </div>

        <!-- Info -->
        <div>
            <h1 style="font-size: 32px; font-weight: bold; color: #333; margin-bottom: 5px;">{{ auth()->user()->name }}</h1>
            <p style="color: #666; font-size: 16px; margin-bottom: 20px;">{{ auth()->user()->email }}</p>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div>
                    <p style="color: #999; font-size: 12px; margin-bottom: 5px;">Member Since</p>
                    <p style="font-weight: 600; color: #333;">March 1, 2026</p>
                </div>
                <div>
                    <p style="color: #999; font-size: 12px; margin-bottom: 5px;">Plan</p>
                    <p style="font-weight: 600; color: #333;">Professional</p>
                </div>
                <div>
                    <p style="color: #999; font-size: 12px; margin-bottom: 5px;">Status</p>
                    <p style="font-weight: 600; color: #4caf50;"><i class="fas fa-check-circle"></i> Active</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="{{ route('settings') }}" class="profile-btn" style="padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 6px; font-weight: 600; text-align: center; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
            <button type="button" onclick="submitLogoutForm()" class="profile-btn" style="padding: 12px 24px; background: #f0f0f0; color: #333; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.3s ease;" onmouseover="this.style.background='#e0e0e0'" onmouseout="this.style.background='#f0f0f0'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </div>

    <style>
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .profile-section h2 {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .stat-card {
            padding: 20px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 8px;
            border: 1px solid rgba(102, 126, 234, 0.2);
            text-align: center;
        }

        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 5px;
        }

        .stat-card .label {
            color: #666;
            font-size: 13px;
            text-transform: uppercase;
        }

        .activity-item {
            padding: 15px;
            border-left: 3px solid #667eea;
            background: #f8f9fa;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .activity-item .time {
            color: #999;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .activity-item .event {
            font-weight: 600;
            color: #333;
        }
    </style>

    <!-- Overview Stats -->
    <div class="profile-section">
        <h2><i class="fas fa-bar-chart"></i> Overview</h2>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="number">8</div>
                <div class="label">Active APIs</div>
            </div>
            <div class="stat-card">
                <div class="number">3.2M</div>
                <div class="label">Total Requests</div>
            </div>
            <div class="stat-card">
                <div class="number">99.8%</div>
                <div class="label">Uptime</div>
            </div>
            <div class="stat-card">
                <div class="number">245ms</div>
                <div class="label">Avg Response</div>
            </div>
        </div>
    </div>

    <!-- Account Information -->
    <div class="profile-section">
        <h2><i class="fas fa-user-circle"></i> Account Information</h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 20px;">
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Email Address</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ auth()->user()->email }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Account Status</p>
                <p style="font-size: 16px; color: #4caf50; font-weight: 500;"><i class="fas fa-check-circle"></i> Active & Verified</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Joined</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">March 1, 2026</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Last Login</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">Today at 2:45 PM</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Date of Birth</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ auth()->user()->dob ? auth()->user()->dob->format('F j, Y') : 'Not specified' }}</p>
            </div>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <a href="{{ route('settings') }}" class="profile-btn" style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 6px; font-weight: 600; inline-block;">
                <i class="fas fa-edit"></i> Update Information
            </a>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="profile-section">
        <h2><i class="fas fa-history"></i> Recent Activity</h2>

        <div class="activity-item">
            <div class="time"><i class="fas fa-clock"></i> Today at 2:45 PM</div>
            <div class="event">Logged in from Chrome on Windows</div>
        </div>

        <div class="activity-item">
            <div class="time"><i class="fas fa-clock"></i> Yesterday at 10:20 AM</div>
            <div class="event">Updated API Rate Limits</div>
        </div>

        <div class="activity-item">
            <div class="time"><i class="fas fa-clock"></i> March 28, 2026</div>
            <div class="event">Created new API key "Production"</div>
        </div>

        <div class="activity-item">
            <div class="time"><i class="fas fa-clock"></i> March 25, 2026</div>
            <div class="event">Changed password</div>
        </div>

        <div class="activity-item">
            <div class="time"><i class="fas fa-clock"></i> March 20, 2026</div>
            <div class="event">Updated notification preferences</div>
        </div>
    </div>

    <!-- Security & Privacy -->
    <div class="profile-section">
        <h2><i class="fas fa-shield-alt"></i> Security & Privacy</h2>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-lock"></i> Password</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Last changed March 25, 2026</p>
                <a href="{{ route('settings') }}" style="color: #667eea; text-decoration: none; font-weight: 600;">Change Password</a>
            </div>

            <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-shield-alt"></i> Two-Factor Auth</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Not enabled</p>
                <a href="{{ route('settings') }}" style="color: #667eea; text-decoration: none; font-weight: 600;">Enable 2FA</a>
            </div>

            <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-key"></i> API Keys</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">2 active keys</p>
                <a href="{{ route('settings') }}" style="color: #667eea; text-decoration: none; font-weight: 600;">Manage Keys</a>
            </div>

            <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-laptop"></i> Sessions</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">1 active session</p>
                <a href="{{ route('settings') }}" style="color: #667eea; text-decoration: none; font-weight: 600;">View Sessions</a>
            </div>
        </div>
    </div>

    <!-- Preferences -->
    <div class="profile-section">
        <h2><i class="fas fa-cogs"></i> Preferences</h2>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Language</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">English (US)</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Theme</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">Light</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Email Notifications</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">Enabled</p>
            </div>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <a href="{{ route('settings') }}" class="profile-btn" style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 6px; font-weight: 600; inline-block;">
                <i class="fas fa-sliders-h"></i> Change Preferences
            </a>
        </div>
    </div>
</div>
@endsection
