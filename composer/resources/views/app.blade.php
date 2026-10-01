<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $brandName)</title>
    <link rel="icon" href="{{ $siteFaviconUrl ?? asset('branding/favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('branding/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    @php $agCssPath = public_path('css/atglance.css'); @endphp
    <link href="{{ asset('css/atglance.css') }}?v={{ is_file($agCssPath) ? filemtime($agCssPath) : '1' }}" rel="stylesheet">
</head>
<body class="ag-body">
    @php
        $agDefaultLogoUrl = asset('branding/atglance-logo.png');
        $agLogoUrl = !empty($siteLogoUrl) ? $siteLogoUrl : $agDefaultLogoUrl;
        // The default wordmark already reads "AtGlance"; only add the organisation
        // name beside it when the organisation is called something else.
        $agShowBrandName = $agLogoUrl !== $agDefaultLogoUrl || strcasecmp(trim((string) $brandName), 'AtGlance') !== 0;
    @endphp
    <div class="ag-frame">
    @if(auth()->check())
        @php
            $agUser = auth()->user();
            $agRole = (int) $agUser->rbac_id;
            $agIsAdmin = in_array($agRole, [100, 101], true);
            $agIsSuperAdmin = $agRole === 100;
            $requiresProfileSetup = !$agUser->dob || !$agUser->pin;

            // One pill per page; `active` lists the route names that light it up.
            $agNavItems = [];
            if (!$requiresProfileSetup) {
                $agNavItems[] = ['label' => 'Dashboard', 'icon' => 'fa-chart-line', 'url' => route('dashboard'), 'active' => ['dashboard', 'admin.dashboard', 'live-service-monitoring', 'vulnerabilities-identified']];
                $agNavItems[] = ['label' => 'Systems', 'icon' => 'fa-server', 'url' => route('systems-registered'), 'active' => ['systems-registered*']];
                $agNavItems[] = ['label' => 'Config Backups', 'icon' => 'fa-file-code', 'url' => route('configuration-backups'), 'active' => ['configuration-backups*']];
                if ($agIsAdmin) {
                    $agNavItems[] = ['label' => 'Users', 'icon' => 'fa-users', 'url' => route('admin.users'), 'active' => ['admin.users*']];
                    $agNavItems[] = ['label' => 'Workspace', 'icon' => 'fa-sitemap', 'url' => $agIsSuperAdmin ? route('enterprise.console') : route('admin.workspaces'), 'active' => $agIsSuperAdmin ? ['__none__'] : ['admin.workspaces*', 'workspace.*']];
                    $agNavItems[] = ['label' => 'Notifications', 'icon' => 'fa-bell', 'url' => route('admin.notifications'), 'active' => ['admin.notifications*']];
                }
                if ($agIsSuperAdmin) {
                    $agNavItems[] = ['label' => 'Site Settings', 'icon' => 'fa-sliders-h', 'url' => route('admin.settings'), 'active' => ['admin.settings*']];
                    $agNavItems[] = ['label' => 'Enterprise Console', 'icon' => 'fa-building', 'url' => route('enterprise.console'), 'active' => ['enterprise.*', 'admin.workspaces*', 'workspace.*']];
                }
                $agNavItems[] = ['label' => 'Settings', 'icon' => 'fa-cog', 'url' => route('settings'), 'active' => ['settings*']];
            }
            $agNavItems[] = ['label' => 'Profile', 'icon' => 'fa-user-circle', 'url' => route('profile'), 'active' => ['profile*']];
        @endphp

        <header class="ag-topbar ag-topbar--app">
            <button type="button" class="ag-icon-btn" id="agNavToggle" aria-controls="agNav" aria-expanded="false" title="Menu">
                <i class="fas fa-bars"></i>
            </button>
            <a href="{{ route('dashboard') }}" class="ag-brand {{ $agLogoUrl === $agDefaultLogoUrl ? 'ag-brand--wordmark' : '' }}" title="{{ $brandName }}">
                <img src="{{ $agLogoUrl }}" alt="{{ $brandName }} Logo">
                @if($agShowBrandName)
                    <span class="ag-brand-name">{{ $brandName }}</span>
                @endif
            </a>

            <div class="ag-topbar-right">
                @if(!empty($workspaceSelectorOptions) && count($workspaceSelectorOptions) > 0)
                    <form method="POST" action="{{ route('workspace.select') }}" class="ag-workspace">
                        @csrf
                        <span>Workspace</span>
                        <label for="workspace_selector" class="sr-only">Workspace</label>
                        <select id="workspace_selector" name="workspace_id" onchange="this.form.submit()">
                            @foreach($workspaceSelectorOptions as $workspaceOption)
                                <option value="{{ $workspaceOption->id }}" {{ (int) ($selectedWorkspaceId ?? 0) === (int) $workspaceOption->id ? 'selected' : '' }}>
                                    {{ $workspaceOption->name }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                @endif

                <div class="ag-user">
                    <button type="button" class="ag-user-chip" id="agUserToggle" aria-haspopup="true" aria-expanded="false">
                        <x-user-avatar :user="$agUser" size="36" />
                        <span class="ag-user-meta">
                            <small>{{ $agUser->email }}</small>
                            <span>{{ $agUser->name }}</span>
                        </span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="ag-menu hidden" id="agUserMenu" role="menu">
                        <a href="{{ route('profile') }}" role="menuitem"><i class="fas fa-user-circle"></i> Profile</a>
                        @if(!$requiresProfileSetup)
                            <a href="{{ route('settings') }}" role="menuitem"><i class="fas fa-cog"></i> Settings</a>
                        @endif
                        <form id="logoutForm" method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" role="menuitem"><i class="fas fa-sign-out-alt"></i> Logout</button>
                        </form>
                        <div class="ag-menu-meta">Version {{ $appVersion ?? config('app.version') }}</div>
                    </div>
                </div>
            </div>
        </header>

        <div class="ag-shell">
            <aside class="ag-sidebar">

                <nav class="ag-side-nav" id="agNav" aria-label="Main">
                    @foreach($agNavItems as $agItem)
                        @php $agActive = request()->routeIs(...$agItem['active']); @endphp
                        <a href="{{ $agItem['url'] }}" class="ag-side-link {{ $agActive ? 'is-active' : '' }}" @if($agActive) aria-current="page" @endif>
                            <i class="fas {{ $agItem['icon'] }}"></i> <span>{{ $agItem['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                @if($agIsSuperAdmin)
                @php
                    $agLicence = \App\Support\License::summary();
                    $agLicenceActivated = \App\Support\License::date($agLicence['activated_at']);
                    $agLicenceValidated = \App\Support\License::date($agLicence['verified_at']);
                @endphp
                <div class="ag-licence-card {{ $agLicence['active'] ? '' : 'is-missing' }}">
                    <div class="ag-licence-head">
                        <span class="ag-card-icon"><i class="fas fa-key"></i></span>
                        <span>Licence</span>
                        <span class="ag-badge {{ $agLicence['active'] ? 'ag-badge--success' : 'ag-badge--warning' }}">
                            {{ $agLicence['active'] ? 'Active' : 'Not licensed' }}
                        </span>
                    </div>
                    @if($agLicence['active'])
                        <div class="ag-licence-plan">{{ $agLicence['plan'] !== '' ? ucfirst($agLicence['plan']) : 'Community Edition' }}</div>
                        <div class="ag-licence-line">Activated On {{ $agLicenceActivated !== '' ? $agLicenceActivated : '-' }}</div>
                        <div class="ag-licence-line">Validated On {{ $agLicenceValidated !== '' ? $agLicenceValidated : '-' }}</div>
                    @else
                        <div class="ag-licence-line">New users and API keys stay locked until a licence is added.</div>
                    @endif
                    @if($agLicence['active'])
                        <a href="{{ \App\Support\License::portalUrl() }}" target="_blank" rel="noopener" class="ag-btn ag-btn--sm ag-licence-btn">
                            <i class="fas fa-arrow-up-right-dots"></i> Upgrade
                        </a>
                    @else
                        <a href="{{ route('admin.settings', ['tab' => 'licence']) }}" class="ag-btn ag-btn--sm ag-licence-btn">
                            <i class="fas fa-key"></i> Add licence
                        </a>
                    @endif
                </div>
                @endif

                <div class="ag-sidebar-meta">Version {{ $appVersion ?? config('app.version') }}</div>
            </aside>

            <div class="ag-shell-main">

                @if($requiresProfileSetup)
                    <div class="ag-notice"><i class="fas fa-lock"></i> Complete mandatory profile setup to unlock all pages.</div>
                @endif

                <main class="ag-main">
                    <div class="ag-content" id="dashboardContent">
                        @yield('dashboard-content')
                    </div>
                </main>

                <footer class="ag-footer">
                    @include('partials.product-footer')
                </footer>
            </div>
        </div>
    @else
        @php
            $disableEmailRegistration = (bool) ($disableEmailRegistration ?? false);
            $showSsoAuthOptions = (bool) ($ssoEnabled ?? false) && !empty($ssoProvidersForAuth ?? []);
            $quickLinkIcons = ['about' => 'fa-lightbulb', 'features' => 'fa-rocket', 'faq' => 'fa-comments', 'support' => 'fa-life-ring', 'contact' => 'fa-paper-plane'];
        @endphp

        <header class="ag-topbar">
            <a href="{{ route('home') }}" class="ag-brand {{ $agLogoUrl === $agDefaultLogoUrl ? 'ag-brand--wordmark' : '' }}" title="{{ $brandName }}">
                <img src="{{ $agLogoUrl }}" alt="{{ $brandName }} Logo">
            </a>

            <button type="button" class="ag-icon-btn" id="agNavToggle" aria-controls="agNav" aria-expanded="false" title="Menu">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="ag-nav" id="agNav" aria-label="Quick links">
                <div class="ag-nav-track">
                    <a href="{{ route('home') }}" class="ag-nav-link {{ request()->routeIs('home') ? 'is-active' : '' }}">
                        <i class="fas fa-home"></i> Home
                    </a>
                    @foreach($publicPages as $publicPage => $publicPageTitle)
                        @php $agActive = request()->routeIs('public.page') && request()->route('page') === $publicPage; @endphp
                        <a href="{{ route('public.page', ['page' => $publicPage]) }}" class="ag-nav-link {{ $agActive ? 'is-active' : '' }}">
                            <i class="fas {{ $quickLinkIcons[$publicPage] ?? 'fa-file-alt' }}"></i> {{ $publicPageTitle }}
                        </a>
                    @endforeach
                </div>
            </nav>
        </header>

        <main class="ag-main ag-public">
            <div>
                @hasSection('public-content')
                    <div class="ag-content">
                        @yield('public-content')
                    </div>
                @else
                    <section class="ag-hero">
                        <h1>Welcome to {{ $brandName }}</h1>
                        <p>
                            @if($siteDescription !== '')
                                {{ $siteDescription }}
                            @else
                                {{ $brandName }} is a Configuration Files Backup as a Service platform built for Linux environments.
                                Securely back up critical server configuration files, monitor service health, and restore faster with centralized management.
                            @endif
                        </p>
                    </section>

                    @php $homeFeatures = \App\Support\SiteProfile::current()->features(); @endphp
                    @php
                        $homeFeatures = !empty($homeFeatures) ? $homeFeatures : [
                        ['title' => 'Lightning Fast', 'description' => 'Optimized performance with sub-millisecond latency', 'icon' => 'fa-bolt'],
                        ['title' => 'Secure', 'description' => 'Enterprise-grade security with encryption and auth', 'icon' => 'fa-shield-alt'],
                        ['title' => 'Analytics', 'description' => 'Real-time monitoring and comprehensive analytics', 'icon' => 'fa-chart-bar'],
                        ['title' => 'Configuration', 'description' => 'Easy setup with intuitive configuration options', 'icon' => 'fa-cogs'],
                        ['title' => 'Scalability', 'description' => 'Seamlessly scale from startup to enterprise', 'icon' => 'fa-expand'],
                        ['title' => 'Support', 'description' => '24/7 dedicated support team ready to help', 'icon' => 'fa-headset'],
                    ];
                    @endphp
                    <section class="ag-feature-grid" id="features">
                        @foreach($homeFeatures as $feature)
                            <div class="ag-feature">
                                <span class="ag-feature-icon"><i class="fas {{ $feature['icon'] ?? 'fa-check' }}"></i></span>
                                <h3>{{ $feature['title'] }}</h3>
                                <p>{{ $feature['description'] }}</p>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            <aside class="ag-auth-card">
                <div class="form-container" id="authForm">
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
            </aside>
        </main>

        <footer class="ag-footer">
            @include('partials.product-footer')
        </footer>
    @endif
    </div>

    <script>
        function switchTab(tabName, evt) {
            const targetButton = evt && evt.target
                ? evt.target.closest('.tab-btn')
                : document.querySelector(`.tab-btn[data-tab="${tabName}"]`);

            if (targetButton && targetButton.disabled) {
                return;
            }

            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');

            if (targetButton) {
                targetButton.classList.add('active');
            }
        }

        function submitLogoutForm() {
            const logoutForm = document.getElementById('logoutForm');
            if (logoutForm) {
                logoutForm.submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const activeButton = document.querySelector('.tab-btn.active');
            if (activeButton && activeButton.disabled) {
                switchTab('login');
            }

            // Mobile: the pill row collapses behind a menu button.
            const navToggle = document.getElementById('agNavToggle');
            const nav = document.getElementById('agNav');
            if (navToggle && nav) {
                navToggle.addEventListener('click', function () {
                    const open = nav.classList.toggle('is-open');
                    const sidebar = nav.closest('.ag-sidebar');
                    if (sidebar) {
                        sidebar.classList.toggle('is-open', open);
                    }
                    navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            }

            // User chip menu (profile, settings, logout).
            const userToggle = document.getElementById('agUserToggle');
            const userMenu = document.getElementById('agUserMenu');
            if (userToggle && userMenu) {
                userToggle.addEventListener('click', function (event) {
                    event.stopPropagation();
                    const open = userMenu.classList.toggle('hidden') === false;
                    userToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                document.addEventListener('click', function (event) {
                    if (!userMenu.contains(event.target)) {
                        userMenu.classList.add('hidden');
                        userToggle.setAttribute('aria-expanded', 'false');
                    }
                });
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        userMenu.classList.add('hidden');
                        userToggle.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            // Keep the active pill in view and fade whichever edge hides more pills.
            const track = document.querySelector('.ag-nav-track');
            const activePill = document.querySelector('.ag-nav-link.is-active');
            if (track && activePill) {
                track.scrollLeft = activePill.offsetLeft - (track.clientWidth - activePill.offsetWidth) / 2;
            }
            function updateNavFade() {
                if (!track) {
                    return;
                }
                const max = track.scrollWidth - track.clientWidth;
                track.classList.toggle('fade-left', track.scrollLeft > 2);
                track.classList.toggle('fade-right', max - track.scrollLeft > 2);
            }
            if (track) {
                track.addEventListener('scroll', updateNavFade, { passive: true });
                window.addEventListener('resize', updateNavFade);
                updateNavFade();
            }
        });

        // Smooth scroll for in-page links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    const element = document.querySelector(href);
                    if (element) {
                        e.preventDefault();
                        element.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
        });

        @if (session('inactive_user'))
            window.addEventListener('DOMContentLoaded', function () {
                alert(@json(session('inactive_user')));
            });
        @endif
    </script>

    @if(auth()->check() && \App\Support\UserPreferences::get(auth()->user(), 'timezone') === null)
        <script>
            // First visit with no time zone set: save the browser's, so dates show in local time.
            (function () {
                try {
                    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    if (!zone) {
                        return;
                    }
                    const body = new FormData();
                    body.append('_token', @json(csrf_token()));
                    body.append('timezone', zone);
                    fetch(@json(route('settings.preferences.timezone')), { method: 'POST', body: body, credentials: 'same-origin' });
                } catch (error) {
                    // Keep the server default.
                }
            })();
        </script>
    @endif
</body>
</html>
