<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete</title>
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
        .success-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 700px;
            width: 100%;
            padding: 40px;
        }
        .success-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            margin: 0 auto 20px;
        }
        .success-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .success-subtitle {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 30px 0;
        }
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .info-label {
            font-weight: 600;
            color: #333;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .info-value {
            font-size: 14px;
            color: #666;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 30px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        @media (max-width: 640px) {
            .success-card {
                padding: 25px;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .success-title {
                font-size: 22px;
            }
            .action-buttons {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="success-card">
        <div class="success-header">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h1 class="success-title">Installation Completed</h1>
            <p class="success-subtitle">Your AtGlance application is ready. Sign in with the administrator account you created during setup.</p>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="info-label"><i class="fas fa-building"></i> Organization</div>
                <div class="info-value">{{ $installation['organization_name'] ?? 'Default Organization' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-globe"></i> Domain</div>
                <div class="info-value">{{ $installation['app_domain'] ?? '' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-lock"></i> HTTPS Enabled</div>
                <div class="info-value">{{ ($installation['https_enabled'] ?? false) ? 'Yes' : 'No' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-user"></i> Administrator</div>
                <div class="info-value">{{ $installation['admin_email'] ?? 'Admin account' }}</div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="{{ route('home') }}" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                Go to Login
            </a>
        </div>
    </div>
</body>
</html>