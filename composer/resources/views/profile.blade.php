@extends('app')

@section('title', 'Profile - AtGlance')

@section('dashboard-content')
@php($requiresProfileSetup = !auth()->user()->dob || !auth()->user()->pin)
<div style="padding: 40px;">
    <style>
        .pin-row {
            display: flex;
            gap: 12px;
            margin-bottom: 4px;
        }

        .pin-box {
            width: 52px;
            height: 54px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .pin-box:focus {
            outline: none;
            border-color: #000000;
            box-shadow: 0 0 0 4px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }

        .pin-help-text {
            margin-top: 6px;
            color: #6b7280;
            font-size: 12px;
        }
    </style>

    @if(session('complete_profile_required'))
        <div style="margin-bottom: 20px; padding: 14px 16px; border-radius: 8px; background: #fff3cd; border: 1px solid #ffe69c; color: #664d03; font-weight: 600;">
            <i class="fas fa-exclamation-triangle"></i> {{ session('complete_profile_required') }}
        </div>
    @endif

    @if ($errors->any())
        <div style="margin-bottom: 20px; padding: 14px 16px; border-radius: 8px; background: #f8d7da; border: 1px solid #f1aeb5; color: #842029;">
            <i class="fas fa-times-circle"></i>
            {{ $errors->first() }}
        </div>
    @endif

    @if($requiresProfileSetup)
        <div class="profile-section" style="margin-bottom: 24px; border: none;">
            <h2><i class="fas fa-user-shield"></i> Complete Profile Setup</h2>
            <p style="margin-bottom: 20px; color: #666;">Set your Date of Birth and a 5-digit PIN to continue to dashboard and all other pages.</p>

            <form method="POST" action="{{ route('profile.update') }}" id="profileSetupForm">
                @csrf
                <div style="display: grid; grid-template-columns: 1fr; gap: 20px; max-width: 520px;">
                    <div>
                        <label for="setup_dob" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Date of Birth</label>
                        <input
                            type="date"
                            id="setup_dob"
                            name="dob"
                            value="{{ old('dob', auth()->user()->dob ? auth()->user()->dob->format('Y-m-d') : '') }}"
                            required
                            style="width: 100%; padding: 12px; border: 1px solid #b3b3b3; border-radius: 6px;"
                        >
                    </div>

                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Set 5-digit PIN</label>
                        <div class="pin-row" data-target="pin"></div>
                        <input type="hidden" name="pin" id="pin" value="{{ old('pin') }}">
                        <div class="pin-help-text">Enter exactly 5 digits</div>
                    </div>

                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Confirm 5-digit PIN</label>
                        <div class="pin-row" data-target="pin_confirmation"></div>
                        <input type="hidden" name="pin_confirmation" id="pin_confirmation" value="{{ old('pin_confirmation') }}">
                        <div class="pin-help-text">Re-enter the same 5 digits</div>
                    </div>
                </div>

                <button type="submit" style="margin-top: 20px; padding: 12px 24px; background: #000000; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-save"></i> Save PIN & DOB
                </button>
            </form>
        </div>
    @else

    <!-- Banner Background -->
    <div style="background: #111111; height: 200px; margin-bottom: 50px; border-radius: 10px; position: relative;"></div>

    <!-- Profile Header -->
    <div style="display: grid; grid-template-columns: auto 1fr auto; gap: 30px; align-items: start; margin-bottom: 40px;">
        <!-- Profile Picture and Name -->
        <div style="text-align: center; margin-top: -10px;">
            <div style="width: 150px; height: 150px; background: #000000; border-radius: 50%; border: 5px solid white; box-shadow: 0 5px 15px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; font-size: 60px; color: white; margin: 0 auto;">
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
                    <p style="font-weight: 600; color: #2f2f2f;"><i class="fas fa-check-circle"></i> Active</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="{{ route('settings') }}" class="profile-btn" style="padding: 12px 24px; background: #000000; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; text-align: center; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
            <button type="button" onclick="submitLogoutForm()" class="profile-btn" style="padding: 12px 24px; background: #d1d1d1; color: #333; border: 1px solid #a8a8a8; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.3s ease;" onmouseover="this.style.background='#b6b6b6'" onmouseout="this.style.background='#d1d1d1'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </div>

    <style>
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            border: 1px solid #b3b3b3;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
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
            background: #f3f3f3;
            border-radius: 8px;
            border: 1px solid #b3b3b3;
            text-align: center;
        }

        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #111111;
            margin-bottom: 5px;
        }

        .stat-card .label {
            color: #666;
            font-size: 13px;
            text-transform: uppercase;
        }

        .activity-item {
            padding: 15px;
            border-left: 3px solid #666666;
            background: #f3f3f3;
            margin-bottom: 10px;
            border-radius: 4px;
            border: 1px solid #b3b3b3;
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
                <p style="font-size: 16px; color: #2f2f2f; font-weight: 500;"><i class="fas fa-check-circle"></i> Active & Verified</p>
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
            <a href="{{ route('settings') }}" class="profile-btn" style="padding: 10px 20px; background: #000000; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
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
            <div style="padding: 20px; background: #f3f3f3; border: 1px solid #b3b3b3; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-lock"></i> Password</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Last changed March 25, 2026</p>
                <a href="{{ route('settings') }}" style="color: #111111; text-decoration: none; font-weight: 600;">Change Password</a>
            </div>

            <div style="padding: 20px; background: #f3f3f3; border: 1px solid #b3b3b3; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-shield-alt"></i> Two-Factor Auth</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Not enabled</p>
                <a href="{{ route('settings') }}" style="color: #111111; text-decoration: none; font-weight: 600;">Enable 2FA</a>
            </div>

            <div style="padding: 20px; background: #f3f3f3; border: 1px solid #b3b3b3; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-key"></i> API Keys</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">2 active keys</p>
                <a href="{{ route('settings') }}" style="color: #111111; text-decoration: none; font-weight: 600;">Manage Keys</a>
            </div>

            <div style="padding: 20px; background: #f3f3f3; border: 1px solid #b3b3b3; border-radius: 8px;">
                <h3 style="font-weight: 600; color: #333; margin-bottom: 10px;"><i class="fas fa-laptop"></i> Sessions</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">1 active session</p>
                <a href="{{ route('settings') }}" style="color: #111111; text-decoration: none; font-weight: 600;">View Sessions</a>
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
            <a href="{{ route('settings') }}" class="profile-btn" style="padding: 10px 20px; background: #000000; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
                <i class="fas fa-sliders-h"></i> Change Preferences
            </a>
        </div>
    </div>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const rows = document.querySelectorAll('.pin-row');

        rows.forEach((row) => {
            const targetId = row.getAttribute('data-target');
            const hiddenInput = document.getElementById(targetId);

            for (let i = 0; i < 5; i++) {
                const input = document.createElement('input');
                input.type = 'password';
                input.inputMode = 'numeric';
                input.maxLength = 1;
                input.className = 'pin-box';
                input.autocomplete = 'off';

                input.addEventListener('paste', function (event) {
                    event.preventDefault();
                    const pasted = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 5);
                    const boxes = row.querySelectorAll('.pin-box');
                    pasted.split('').forEach((digit, index) => {
                        if (boxes[index]) {
                            boxes[index].value = digit;
                        }
                    });
                    syncPinValue(row, hiddenInput);
                    const nextIndex = Math.min(pasted.length, 4);
                    if (boxes[nextIndex]) {
                        boxes[nextIndex].focus();
                    }
                });

                input.addEventListener('input', function () {
                    this.value = this.value.replace(/\D/g, '').slice(0, 1);
                    syncPinValue(row, hiddenInput);

                    if (this.value && this.nextElementSibling) {
                        this.nextElementSibling.focus();
                    }
                });

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Backspace' && !this.value && this.previousElementSibling) {
                        this.previousElementSibling.focus();
                    }
                });

                row.appendChild(input);
            }
        });

        function syncPinValue(row, hiddenInput) {
            const value = Array.from(row.querySelectorAll('.pin-box')).map((box) => box.value).join('');
            hiddenInput.value = value;
        }
    });
</script>
@endsection
