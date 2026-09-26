@extends('app')

@section('title', 'Profile - ' . $brandName)

@section('dashboard-content')
@php
    $requiresProfileSetup = !auth()->user()->dob || !auth()->user()->pin;
@endphp
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
            <x-user-avatar :user="auth()->user()" size="150" style="border: 5px solid white; box-shadow: 0 5px 15px rgba(0,0,0,0.2);" />
        </div>

        @php
            $profileUser = auth()->user();
            $fullName = trim(($profileUser->first_name ?? '') . ' ' . ($profileUser->last_name ?? ''));
            $isActiveUser = strtolower((string) $profileUser->status) === 'active';
        @endphp
        <!-- Info -->
        <div>
            <h1 style="font-size: 32px; font-weight: bold; color: #333; margin-bottom: 5px;">{{ $fullName !== '' ? $fullName : $profileUser->name }}</h1>
            <p style="color: #666; font-size: 16px; margin-bottom: 20px;">{{ $profileUser->email }}</p>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div>
                    <p style="color: #999; font-size: 12px; margin-bottom: 5px;">Member Since</p>
                    <p style="font-weight: 600; color: #333;">{{ $profileUser->created_at ? \App\Support\UserPreferences::date($profileUser->created_at) : 'Unknown' }}</p>
                </div>
                <div>
                    <p style="color: #999; font-size: 12px; margin-bottom: 5px;">Role</p>
                    <p style="font-weight: 600; color: #333;">{{ $roleLabel }}</p>
                </div>
                <div>
                    <p style="color: #999; font-size: 12px; margin-bottom: 5px;">Status</p>
                    <p style="font-weight: 600; color: #2f2f2f;"><i class="fas {{ $isActiveUser ? 'fa-check-circle' : 'fa-ban' }}"></i> {{ ucfirst($profileUser->status ?? 'unknown') }}</p>
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
            display: block;
            padding: 20px;
            background: #f3f3f3;
            border-radius: 8px;
            border: 1px solid #b3b3b3;
            text-align: center;
            color: inherit;
            text-decoration: none;
            transition: border-color 0.2s ease, transform 0.2s ease;
        }

        a.stat-card:hover {
            border-color: #111111;
            transform: translateY(-2px);
        }

        .stat-card .number-text {
            font-size: 22px;
            line-height: 38px;
        }

        .stat-card .hint {
            color: #888;
            font-size: 12px;
            margin-top: 4px;
        }

        .overview-scope {
            font-size: 12px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
        }

        .overview-empty {
            margin-bottom: 14px;
            padding: 12px 14px;
            border: 1px dashed #b3b3b3;
            border-radius: 8px;
            color: #444;
            font-size: 14px;
        }

        .security-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .security-card {
            padding: 20px;
            background: #f3f3f3;
            border: 1px solid #b3b3b3;
            border-radius: 8px;
        }

        .security-card h3 {
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .security-card p {
            color: #555;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .security-card .security-detail {
            color: #888;
            font-size: 12px;
        }

        .security-card a {
            display: inline-block;
            margin-top: 10px;
            color: #111111;
            text-decoration: none;
            font-weight: 600;
        }

        .security-card.is-muted {
            opacity: 0.7;
        }

        .overview-empty a {
            color: #111111;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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


    </style>

    <!-- Overview Stats -->
    @php
        $mine = $overview['mine'];
        $scope = $overview['scope'];
        $workspacesRoute = (int) $profileUser->rbac_id === 100 ? route('enterprise.console') : route('admin.workspaces');
    @endphp
    <div class="profile-section">
        <h2><i class="fas fa-chart-bar"></i> Overview</h2>

        <p class="overview-scope">Your usage</p>
        @if($mine['systems_active'] + $mine['systems_inactive'] === 0 && $mine['versions'] === 0)
            <div class="overview-empty">
                No systems registered yet. Create an API key in <a href="{{ route('settings') }}#api">Settings</a>, then connect the <code>atglance</code> CLI on your server.
            </div>
        @endif
        <div class="stat-grid">
            <a class="stat-card" href="{{ route('systems-registered') }}">
                <div class="number">{{ number_format($mine['systems_active']) }}</div>
                <div class="label">Systems registered</div>
                @if($mine['systems_inactive'] > 0)
                    <div class="hint">{{ number_format($mine['systems_inactive']) }} inactive</div>
                @endif
            </a>
            <a class="stat-card" href="{{ route('configuration-backups') }}">
                <div class="number">{{ number_format($mine['files']) }}</div>
                <div class="label">Configuration files</div>
                <div class="hint">{{ number_format($mine['versions']) }} {{ \Illuminate\Support\Str::plural('version', $mine['versions']) }}</div>
            </a>
            <a class="stat-card" href="{{ route('configuration-backups') }}">
                <div class="number number-text">{{ $mine['latest_backup'] ? $mine['latest_backup']->diffForHumans() : 'Never' }}</div>
                <div class="label">Latest backup</div>
                @if($mine['latest_backup'])
                    <div class="hint">{{ \App\Support\UserPreferences::datetime($mine['latest_backup']) }}</div>
                @endif
            </a>
            <a class="stat-card" href="{{ route('settings') }}#api">
                <div class="number">{{ number_format($mine['api_keys']) }}</div>
                <div class="label">Active API keys</div>
            </a>
        </div>

        @if($scope)
            <p class="overview-scope" style="margin-top: 24px;">{{ $scope['label'] }}</p>
            <p style="color: #666; font-size: 13px; margin: -6px 0 12px;">{{ $scope['description'] }}</p>
            <div class="stat-grid">
                <a class="stat-card" href="{{ $workspacesRoute }}">
                    <div class="number">{{ number_format($scope['workspaces']) }}</div>
                    <div class="label">{{ \Illuminate\Support\Str::plural('Workspace', $scope['workspaces']) }}</div>
                </a>
                <a class="stat-card" href="{{ route('admin.users') }}">
                    <div class="number">{{ number_format($scope['users']) }}</div>
                    <div class="label">Users</div>
                </a>
                <a class="stat-card" href="{{ route('systems-registered') }}">
                    <div class="number">{{ number_format($scope['systems_active']) }}</div>
                    <div class="label">Active systems</div>
                </a>
                <a class="stat-card" href="{{ route('configuration-backups') }}">
                    <div class="number">{{ number_format($scope['files']) }}</div>
                    <div class="label">Configuration files</div>
                    <div class="hint">
                        {{ number_format($scope['versions']) }} {{ \Illuminate\Support\Str::plural('version', $scope['versions']) }}@if($scope['latest_backup']), latest {{ $scope['latest_backup']->diffForHumans() }}@endif
                    </div>
                </a>
            </div>
        @endif
    </div>

    <!-- Account Information -->
    <div class="profile-section">
        <h2><i class="fas fa-user-circle"></i> Account Information</h2>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 20px;">
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Name</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $fullName !== '' ? $fullName : 'Not specified' }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Username</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $profileUser->name }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Email Address</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $profileUser->email }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Account Status</p>
                <p style="font-size: 16px; color: #2f2f2f; font-weight: 500;"><i class="fas {{ $isActiveUser ? 'fa-check-circle' : 'fa-ban' }}"></i> {{ ucfirst($profileUser->status ?? 'unknown') }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Role</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $roleLabel }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Joined</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $profileUser->created_at ? \App\Support\UserPreferences::date($profileUser->created_at) : 'Unknown' }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Phone</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $profileUser->phone ?: 'Not specified' }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Last Sign-in</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $security['last_login_at'] ? \App\Support\UserPreferences::datetime($security['last_login_at']) : 'Recorded from your next sign-in' }}</p>
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
        <h2 style="justify-content: space-between;">
            <span style="display: flex; align-items: center; gap: 10px;"><i class="fas fa-history"></i> Recent Activity</span>
            <a href="{{ route('profile.activity') }}" style="font-size: 14px; font-weight: 600; color: #111111;">View all</a>
        </h2>
        @include('partials.activity-list', ['activityItems' => $recentActivity])
    </div>

    <!-- Security & Privacy -->
    <div class="profile-section">
        <h2><i class="fas fa-shield-alt"></i> Security & Privacy</h2>

        <div class="security-grid">
            <div class="security-card">
                <h3><i class="fas fa-lock"></i> Password</h3>
                @if($security['password_changed_at'])
                    <p>Last changed {{ $security['password_changed_at']->diffForHumans() }}</p>
                    <p class="security-detail">{{ \App\Support\UserPreferences::date($security['password_changed_at']) }}</p>
                @elseif($security['sso_provider'])
                    <p>You sign in with {{ $security['sso_provider'] }}. No password has been set here.</p>
                @else
                    <p>Not recorded yet</p>
                @endif
                <a href="{{ route('settings') }}#security">Change password</a>
            </div>

            <div class="security-card">
                <h3><i class="fas fa-sign-in-alt"></i> Sign-ins</h3>
                @if($security['last_login_at'])
                    <p>Last sign-in {{ $security['last_login_at']->diffForHumans() }}{{ $security['last_login_ip'] ? ' from ' . $security['last_login_ip'] : '' }}</p>
                    @if($security['previous_login_at'])
                        <p class="security-detail">Before that: {{ \App\Support\UserPreferences::datetime($security['previous_login_at']) }}{{ $security['previous_login_ip'] ? ' from ' . $security['previous_login_ip'] : '' }}</p>
                    @endif
                @else
                    <p>Recorded from your next sign-in</p>
                @endif
                <a href="{{ route('profile.activity', ['type' => 'signin']) }}">View sign-in history</a>
            </div>

            <div class="security-card">
                <h3><i class="fas fa-key"></i> API Keys</h3>
                <p>{{ $security['api_keys'] }} active {{ \Illuminate\Support\Str::plural('key', $security['api_keys']) }}</p>
                <p class="security-detail">{{ $security['api_key_last_used'] ? 'Last used ' . $security['api_key_last_used']->diffForHumans() : 'Not used yet' }}</p>
                <a href="{{ route('settings') }}#api">Manage keys</a>
            </div>

            <div class="security-card">
                <h3><i class="fas fa-laptop"></i> Sessions</h3>
                @if($security['sessions'] !== null)
                    <p>{{ $security['sessions'] }} active {{ \Illuminate\Support\Str::plural('session', $security['sessions']) }}</p>
                    <p class="security-detail">Including this browser</p>
                @else
                    <p>Session list not available</p>
                @endif
                <a href="{{ route('settings') }}#security">View sessions</a>
            </div>

            <div class="security-card is-muted">
                <h3><i class="fas fa-mobile-alt"></i> Two-Factor Auth</h3>
                <p>Coming soon</p>
            </div>
        </div>
    </div>

    <!-- Preferences -->
    @php
        $prefs = \App\Support\UserPreferences::all($profileUser);
        $enabledAlerts = collect(\App\Support\UserPreferences::ALERTS)
            ->filter(fn ($meta, $key) => $prefs['alerts'][$key])
            ->map(fn ($meta) => $meta[0])
            ->values();
    @endphp
    <div class="profile-section">
        <h2><i class="fas fa-sliders-h"></i> Preferences</h2>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Time zone</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $prefs['timezone'] ? str_replace('_', ' ', $prefs['timezone']) : 'Server default (' . config('app.timezone') . ')' }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Date format</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ \App\Support\UserPreferences::DATE_FORMATS[$prefs['date_format']][2] }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Rows per page</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $prefs['per_page'] }}</p>
            </div>
            <div>
                <p style="color: #999; font-size: 12px; text-transform: uppercase; margin-bottom: 8px;">Email alerts</p>
                <p style="font-size: 16px; color: #333; font-weight: 500;">{{ $enabledAlerts->implode(', ') }}</p>
                @unless(\App\Support\NotificationSettings::mailConfigured())
                    <p style="color: #92400e; font-size: 12px; margin-top: 4px;">Email is not set up on this server, so alerts are not sent yet.</p>
                @endunless
            </div>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <a href="{{ route('settings') }}#preferences" class="profile-btn" style="padding: 10px 20px; background: #000000; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">
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
