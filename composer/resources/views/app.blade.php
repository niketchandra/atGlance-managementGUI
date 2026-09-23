<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'AtGlance - Configuration Backup Service')</title>
    <link rel="icon" type="image/x-icon" href="{{ $siteFaviconUrl ?? asset('branding/favicon.ico') }}">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
        }

        .main-container {
            display: flex;
            align-items: stretch;
            min-height: 100vh;
            background: #f5f5f5;
        }

        .sidebar {
            width: 20%;
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            min-height: 100%;
            background: #ffffff;
            box-shadow: 2px 0 4px rgba(0, 0, 0, 0.06);
            border-right: 1px solid #b3b3b3;
        }

        .content {
            width: 80%;
            padding: 0;
            min-height: 100vh;
            background: #ffffff;
            display: flex;
            flex-direction: column;
        }

        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            font-weight: bold;
            color: #1a1a1a;
            margin-bottom: 40px;
            text-align: center;
        }

        .sidebar-logo i {
            margin-right: 8px;
        }

        .form-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #b3b3b3;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #999999;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            margin-bottom: -2px;
        }

        .tab-btn.active {
            color: #000000;
            border-bottom-color: #000000;
        }

        .tab-btn:disabled {
            color: #b8b8b8;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
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

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 1px solid #b3b3b3;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #000000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-primary {
            background: #000000;
            color: white;
        }

        .btn-primary:hover {
            background: #333333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        }

        .btn-secondary {
            background: #d1d1d1;
            color: #333333;
            border: 1px solid #a8a8a8;
        }

        .btn-secondary:hover {
            background: #999999;
            color: white;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #333333;
            color: white;
        }

        .btn-danger:hover {
            background: #1a1a1a;
            transform: translateY(-2px);
        }

        /* Global button hover color across app pages */
        button:hover,
        .btn:hover,
        .btn-primary:hover,
        .btn-secondary:hover,
        .btn-danger:hover,
        .btn-save:hover,
        .action-btn:hover,
        .filter-btn:hover,
        .settings-tab-btn:hover,
        .profile-btn:hover,
        .admin-action-btn:hover,
        a[style*='cursor: pointer']:hover {
            background: #555555 !important;
            border-color: #555555 !important;
            color: #ffffff !important;
        }

        .sso-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-top: 12px;
        }

        .btn-sso {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            text-transform: none;
            letter-spacing: normal;
        }

        .divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
            color: #7a7a7a;
            font-size: 13px;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #bdbdbd;
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            margin-top: 10px;
        }

        .remember-forgot a {
            color: #000000;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .remember-forgot a:hover {
            color: #333333;
        }

        .header {
            background: white;
            border-bottom: 1px solid #b3b3b3;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
        }

        .header-logo {
            font-size: 28px;
            font-weight: bold;
            color: #000000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 0;
        }

        .header-logo i {
            margin-right: 8px;
            color: #000000;
        }

        .header-nav {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .header-nav a {
            color: #333;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            font-size: 14px;
        }

        .header-nav a:hover {
            color: #000000;
            font-weight: 600;
        }

        .public-header {
            flex-direction: row;
            gap: 20px;
            align-items: center;
            justify-content: flex-start;
            padding: 20px 40px;
        }

        .public-header .header-nav {
            display: none;
        }

        .header-right {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .workspace-switcher {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .workspace-switcher select {
            min-width: 220px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            color: #111827;
            background: #ffffff;
        }

.welcome-section {
            padding: 60px 40px;
            text-align: center;
            background: #ffffff;
        }

        .welcome-subtitle {
            font-size: 18px;
            color: #666;
            margin-bottom: 40px;
            line-height: 1.6;
        }

        .features-section {
            padding: 60px 40px;
            background: #f7f7f7;
        }

        .section-title {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin-bottom: 40px;
            text-align: center;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-bottom: 60px;
        }

        .feature-card {
            padding: 30px;
            background: #ffffff;
            border-radius: 10px;
            border: 1px solid #b3b3b3;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-align: center;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .feature-icon {
            font-size: 40px;
            color: #333333;
            margin-bottom: 15px;
        }

        .feature-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .feature-desc {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        .screenshots-section {
            padding: 60px 40px;
            background: #f1f1f1;
        }

        .screenshot-placeholder {
            width: 100%;
            height: 300px;
            background: #d0d0d0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333333;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .contact-section {
            padding: 60px 40px;
        }

        .contact-form {
            max-width: 600px;
            margin: 0 auto;
        }

        .contact-form .form-group {
            margin-bottom: 20px;
        }

        .contact-form textarea {
            padding: 12px;
            border: 1px solid #b3b3b3;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
        }

        .contact-form textarea:focus {
            outline: none;
            border-color: #000000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            font-size: 14px;
        }

        .alert-success {
            background: #f0f0f0;
            color: #333333;
            border: 1px solid #b3b3b3;
        }

        .alert-error {
            background: #e8e8e8;
            color: #1a1a1a;
            border: 1px solid #b3b3b3;
        }

        .alert i {
            font-size: 16px;
        }

        .hidden {
            display: none;
        }

        @media (max-width: 1024px) {
            .main-container {
                flex-direction: column;
                min-height: auto;
            }

            .sidebar {
                width: 100%;
                max-height: none;
                height: auto;
                border-bottom: 1px solid #b3b3b3;
                border-right: none;
                padding: 20px;
                padding-top: 24px;
                order: 2;
            }

            .content {
                width: 100%;
                min-height: auto;
                display: flex;
                flex-direction: column;
                order: 1;
            }

            .content > footer {
                order: 99;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .header-nav {
                gap: 15px;
                font-size: 13px;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }

            @if(auth()->check())
                .sidebar {
                    padding: 16px 14px;
                    position: fixed;
                    left: 0;
                    top: 0;
                    width: 80%;
                    max-width: 300px;
                    height: 100vh;
                    transform: translateX(-100%);
                    transition: transform 0.3s ease;
                    z-index: 1000;
                    border-right: 1px solid #b3b3b3;
                }

                .sidebar.open {
                    transform: translateX(0);
                }

                .sidebar-overlay {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 999;
                }

                .sidebar-overlay.open {
                    display: block;
                }

                #sidebarToggle {
                    display: inline-block !important;
                }
            @else
                .sidebar {
                    padding: 16px 14px;
                    position: fixed;
                    left: 0;
                    top: 0;
                    width: 70%;
                    max-width: 450px;
                    height: 100vh;
                    transform: translateX(-100%);
                    transition: transform 0.3s ease;
                    z-index: 1000;
                    border-right: 1px solid #b3b3b3;
                }

                .sidebar.open {
                    transform: translateX(0);
                }

                .sidebar-overlay {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 999;
                }

                .sidebar-overlay.open {
                    display: block;
                }

                #sidebarToggle {
                    display: inline-block !important;
                }

                .public-header #sidebarToggle {
                    display: inline-block;
                    order: -1;
                }
            @endif

            .sidebar-logo {
                margin-bottom: 24px;
            }

            .header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
                gap: 14px;
                padding: 16px 18px;
            }

            .header-left {
                display: flex;
                align-items: center;
                gap: 10px;
                flex: 1;
                min-width: 0;
            }

            .header-logo {
                font-size: 18px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .header-right {
                display: flex;
                gap: 10px;
                align-items: center;
                white-space: nowrap;
                font-size: 13px;
            }

            .header-nav {
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                gap: 10px 14px;
                justify-content: center;
            }

            .public-header {
                padding: 20px 16px;
                gap: 16px;
            }

            .welcome-section,
            .features-section,
            .screenshots-section,
            .contact-section {
                padding: 32px 18px;
            }

            .welcome-title {
                font-size: 34px;
                line-height: 1.2;
            }

            .welcome-subtitle {
                font-size: 16px;
                line-height: 1.7;
                margin-bottom: 28px;
            }

            .section-title {
                font-size: 26px;
                margin-bottom: 28px;
            }

            .screenshot-placeholder {
                height: 220px;
                font-size: 18px;
            }

            .form-container {
                max-height: none;
                overflow: visible;
            }
        }

        .logout-btn {
            background: #000000;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.25);
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- SIDEBAR OVERLAY (Mobile only) -->
        <div id="sidebarOverlay" class="sidebar-overlay"></div>

        <!-- LEFT SIDEBAR (20%) -->
        <div class="sidebar">
            <div class="sidebar-logo">
                <img src="{{ !empty($siteLogoUrl) ? $siteLogoUrl : asset('branding/atglance-logo.png') }}" alt="AtGlance Logo" style="max-width: 250px; max-height: 100px; object-fit: contain;">
            </div>

            <div class="form-container" id="authForm">
                @php
                    $disableEmailRegistration = (bool) ($disableEmailRegistration ?? false);
                    $showSsoAuthOptions = (bool) ($ssoEnabled ?? false) && !empty($ssoProvidersForAuth ?? []);
                @endphp
                <!-- Auth Tabs -->
                <div class="tab-buttons">
                    <button class="tab-btn active" data-tab="login" onclick="switchTab('login', event)">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                    <button class="tab-btn" data-tab="register" onclick="switchTab('register', event)" {{ $disableEmailRegistration ? 'disabled' : '' }}>
                        <i class="fas fa-user-plus"></i> Register
                    </button>
                    <button class="tab-btn" data-tab="forgot" onclick="switchTab('forgot', event)" {{ $disableEmailRegistration ? 'disabled' : '' }}>
                        <i class="fas fa-key"></i> Forgot
                    </button>
                </div>

                <!-- LOGIN FORM -->
                <div class="tab-content active" id="login">
                    <form method="POST" action="{{ route('login') }}">
                        @csrf
                        @if ($errors->has('login'))
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>{{ $errors->first('login') }}</span>
                        </div>
                        @endif

                        @if (session('inactive_user'))
                        <div class="alert alert-error">
                            <i class="fas fa-user-slash"></i>
                            <span>{{ session('inactive_user') }}</span>
                        </div>
                        @endif

                        <div class="form-group">
                            <label for="login_email"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="login_email" name="email" placeholder="you@example.com" required value="{{ old('email') }}">
                        </div>

                        <div class="form-group">
                            <label for="login_password"><i class="fas fa-lock"></i> Password</label>
                            <input type="password" id="login_password" name="password" placeholder="Enter your password" required>
                        </div>

                        <div class="remember-forgot">
                            <label style="display: flex; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="remember" id="remember">
                                <span>Remember me</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </button>

                        @if($showSsoAuthOptions)
                            <div class="divider">Or continue with SSO</div>
                            <div class="sso-grid">
                                @foreach($ssoProvidersForAuth as $ssoProvider)
                                    <a class="btn btn-secondary btn-sso" href="{{ route('auth.sso.redirect', ['provider' => $ssoProvider['key'], 'context' => 'login']) }}">
                                        <i class="{{ $ssoProvider['icon'] }}"></i>
                                        <span>Continue with {{ $ssoProvider['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </form>
                </div>

                <!-- REGISTRATION FORM -->
                <div class="tab-content" id="register">
                    <form method="POST" action="{{ route('register') }}">
                        @csrf
                        @if ($errors->has('register'))
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>{{ $errors->first('register') }}</span>
                        </div>
                        @endif

                        @if($disableEmailRegistration)
                        <div class="alert alert-error">
                            <i class="fas fa-ban"></i>
                            <span>Email registration is disabled by the administrator. Please use SSO.</span>
                        </div>
                        @endif

                        <fieldset {{ $disableEmailRegistration ? 'disabled' : '' }} style="border:0; margin:0; padding:0; {{ $disableEmailRegistration ? 'opacity:0.55;' : '' }}">
                            <div class="form-group">
                                <label for="reg_name"><i class="fas fa-user"></i> Full Name</label>
                                <input type="text" id="reg_name" name="name" placeholder="John Doe" required value="{{ old('name') }}">
                            </div>

                            <div class="form-group">
                                <label for="reg_email"><i class="fas fa-envelope"></i> Email Address</label>
                                <input type="email" id="reg_email" name="email" placeholder="you@example.com" required value="{{ old('email') }}">
                            </div>

                            <div class="form-group">
                                <label for="reg_password"><i class="fas fa-lock"></i> Password</label>
                                <input type="password" id="reg_password" name="password" placeholder="Minimum 8 characters" required>
                            </div>

                            <div class="form-group">
                                <label for="reg_confirm_password"><i class="fas fa-lock"></i> Confirm Password</label>
                                <input type="password" id="reg_confirm_password" name="password_confirmation" placeholder="Confirm password" required>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Create Account
                            </button>
                        </fieldset>

                        @if($showSsoAuthOptions)
                            <div class="divider">Or register with SSO</div>
                            <div class="sso-grid">
                                @foreach($ssoProvidersForAuth as $ssoProvider)
                                    <a class="btn btn-secondary btn-sso" href="{{ route('auth.sso.redirect', ['provider' => $ssoProvider['key'], 'context' => 'register']) }}">
                                        <i class="{{ $ssoProvider['icon'] }}"></i>
                                        <span>Register with {{ $ssoProvider['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </form>
                </div>

                <!-- FORGOT PASSWORD FORM -->
                <div class="tab-content" id="forgot">
                    <form method="POST" action="{{ route('password.email') }}">
                        @csrf
                        @if($disableEmailRegistration)
                        <div class="alert alert-error">
                            <i class="fas fa-ban"></i>
                            <span>Forgot password is disabled while email registration is off.</span>
                        </div>
                        @endif

                        <fieldset {{ $disableEmailRegistration ? 'disabled' : '' }} style="border:0; margin:0; padding:0; {{ $disableEmailRegistration ? 'opacity:0.55;' : '' }}">
                        <p style="font-size: 13px; color: #666; margin-bottom: 20px;">
                            Enter your email address and we'll send you a link to reset your password.
                        </p>

                        @if ($errors->has('email'))
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>{{ $errors->first('email') }}</span>
                        </div>
                        @endif

                        @if (session('status'))
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <span>{{ session('status') }}</span>
                        </div>
                        @endif

                        <div class="form-group">
                            <label for="forgot_email"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="forgot_email" name="email" placeholder="you@example.com" required value="{{ old('email') }}">
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-envelope"></i> Send Reset Link
                        </button>
                        </fieldset>
                    </form>
                </div>
            </div>

            <!-- Public Navigation (shown on homepage when not authenticated) -->
            <div id="publicNav" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; display: none;">
                <p style="font-size: 11px; color: #6b7280; margin-bottom: 16px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Quick Links</p>
                <nav style="display: flex; flex-direction: column; gap: 8px;">
                    <a href="#about" class="nav-link" style="padding: 12px 14px; color: #1f2937; text-decoration: none; border-radius: 8px; transition: all 0.3s ease; background: #f9fafb; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#d1d5db'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)';" onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb'; this.style.transform='translateX(0)'; this.style.boxShadow='none';">
                        <i class="fas fa-lightbulb" style="color: #444444; font-size: 16px; width: 20px; text-align: center;"></i> About
                    </a>
                    <a href="#features" class="nav-link" style="padding: 12px 14px; color: #1f2937; text-decoration: none; border-radius: 8px; transition: all 0.3s ease; background: #f9fafb; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#d1d5db'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)';" onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb'; this.style.transform='translateX(0)'; this.style.boxShadow='none';">
                        <i class="fas fa-rocket" style="color: #444444; font-size: 16px; width: 20px; text-align: center;"></i> Features
                    </a>
                    <a href="#faq" class="nav-link" style="padding: 12px 14px; color: #1f2937; text-decoration: none; border-radius: 8px; transition: all 0.3s ease; background: #f9fafb; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#d1d5db'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)';" onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb'; this.style.transform='translateX(0)'; this.style.boxShadow='none';">
                        <i class="fas fa-comments" style="color: #444444; font-size: 16px; width: 20px; text-align: center;"></i> FAQ
                    </a>
                    <a href="#support" class="nav-link" style="padding: 12px 14px; color: #1f2937; text-decoration: none; border-radius: 8px; transition: all 0.3s ease; background: #f9fafb; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#d1d5db'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)';" onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb'; this.style.transform='translateX(0)'; this.style.boxShadow='none';">
                        <i class="fas fa-life-ring" style="color: #444444; font-size: 16px; width: 20px; text-align: center;"></i> Support
                    </a>
                    <a href="#contact" class="nav-link" style="padding: 12px 14px; color: #1f2937; text-decoration: none; border-radius: 8px; transition: all 0.3s ease; background: #f9fafb; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px;" onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#d1d5db'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)';" onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb'; this.style.transform='translateX(0)'; this.style.boxShadow='none';">
                        <i class="fas fa-paper-plane" style="color: #444444; font-size: 16px; width: 20px; text-align: center;"></i> Contact
                    </a>
                </nav>
            </div>

            <!-- Dashboard Nav (shown after login) -->
            <div id="dashboardNav" class="hidden" style="margin-top: 40px; padding-top: 40px; border-top: 1px solid #e0e0e0;">
                @php($requiresProfileSetup = auth()->check() && (!auth()->user()->dob || !auth()->user()->pin))
                
                @if(!empty($workspaceSelectorOptions) && count($workspaceSelectorOptions) > 0)
                    <div style="margin-bottom: 24px;">
                        <form method="POST" action="{{ route('workspace.select') }}" class="workspace-switcher" style="display: flex; flex-direction: column; gap: 8px;">
                            @csrf
                            <label for="workspace_selector" style="font-size:12px; color:#4b5563; font-weight:600; text-transform:uppercase;">Workspace</label>
                            <select id="workspace_selector" name="workspace_id" onchange="this.form.submit()" style="min-width: auto; width: 100%;">
                                @foreach($workspaceSelectorOptions as $workspaceOption)
                                    <option value="{{ $workspaceOption->id }}" {{ (int) ($selectedWorkspaceId ?? 0) === (int) $workspaceOption->id ? 'selected' : '' }}>
                                        {{ $workspaceOption->name }}
                                    </option>
                                @endforeach
                            </select>
                        </form>
                    </div>
                @endif
                
                <div style="margin-bottom: 30px;">
                    <p style="font-size: 12px; color: #999; margin-bottom: 10px; text-transform: uppercase; font-weight: 600;">Menu</p>
                    @if($requiresProfileSetup)
                        <div style="margin-bottom: 12px; padding: 10px; border-radius: 6px; background: #fff3cd; border: 1px solid #ffe69c; color: #664d03; font-size: 12px; font-weight: 600;">
                            Complete mandatory profile setup to unlock all pages.
                        </div>
                    @endif
                    <nav style="display: flex; flex-direction: column; gap: 10px;">
                        @if(!$requiresProfileSetup)
                        <a href="{{ route('dashboard') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-chart-line"></i> Dashboard
                        </a>
                        <a href="{{ route('settings') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        @if(auth()->check() && in_array((int) auth()->user()->rbac_id, [100, 101], true))
                        <a href="{{ route('admin.users') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-users"></i> Manage Users
                        </a>
                        <a href="{{ (int) auth()->user()->rbac_id === 100 ? route('enterprise.console') : route('admin.workspaces') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-sitemap"></i> Manage Workspace
                        </a>
                        @if((int) auth()->user()->rbac_id === 100)
                        <a href="{{ route('admin.settings') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-sliders-h"></i> Site Setting
                        </a>
                        <a href="{{ route('enterprise.console') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-building"></i> Enterprise Console
                        </a>
                        @endif
                        <a href="{{ route('systems-registered') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-server"></i> Systems Registered
                        </a>
                        <a href="{{ route('configuration-backups') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-file-code"></i> Configuration Backups
                        </a>
                        @endif
                        @endif
                        <a href="{{ route('profile') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-user-circle"></i> Profile
                        </a>
                    </nav>
                </div>

                <form id="logoutForm" method="POST" action="{{ route('logout') }}" style="margin-top: 20px;">
                    @csrf
                    <button type="submit" class="logout-btn" style="width: 100%;">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
                <div style="margin-top:8px; text-align:center; font-size:11px; color:#9ca3af;">
                    Version {{ $appVersion ?? config('app.version') }}
                </div>
            </div>
        </div>

        <!-- RIGHT CONTENT (80%) -->
        <div class="content">
            @if(auth()->check())
                <!-- DASHBOARD HEADER -->
                <div class="header">
                    <div class="header-left" style="display: flex; align-items: center; gap: 15px;">
                        <button id="sidebarToggle" type="button" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #333; padding: 8px; display: none;" title="Toggle Menu">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="header-logo">
                            <i class="fas fa-gate"></i> AtGlance - Configuration Backup Service
                        </div>
                    </div>
                    <div class="header-right">
                        <span style="color: #333; font-weight: 500;">Hello, {{ auth()->user()->name }}!</span>
                    </div>
                </div>

                <!-- DASHBOARD CONTENT -->
                <div id="dashboardContent">
                    @yield('dashboard-content')
                </div>

                <footer style="margin-top:0; padding:14px 8px; border-top:1px solid #e5e7eb; color:#6b7280; font-size:12px; text-align:center;">
                    <p>&copy; 2026 AtGlance. All rights reserved. | <a href="#" style="color: #000000;">Privacy Policy</a> | <a href="#" style="color: #000000;">Terms of Service</a></p>
                </footer>
            @else
                <!-- PUBLIC HEADER -->
                <div class="header public-header">
                    <button id="sidebarToggle" type="button" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #333; padding: 8px; display: inline-block;" title="Toggle Menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="header-logo">
                        <i class="fas fa-gate"></i> AtGlance - Configuration Backup Service
                    </div>
                </div>

                <!-- WELCOME SECTION -->
                <div class="welcome-section">
                    <h1 class="welcome-title">Welcome to AtGlance</h1>
                    <p class="welcome-subtitle">
                        @if(!empty($siteContent))
                            {{ $siteContent }}
                        @else
                            AtGlance is a Configuration Files Backup as a Service platform built for Linux environments.<br>
                            Securely back up critical server configuration files, monitor service health, and restore faster with centralized management.
                        @endif
                    </p>
                </div>

                <!-- FEATURES SECTION -->
                <div class="features-section" id="features">
                    <h2 class="section-title">Powerful Features</h2>
                    <div class="features-grid">
                        @if(!empty($siteFeatures))
                            @foreach($siteFeatures as $feature)
                                <div class="feature-card">
                                    <div class="feature-icon"><i class="fas fa-check-circle"></i></div>
                                    <div class="feature-title">Custom Feature</div>
                                    <div class="feature-desc">{{ $feature }}</div>
                                </div>
                            @endforeach
                        @else
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                            <div class="feature-title">Lightning Fast</div>
                            <div class="feature-desc">Optimized performance with sub-millisecond latency</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                            <div class="feature-title">Secure</div>
                            <div class="feature-desc">Enterprise-grade security with encryption and auth</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-chart-bar"></i></div>
                            <div class="feature-title">Analytics</div>
                            <div class="feature-desc">Real-time monitoring and comprehensive analytics</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-cogs"></i></div>
                            <div class="feature-title">Configuration</div>
                            <div class="feature-desc">Easy setup with intuitive configuration options</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-expand"></i></div>
                            <div class="feature-title">Scalability</div>
                            <div class="feature-desc">Seamlessly scale from startup to enterprise</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-headset"></i></div>
                            <div class="feature-title">Support</div>
                            <div class="feature-desc">24/7 dedicated support team ready to help</div>
                        </div>
                        @endif
                    </div>
                </div>

                {{--
                <!-- SCREENSHOTS SECTION -->
                <div class="screenshots-section" id="screenshots">
                    <h2 class="section-title">See It In Action</h2>
                    <div class="screenshot-placeholder">
                        <i class="fas fa-image"></i> Dashboard Screenshot
                    </div>
                    <div class="screenshot-placeholder">
                        <i class="fas fa-image"></i> Analytics Screenshot
                    </div>
                </div>
                --}}

                {{--
                <!-- CONTACT SECTION -->
                <div class="contact-section" id="contact">
                    <h2 class="section-title">Get in Touch</h2>
                    <form class="contact-form" method="POST" action="{{ route('contact') }}">
                        @csrf
                        <div class="form-group">
                            <label for="contact_name"><i class="fas fa-user"></i> Your Name</label>
                            <input type="text" id="contact_name" name="name" placeholder="John Doe" required>
                        </div>

                        <div class="form-group">
                            <label for="contact_email"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="contact_email" name="email" placeholder="you@example.com" required>
                        </div>

                        <div class="form-group">
                            <label for="contact_subject"><i class="fas fa-heading"></i> Subject</label>
                            <input type="text" id="contact_subject" name="subject" placeholder="What is this about?" required>
                        </div>

                        <div class="form-group">
                            <label for="contact_message"><i class="fas fa-comment"></i> Message</label>
                            <textarea id="contact_message" name="message" placeholder="Your message here..." required></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                </div>
                --}}

                <!-- FOOTER -->
                <footer style="padding: 40px; background: #f3f3f3; border-top: 1px solid #b3b3b3; text-align: center; color: #444; font-size: 14px;">
                    <p>&copy; 2026 AtGlance. All rights reserved. | <a href="#" style="color: #000000;">Privacy Policy</a> | <a href="#" style="color: #000000;">Terms of Service</a></p>
                </footer>
            @endif
        </div>
    </div>

    <script>
        function switchTab(tabName, evt) {
            const targetButton = evt && evt.target
                ? evt.target.closest('.tab-btn')
                : document.querySelector(`.tab-btn[data-tab="${tabName}"]`);

            if (targetButton && targetButton.disabled) {
                return;
            }

            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked button
            if (targetButton) {
                targetButton.classList.add('active');
            }
        }

        // Toggle dashboard nav visibility when user is authenticated
        document.addEventListener('DOMContentLoaded', function() {
            const isAuthenticated = {{ auth()->check() ? 'true' : 'false' }};
            const authForm = document.getElementById('authForm');
            const dashboardNav = document.getElementById('dashboardNav');

            if (isAuthenticated) {
                authForm.classList.add('hidden');
                dashboardNav.classList.remove('hidden');
                return;
            }

            const activeButton = document.querySelector('.tab-btn.active');
            if (activeButton && activeButton.disabled) {
                switchTab('login');
            }
        });

        // Smooth scroll for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    const element = document.querySelector(href);
                    if (element) {
                        element.scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                }
            });
        });

        function submitLogoutForm() {
            const logoutForm = document.getElementById('logoutForm');
            if (logoutForm) {
                logoutForm.submit();
            }
        }

        // Sidebar toggle functionality for mobile
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const publicNav = document.getElementById('publicNav');
            const dashboardNav = document.getElementById('dashboardNav');

            // Handle sidebar toggle for both public and dashboard pages
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('open');
                    sidebarOverlay.classList.toggle('open');
                });
            }

            // Close sidebar when overlay is clicked
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    sidebarOverlay.classList.remove('open');
                });
            }

            // Toggle between public and dashboard navigation
            @if(auth()->check())
                if (publicNav) publicNav.style.display = 'none';
                if (dashboardNav) dashboardNav.classList.remove('hidden');
            @else
                if (publicNav) publicNav.style.display = 'block';
                if (dashboardNav) dashboardNav.classList.add('hidden');
            @endif
        });

        @if (session('inactive_user'))
            window.addEventListener('DOMContentLoaded', function () {
                alert(@json(session('inactive_user')));
            });
        @endif
    </script>

</body>
</html>
