<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'AtGlance - API Gateway')</title>
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
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .sidebar {
            width: 20%;
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            overflow-y: auto;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .content {
            width: 80%;
            padding: 0;
            overflow-y: auto;
            background: white;
        }

        .sidebar-logo {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
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
            border-bottom: 2px solid #e0e0e0;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #999;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            margin-bottom: -2px;
        }

        .tab-btn.active {
            color: #667eea;
            border-bottom-color: #667eea;
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
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            width: 100%;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
            color: #999;
            font-size: 13px;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #ddd;
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
            color: #667eea;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .remember-forgot a:hover {
            color: #764ba2;
        }

        .header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-logo {
            font-size: 24px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-logo i {
            margin-right: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
            color: #667eea;
        }

        .header-right {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .welcome-section {
            padding: 60px 40px;
            text-align: center;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        }

        .welcome-title {
            font-size: 48px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .welcome-subtitle {
            font-size: 18px;
            color: #666;
            margin-bottom: 40px;
            line-height: 1.6;
        }

        .features-section {
            padding: 60px 40px;
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
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 10px;
            border: 1px solid rgba(102, 126, 234, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-align: center;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
        }

        .feature-icon {
            font-size: 40px;
            color: #667eea;
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
            background: #f8f9fa;
        }

        .screenshot-placeholder {
            width: 100%;
            height: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
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
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
        }

        .contact-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            }

            .sidebar {
                width: 100%;
                height: auto;
                border-bottom: 1px solid #e0e0e0;
                padding: 20px;
            }

            .content {
                width: 100%;
                height: auto;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .header-nav {
                gap: 15px;
                font-size: 13px;
            }
        }

        .logout-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- LEFT SIDEBAR (20%) -->
        <div class="sidebar">
            <div class="sidebar-logo">
                <i class="fas fa-gate"></i> AtGlance
            </div>

            <div class="form-container" id="authForm">
                <!-- Auth Tabs -->
                <div class="tab-buttons">
                    <button class="tab-btn active" onclick="switchTab('login')">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                    <button class="tab-btn" onclick="switchTab('register')">
                        <i class="fas fa-user-plus"></i> Register
                    </button>
                    <button class="tab-btn" onclick="switchTab('forgot')">
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
                    </form>
                </div>

                <!-- FORGOT PASSWORD FORM -->
                <div class="tab-content" id="forgot">
                    <form method="POST" action="{{ route('password.email') }}">
                        @csrf
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
                    </form>
                </div>
            </div>

            <!-- Dashboard Nav (shown after login) -->
            <div id="dashboardNav" class="hidden" style="margin-top: 40px; padding-top: 40px; border-top: 1px solid #e0e0e0;">
                <div style="margin-bottom: 30px;">
                    <p style="font-size: 12px; color: #999; margin-bottom: 10px; text-transform: uppercase; font-weight: 600;">Menu</p>
                    <nav style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="{{ route('dashboard') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-chart-line"></i> Dashboard
                        </a>
                        <a href="{{ route('settings') }}" class="nav-link" style="padding: 10px; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.3s ease;" onmouseover="this.style.background='#f0f0f0'" onmouseout="this.style.background='transparent'">
                            <i class="fas fa-cog"></i> Settings
                        </a>
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
            </div>
        </div>

        <!-- RIGHT CONTENT (80%) -->
        <div class="content">
            @if(auth()->check())
                <!-- DASHBOARD HEADER -->
                <div class="header">
                    <div class="header-left">
                        <div class="header-logo">
                            <i class="fas fa-gate"></i> AtGlance
                        </div>
                    </div>
                    <div class="header-right">
                        <span style="color: #333; font-weight: 500;">Hello, {{ auth()->user()->name }}!</span>
                        <button type="button" onclick="submitLogoutForm()" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </div>
                </div>

                <!-- DASHBOARD CONTENT -->
                <div id="dashboardContent">
                    @yield('dashboard-content')
                </div>
            @else
                <!-- PUBLIC HEADER -->
                <div class="header">
                    <div class="header-left">
                        <div class="header-logo">
                            <i class="fas fa-gate"></i> AtGlance
                        </div>
                    </div>
                    <div class="header-nav">
                        <a href="#about">About</a>
                        <a href="#features">Features</a>
                        <a href="#faq">FAQ</a>
                        <a href="#support">Support</a>
                        <a href="#contact">Contact</a>
                    </div>
                </div>

                <!-- WELCOME SECTION -->
                <div class="welcome-section">
                    <h1 class="welcome-title">Welcome to AtGlance</h1>
                    <p class="welcome-subtitle">
                        Your comprehensive API Gateway for seamless integration and management.<br>
                        Build, deploy, and scale your applications with confidence.
                    </p>
                </div>

                <!-- FEATURES SECTION -->
                <div class="features-section" id="features">
                    <h2 class="section-title">Powerful Features</h2>
                    <div class="features-grid">
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
                    </div>
                </div>

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

                <!-- FOOTER -->
                <footer style="padding: 40px; background: #f8f9fa; border-top: 1px solid #e0e0e0; text-align: center; color: #666; font-size: 14px;">
                    <p>&copy; 2026 AtGlance. All rights reserved. | <a href="#" style="color: #667eea;">Privacy Policy</a> | <a href="#" style="color: #667eea;">Terms of Service</a></p>
                </footer>
            @endif
        </div>
    </div>

    <script>
        function switchTab(tabName) {
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

            // Add active class to clicked button (find the closest button element)
            const clickedButton = event.target.closest('.tab-btn');
            if (clickedButton) {
                clickedButton.classList.add('active');
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
    </script>
    function submitLogoutForm() {
        const logoutForm = document.getElementById('logoutForm');
        if (logoutForm) {
            logoutForm.submit();
        }
    }

</body>
</html>
