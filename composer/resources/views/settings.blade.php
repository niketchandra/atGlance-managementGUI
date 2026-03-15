@extends('app')

@section('title', 'Settings - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <!-- Page Header -->
    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 32px; font-weight: bold; color: #333; margin-bottom: 10px;">Settings</h1>
        <p style="color: #666;">Configure your account and application preferences</p>
    </div>

    <!-- Settings Tabs -->
    <div style="display: flex; gap: 10px; border-bottom: 2px solid #e0e0e0; margin-bottom: 30px;">
        <button class="settings-tab active" onclick="switchSettingsTab('account', this)">
            <i class="fas fa-user-cog"></i> Account
        </button>
        <button class="settings-tab" onclick="switchSettingsTab('security', this)">
            <i class="fas fa-shield-alt"></i> Security
        </button>
        <button class="settings-tab" onclick="switchSettingsTab('notifications', this)">
            <i class="fas fa-bell"></i> Notifications
        </button>
        <button class="settings-tab" onclick="switchSettingsTab('billing', this)">
            <i class="fas fa-credit-card"></i> Billing
        </button>
        <button class="settings-tab" onclick="switchSettingsTab('api', this)">
            <i class="fas fa-plug"></i> API Keys
        </button>
    </div>

    <style>
        .settings-tab {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #999;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.3s ease;
        }

        .settings-tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .settings-content {
            display: none;
        }

        .settings-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .settings-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .settings-form-group {
            margin-bottom: 20px;
        }

        .settings-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .settings-form-group input,
        .settings-form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .settings-form-group input:focus,
        .settings-form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .toggle {
            position: relative;
            width: 50px;
            height: 30px;
            background: #ccc;
            border-radius: 15px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .toggle.active {
            background: #667eea;
        }

        .toggle::after {
            content: '';
            position: absolute;
            width: 26px;
            height: 26px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.3s ease;
        }

        .toggle.active::after {
            left: 22px;
        }

        .btn-save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .btn-save:hover {
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-left: 10px;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .api-key-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .api-key-display {
            font-family: 'Courier New', monospace;
            color: #333;
            font-size: 13px;
            background: white;
            padding: 10px;
            border-radius: 4px;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
    </style>

    <!-- ACCOUNT SETTINGS -->
    <div class="settings-content active" id="account">
        <div class="settings-card">
            <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 25px;"><i class="fas fa-user"></i> Account Information</h2>

            <form method="POST" action="{{ route('settings.update') }}">
                @csrf
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="settings-form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="{{ auth()->user()->first_name }}" placeholder="John">
                    </div>

                    <div class="settings-form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="{{ auth()->user()->last_name }}" placeholder="Doe">
                    </div>

                    <div class="settings-form-group">
                        <label for="dob">Date of Birth</label>
                        <input type="date" id="dob" name="dob" value="{{ auth()->user()->dob ? auth()->user()->dob->format('Y-m-d') : '' }}">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="settings-form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="{{ auth()->user()->name }}" readonly>
                    </div>

                    <div class="settings-form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="{{ auth()->user()->email }}" readonly>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="settings-form-group">
                        <label for="organization_name">Organisation Name</label>
                        <input type="text" id="organization_name" name="organization_name" value="{{ auth()->user()->organization?->name ?? 'N/A' }}" readonly>
                    </div>

                    <div class="settings-form-group">
                        <label for="role_name">Role</label>
                        <input type="text" id="role_name" name="role_name" value="{{ auth()->user()->rbac?->role_name ? ucfirst(str_replace('_', ' ', auth()->user()->rbac->role_name)) : 'N/A' }}" readonly>
                    </div>
                </div>

                <div style="margin-top: 30px; display: flex; gap: 10px; align-items: center;">
                    <button type="submit" class="btn-save" style="min-width: 160px;"><i class="fas fa-save"></i> Save Changes</button>
                    <button type="reset" class="btn-secondary" style="margin: 0; min-width: 160px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SECURITY SETTINGS -->
    <div class="settings-content" id="security">
        <div class="settings-card">
            <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 25px;"><i class="fas fa-shield-alt"></i> Security Settings</h2>

            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 15px;">Change Password</h3>
                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <div class="settings-form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" placeholder="Enter your current password">
                    </div>

                    <div class="settings-form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="password" placeholder="Enter new password (min 8 characters)">
                    </div>

                    <div class="settings-form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="password_confirmation" placeholder="Confirm new password">
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-lock"></i> Update Password</button>
                </form>
            </div>

            <div style="border-top: 1px solid #e0e0e0; padding-top: 30px;">
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 15px;">Two-Factor Authentication</h3>
                <div class="toggle-switch">
                    <div>
                        <strong>Enable 2FA</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Enhance your account security with two-factor authentication</p>
                    </div>
                    <div class="toggle" onclick="this.classList.toggle('active')"></div>
                </div>
            </div>

            <div style="border-top: 1px solid #e0e0e0; padding-top: 30px; margin-top: 30px;">
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 15px;">Active Sessions</h3>
                <div class="api-key-item">
                    <div>
                        <strong>Current Session</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Chrome on Windows • Last active 2 minutes ago</p>
                    </div>
                    <span class="status-badge status-active">Active</span>
                </div>
            </div>
        </div>
    </div>

    <!-- NOTIFICATIONS -->
    <div class="settings-content" id="notifications">
        <div class="settings-card">
            <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 25px;"><i class="fas fa-bell"></i> Notification Settings</h2>

            <form>
                <div class="toggle-switch">
                    <div>
                        <strong>Email Notifications</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Receive important alerts via email</p>
                    </div>
                    <div class="toggle active" onclick="this.classList.toggle('active')"></div>
                </div>

                <div class="toggle-switch">
                    <div>
                        <strong>API Failures Alert</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Get notified when your APIs fail</p>
                    </div>
                    <div class="toggle active" onclick="this.classList.toggle('active')"></div>
                </div>

                <div class="toggle-switch">
                    <div>
                        <strong>High Response Time Alert</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Alert when response time exceeds threshold</p>
                    </div>
                    <div class="toggle active" onclick="this.classList.toggle('active')"></div>
                </div>

                <div class="toggle-switch">
                    <div>
                        <strong>Weekly Summary</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Receive weekly performance summary</p>
                    </div>
                    <div class="toggle" onclick="this.classList.toggle('active')"></div>
                </div>

                <div style="margin-top: 30px;">
                    <button type="button" class="btn-save"><i class="fas fa-save"></i> Save Preferences</button>
                </div>
            </form>
        </div>
    </div>

    <!-- BILLING -->
    <div class="settings-content" id="billing">
        <div class="settings-card">
            <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 25px;"><i class="fas fa-credit-card"></i> Billing & Subscription</h2>

            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 8px; margin-bottom: 25px;">
                <h3 style="font-size: 16px; margin-bottom: 10px;">Current Plan</h3>
                <p style="font-size: 28px; font-weight: bold; margin-bottom: 10px;">Professional</p>
                <p style="opacity: 0.9;">$49/month • Renewal on March 15, 2026</p>
            </div>

            <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 15px;">Payment Method</h3>
            <div style="background: white; padding: 15px; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong><i class="fas fa-credit-card"></i> Visa ending in 4242</strong>
                        <p style="color: #666; font-size: 13px; margin-top: 5px;">Expires 12/2028</p>
                    </div>
                    <button type="button" class="btn-secondary">Update</button>
                </div>
            </div>

            <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 15px;">Recent Invoices</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                        <th style="padding: 12px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Date</th>
                        <th style="padding: 12px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Amount</th>
                        <th style="padding: 12px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Status</th>
                        <th style="padding: 12px; text-align: left; color: #666; font-weight: 600; font-size: 12px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px;">February 15, 2026</td>
                        <td style="padding: 12px;">$49.00</td>
                        <td style="padding: 12px;"><span class="status-badge status-active">Paid</span></td>
                        <td style="padding: 12px;"><a href="#" style="color: #667eea;">Download</a></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px;">January 15, 2026</td>
                        <td style="padding: 12px;">$49.00</td>
                        <td style="padding: 12px;"><span class="status-badge status-active">Paid</span></td>
                        <td style="padding: 12px;"><a href="#" style="color: #667eea;">Download</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- API KEYS -->
    <div class="settings-content" id="api">
        <div class="settings-card">
            <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 25px;"><i class="fas fa-plug"></i> API Keys</h2>

            <div style="margin-bottom: 25px;">
                <button type="button" class="btn-save" onclick="openCreateKeyModal()"><i class="fas fa-plus"></i> Create New Key</button>
            </div>

            <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 15px;">Your API Keys</h3>
            <div id="apiKeysList">
                @forelse($apiKeys as $apiKey)
                    <div class="api-key-item" id="key-{{ $apiKey->id }}">
                        <div style="flex: 1;">
                            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                                <strong>{{ $apiKey->name }}</strong>
                                <span class="status-badge {{ $apiKey->status === 'active' ? 'status-active' : 'status-inactive' }}">
                                    {{ ucfirst($apiKey->status) }}
                                </span>
                            </div>
                            <div id="token-display-{{ $apiKey->id }}" class="api-key-display">{{ substr($apiKey->token, 0, 16) }}...</div>
                            <div style="display: flex; gap: 10px; margin-top: 8px; font-size: 12px; color: #666;">
                                <span><i class="fas fa-calendar"></i> Created {{ $apiKey->created_at?->format('F j, Y') }}</span>
                                @if($apiKey->expires_at)
                                    <span><i class="fas fa-hourglass-end"></i> Expires {{ $apiKey->expires_at->format('F j, Y') }}</span>
                                @else
                                    <span><i class="fas fa-infinity"></i> No expiry</span>
                                @endif
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="btn-secondary" onclick="openPasswordModal('view', {{ $apiKey->id }}, '{{ $apiKey->name }}')" style="margin: 0;">View</button>
                            <button type="button" class="btn-secondary" onclick="openPasswordModal('revoke', {{ $apiKey->id }}, '{{ $apiKey->name }}')" style="margin: 0; background: #f8d7da; color: #721c24;">Revoke</button>
                        </div>
                    </div>
                @empty
                    <div class="api-key-item">
                        <div style="flex: 1; color: #666;">No API keys found.</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- CREATE KEY MODAL -->
    <div id="createKeyModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 10px; padding: 30px; width: 90%; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 20px; font-weight: bold; margin: 0;">Create New API Key</h2>
                <button type="button" onclick="closeCreateKeyModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">&times;</button>
            </div>

            <form id="createKeyForm" method="POST" action="{{ route('settings.api-keys.create') }}" onsubmit="handleCreateKeySubmit(event)">
                @csrf
                <div class="settings-form-group">
                    <label for="key_name">Key Name</label>
                    <input type="text" id="key_name" name="name" placeholder="e.g., Production, Development" required>
                </div>

                <div class="settings-form-group">
                    <label for="expiration_type">Expiration</label>
                    <select id="expiration_type" name="expiration_type" onchange="toggleCustomDate()" required>
                        <option value="">Select expiration...</option>
                        <option value="1_month">1 Month</option>
                        <option value="3_months">3 Months</option>
                        <option value="6_months">6 Months</option>
                        <option value="12_months">12 Months</option>
                        <option value="no_expiry">No Expiry</option>
                        <option value="custom">Custom Date</option>
                    </select>
                </div>

                <div class="settings-form-group" id="customDateGroup" style="display: none;">
                    <label for="custom_expiry_date">Expiration Date</label>
                    <input type="date" id="custom_expiry_date" name="custom_expiry_date">
                </div>

                <div style="margin-top: 25px; display: flex; gap: 10px;">
                    <button type="submit" class="btn-save"><i class="fas fa-plus"></i> Create Key</button>
                    <button type="button" class="btn-secondary" onclick="closeCreateKeyModal()" style="margin: 0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PASSWORD CONFIRMATION MODAL -->
    <div id="passwordModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 10px; padding: 30px; width: 90%; max-width: 450px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 id="passwordModalTitle" style="font-size: 20px; font-weight: bold; margin: 0;">Confirm Password</h2>
                <button type="button" onclick="closePasswordModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">&times;</button>
            </div>

            <p id="passwordModalDesc" style="color: #666; margin-bottom: 20px;">Enter your password to continue.</p>

            <form id="passwordForm" onsubmit="handlePasswordSubmit(event)">
                @csrf
                <input type="hidden" id="passwordAction" name="action" value="">
                <input type="hidden" id="passwordKeyId" name="key_id" value="">

                <div class="settings-form-group">
                    <label for="api_key_password">Password</label>
                    <input type="password" id="api_key_password" name="password" placeholder="Enter your password" required autofocus>
                </div>

                <div id="viewKeyContent" style="display: none; margin-top: 15px; margin-bottom: 20px;">
                    <p style="color: #999; font-size: 12px; margin-bottom: 8px;">API Key:</p>
                    <div class="api-key-display" id="fullKeyDisplay" style="word-break: break-all;"></div>
                </div>

                <div style="margin-top: 25px; display: flex; gap: 10px;">
                    <button type="submit" id="passwordSubmitBtn" class="btn-save"><i class="fas fa-check"></i> Confirm</button>
                    <button type="button" class="btn-secondary" onclick="closePasswordModal()" style="margin: 0;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- NEW TOKEN DISPLAY ALERT -->
    <div id="newTokenAlert" style="display: none; position: fixed; top: 20px; right: 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); z-index: 1002; max-width: 400px;">
        <div style="display: flex; justify-content: space-between; align-items: start; gap: 15px;">
            <div>
                <h4 style="margin: 0 0 10px 0;"><i class="fas fa-check-circle"></i> API Key Created!</h4>
                <p style="margin: 0 0 10px 0; font-size: 13px;">Copy your new API key below. You won't be able to see it again.</p>
                <div class="api-key-display" id="newTokenValue" style="margin-bottom: 10px;"></div>
                <button type="button" onclick="copyToClipboard(document.getElementById('newTokenValue').textContent)" class="btn-save" style="padding: 8px 16px; font-size: 13px;"><i class="fas fa-copy"></i> Copy</button>
            </div>
            <button type="button" onclick="closeNewTokenAlert()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #155724; padding: 0;">&times;</button>
        </div>
    </div>

</div>

<script>
    let currentAction = '';
    let currentKeyId = '';
    let currentKeyName = '';

    function switchSettingsTab(tabName, buttonEl) {
        document.querySelectorAll('.settings-content').forEach(tab => {
            tab.classList.remove('active');
        });

        document.querySelectorAll('.settings-tab').forEach(btn => {
            btn.classList.remove('active');
        });

        const targetTab = document.getElementById(tabName);
        if (targetTab) {
            targetTab.classList.add('active');
        }

        if (buttonEl) {
            buttonEl.classList.add('active');
        }
    }

    function openCreateKeyModal() {
        document.getElementById('createKeyModal').style.display = 'flex';
        document.getElementById('key_name').focus();
    }

    function closeCreateKeyModal() {
        document.getElementById('createKeyModal').style.display = 'none';
        document.getElementById('createKeyForm').reset();
        document.getElementById('customDateGroup').style.display = 'none';
    }

    function toggleCustomDate() {
        const value = document.getElementById('expiration_type').value;
        document.getElementById('customDateGroup').style.display = value === 'custom' ? 'block' : 'none';
        if (value === 'custom') {
            document.getElementById('custom_expiry_date').focus();
        }
    }

    function openPasswordModal(action, keyId, keyName) {
        currentAction = action;
        currentKeyId = keyId;
        currentKeyName = keyName;

        const modalTitle = action === 'view' ? 'View API Key' : 'Revoke API Key';
        const modalDesc = action === 'view' 
            ? 'Enter your password to view this API key.'
            : 'Enter your password to revoke this API key. This action cannot be undone.';
        const btnText = action === 'view' ? 'View Key' : 'Revoke Key';
        const btnClass = action === 'view' ? 'btn-save' : 'btn-secondary';
        const btnStyle = action === 'view' ? '' : 'background: #f8d7da; color: #721c24;';

        document.getElementById('passwordModalTitle').textContent = modalTitle;
        document.getElementById('passwordModalDesc').textContent = modalDesc;
        document.getElementById('passwordSubmitBtn').textContent = btnText;
        document.getElementById('passwordSubmitBtn').className = action === 'view' ? 'btn-save' : 'btn-secondary';
        document.getElementById('passwordSubmitBtn').style.cssText = btnStyle;
        document.getElementById('viewKeyContent').style.display = action === 'view' ? 'block' : 'none';
        document.getElementById('passwordAction').value = action;
        document.getElementById('passwordKeyId').value = keyId;
        document.getElementById('api_key_password').value = '';
        document.getElementById('api_key_password').focus();
        document.getElementById('passwordModal').style.display = 'flex';
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').style.display = 'none';
        document.getElementById('passwordForm').reset();
    }

    function handleCreateKeySubmit(event) {
        event.preventDefault();
        
        const formData = new FormData(document.getElementById('createKeyForm'));
        const keyName = formData.get('name');
        const expirationType = formData.get('expiration_type');
        const customDate = formData.get('custom_expiry_date');

        // Add expiration_date field based on type
        let expirationDate = null;
        if (expirationType === '1_month') {
            const d = new Date();
            d.setMonth(d.getMonth() + 1);
            expirationDate = d.toISOString().split('T')[0];
        } else if (expirationType === '3_months') {
            const d = new Date();
            d.setMonth(d.getMonth() + 3);
            expirationDate = d.toISOString().split('T')[0];
        } else if (expirationType === '6_months') {
            const d = new Date();
            d.setMonth(d.getMonth() + 6);
            expirationDate = d.toISOString().split('T')[0];
        } else if (expirationType === '12_months') {
            const d = new Date();
            d.setFullYear(d.getFullYear() + 1);
            expirationDate = d.toISOString().split('T')[0];
        } else if (expirationType === 'custom') {
            expirationDate = customDate;
        }

        const data = new FormData();
        data.append('name', keyName);
        if (expirationDate) {
            data.append('expiration_date', expirationDate);
        }
        data.append('_token', document.querySelector('[name="_token"]').value);

        fetch('{{ route("settings.api-keys.create") }}', {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeCreateKeyModal();
                document.getElementById('newTokenValue').textContent = data.token;
                document.getElementById('newTokenAlert').style.display = 'block';
                
                // Add new key to list
                const newKeyHtml = `
                    <div class="api-key-item" id="key-${data.key_id}">
                        <div style="flex: 1;">
                            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                                <strong>${data.name}</strong>
                                <span class="status-badge status-active">Active</span>
                            </div>
                            <div id="token-display-${data.key_id}" class="api-key-display">${data.token}</div>
                            <div style="display: flex; gap: 10px; margin-top: 8px; font-size: 12px; color: #666;">
                                <span><i class="fas fa-calendar"></i> Created Today</span>
                                ${data.expires_at ? `<span><i class="fas fa-hourglass-end"></i> Expires ${data.expires_at}</span>` : '<span><i class="fas fa-infinity"></i> No expiry</span>'}
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="btn-secondary" onclick="openPasswordModal('view', ${data.key_id}, '${data.name}')" style="margin: 0;">View</button>
                            <button type="button" class="btn-secondary" onclick="openPasswordModal('revoke', ${data.key_id}, '${data.name}')" style="margin: 0; background: #f8d7da; color: #721c24;">Revoke</button>
                        </div>
                    </div>
                `;
                
                const apiKeysList = document.getElementById('apiKeysList');
                const emptyMsg = apiKeysList.querySelector('[style*="No API keys"]');
                if (emptyMsg) {
                    emptyMsg.parentElement.remove();
                }
                apiKeysList.insertAdjacentHTML('afterbegin', newKeyHtml);
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function handlePasswordSubmit(event) {
        event.preventDefault();
        
        const action = document.getElementById('passwordAction').value;
        const keyId = document.getElementById('passwordKeyId').value;
        const password = document.getElementById('api_key_password').value;

        const data = new FormData();
        data.append('password', password);
        data.append('key_id', keyId);
        data.append('_token', document.querySelector('[name="_token"]').value);

        const endpoint = action === 'view' 
            ? '{{ route("settings.api-keys.view") }}'
            : '{{ route("settings.api-keys.revoke") }}';

        fetch(endpoint, {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (action === 'view') {
                    document.getElementById('fullKeyDisplay').textContent = data.token;
                    document.getElementById('viewKeyContent').style.display = 'block';
                } else if (action === 'revoke') {
                    closePasswordModal();
                    const row = document.getElementById(`key-${keyId}`);
                    if (row) {
                        row.remove();
                    }
                }
            } else {
                alert('Error: ' + (data.message || 'Invalid password'));
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function closeNewTokenAlert() {
        document.getElementById('newTokenAlert').style.display = 'none';
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            alert('API key copied to clipboard!');
        });
    }
</script>

@endsection
