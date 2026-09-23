<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AtGlance Installer</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .installer-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        .installer-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }
        .installer-logo {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }
        .installer-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        .installer-description {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin-bottom: 25px;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }
        .form-section {
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            text-transform: uppercase;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e0e0e0;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        .form-group label {
            font-weight: 600;
            color: #333;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .form-group input,
        .form-group select {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s ease;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .helper-text {
            font-size: 12px;
            color: #999;
            margin-top: 6px;
        }
        .error-alert {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .error-alert ul {
            margin: 0;
            padding-left: 20px;
        }
        .error-alert li {
            margin-bottom: 6px;
        }
        .submit-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        .submit-btn i {
            margin-right: 8px;
        }
        @media (max-width: 640px) {
            .installer-card {
                padding: 25px;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .installer-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="installer-card">
        <div class="installer-header">
            <div class="installer-logo">
                <i class="fas fa-gate"></i>
            </div>
            <h1 class="installer-title">AtGlance Installer</h1>
        </div>
        <div class="installer-description">
            <strong>AtGlance</strong> is a configuration files backup platform for Linux administrators. Set up the application for your organization.
        </div>
        @if ($errors->any())
            <div class="error-alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form action="{{ route('install.run') }}" method="POST">
            @csrf
            <div class="form-section">
                <h2 class="section-title">Organization Setup</h2>
                <div class="form-group">
                    <label for="organization_name">Organization Name</label>
                    <input id="organization_name" name="organization_name" type="text" required value="{{ old('organization_name', 'Default Organization') }}" placeholder="Acme Corp">
                </div>
                <div class="form-group">
                    <label for="app_url">Domain or IP Address</label>
                    <input id="app_url" name="app_url" type="text" required value="{{ old('app_url', $defaultDomain ?? request()->getHttpHost()) }}" placeholder="example.com or 192.168.1.50:8000">
                    <p class="helper-text">Do not include http:// or https://</p>
                </div>
                <div class="form-group">
                    <label for="use_https">Use HTTPS</label>
                    <select id="use_https" name="use_https">
                        <option value="1" {{ old('use_https', '1') === '1' ? 'selected' : '' }}>Yes</option>
                        <option value="0" {{ old('use_https') === '0' ? 'selected' : '' }}>No</option>
                    </select>
                </div>
            </div>

            <div class="form-section">
                <h2 class="section-title">Administrator Account</h2>
                <p style="font-size: 13px; color: #666; margin-bottom: 15px;">Create the account you will use to manage this organization.</p>
                <div class="form-group">
                    <label for="admin_name">Administrator Name</label>
                    <input id="admin_name" name="admin_name" type="text" required value="{{ old('admin_name') }}" placeholder="Jane Smith">
                </div>
                <div class="form-group">
                    <label for="admin_email">Administrator Email</label>
                    <input id="admin_email" name="admin_email" type="email" required value="{{ old('admin_email') }}" placeholder="admin@example.com">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="admin_password">Password</label>
                        <input id="admin_password" name="admin_password" type="password" minlength="8" required placeholder="Minimum 8 characters">
                    </div>
                    <div class="form-group">
                        <label for="admin_password_confirmation">Confirm Password</label>
                        <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" minlength="8" required placeholder="Confirm password">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-check-circle"></i>
                Complete Installation
            </button>
        </form>
    </div>
</body>
</html>