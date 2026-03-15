@extends('app')

@section('title', 'Products - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h1 style="font-size: 32px; font-weight: bold; color: #333; margin-bottom: 10px;">Products & APIs</h1>
            <p style="color: #666;">Manage your APIs and integrations</p>
        </div>
        <button style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: transform 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <i class="fas fa-plus"></i> Add New API
        </button>
    </div>

    <style>
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
        }

        .filter-btn {
            padding: 8px 16px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            color: #333;
            transition: all 0.3s ease;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .filter-btn:hover {
            border-color: #667eea;
        }

        .search-box {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .search-box:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .product-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .product-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: start;
        }

        .product-header h3 {
            font-size: 20px;
            font-weight: bold;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255,255,255,0.3);
            color: white;
        }

        .product-body {
            padding: 20px;
        }

        .product-desc {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .product-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .stat {
            text-align: center;
        }

        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .product-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .action-btn-primary:hover {
            transform: translateY(-2px);
        }

        .action-btn-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .action-btn-secondary:hover {
            background: #e0e0e0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 60px;
            color: #ccc;
            margin-bottom: 20px;
        }
    </style>

    <!-- Filter Bar -->
    <div style="display: flex; gap: 10px; margin-bottom: 30px;">
        <input type="text" class="search-box" placeholder="Search APIs..." style="max-width: 300px;">
        <div class="filter-bar" style="flex: 1;">
            <button class="filter-btn active">All</button>
            <button class="filter-btn">Active</button>
            <button class="filter-btn">Inactive</button>
            <button class="filter-btn">Maintenance</button>
        </div>
    </div>

    <!-- Products Grid -->
    <div class="products-grid">
        <!-- Product Card 1 -->
        <div class="product-card">
            <div class="product-header">
                <div>
                    <h3><i class="fas fa-user"></i> User API</h3>
                    <p style="font-size: 13px; margin-top: 8px; opacity: 0.9;">v2.0</p>
                </div>
                <span class="status-badge">Active</span>
            </div>
            <div class="product-body">
                <p class="product-desc">Comprehensive user management API with authentication and profile endpoints.</p>
                <div class="product-stats">
                    <div class="stat">
                        <div class="stat-value">524.5K</div>
                        <div class="stat-label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">99.9%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">142ms</div>
                        <div class="stat-label">Response</div>
                    </div>
                </div>
                <div class="product-actions">
                    <button class="action-btn action-btn-primary">View Details</button>
                    <button class="action-btn action-btn-secondary">Edit</button>
                </div>
            </div>
        </div>

        <!-- Product Card 2 -->
        <div class="product-card">
            <div class="product-header">
                <div>
                    <h3><i class="fas fa-box"></i> Product API</h3>
                    <p style="font-size: 13px; margin-top: 8px; opacity: 0.9;">v1.5</p>
                </div>
                <span class="status-badge">Active</span>
            </div>
            <div class="product-body">
                <p class="product-desc">Product catalog and inventory management API with advanced filtering options.</p>
                <div class="product-stats">
                    <div class="stat">
                        <div class="stat-value">892.1K</div>
                        <div class="stat-label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">99.8%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">235ms</div>
                        <div class="stat-label">Response</div>
                    </div>
                </div>
                <div class="product-actions">
                    <button class="action-btn action-btn-primary">View Details</button>
                    <button class="action-btn action-btn-secondary">Edit</button>
                </div>
            </div>
        </div>

        <!-- Product Card 3 -->
        <div class="product-card">
            <div class="product-header">
                <div>
                    <h3><i class="fas fa-shopping-cart"></i> Order API</h3>
                    <p style="font-size: 13px; margin-top: 8px; opacity: 0.9;">v1.2</p>
                </div>
                <span class="status-badge">Active</span>
            </div>
            <div class="product-body">
                <p class="product-desc">Order processing and management API with payment integration support.</p>
                <div class="product-stats">
                    <div class="stat">
                        <div class="stat-value">346.8K</div>
                        <div class="stat-label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">99.7%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">195ms</div>
                        <div class="stat-label">Response</div>
                    </div>
                </div>
                <div class="product-actions">
                    <button class="action-btn action-btn-primary">View Details</button>
                    <button class="action-btn action-btn-secondary">Edit</button>
                </div>
            </div>
        </div>

        <!-- Product Card 4 -->
        <div class="product-card">
            <div class="product-header">
                <div>
                    <h3><i class="fas fa-chart-line"></i> Analytics API</h3>
                    <p style="font-size: 13px; margin-top: 8px; opacity: 0.9;">v1.0</p>
                </div>
                <span class="status-badge" style="background: rgba(255,255,255,0.3);">Maintenance</span>
            </div>
            <div class="product-body">
                <p class="product-desc">Real-time analytics and reporting API with custom dashboard support.</p>
                <div class="product-stats">
                    <div class="stat">
                        <div class="stat-value">178.3K</div>
                        <div class="stat-label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">98.5%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">387ms</div>
                        <div class="stat-label">Response</div>
                    </div>
                </div>
                <div class="product-actions">
                    <button class="action-btn action-btn-primary">View Details</button>
                    <button class="action-btn action-btn-secondary">Edit</button>
                </div>
            </div>
        </div>

        <!-- Product Card 5 -->
        <div class="product-card">
            <div class="product-header">
                <div>
                    <h3><i class="fas fa-envelope"></i> Notification API</h3>
                    <p style="font-size: 13px; margin-top: 8px; opacity: 0.9;">v1.1</p>
                </div>
                <span class="status-badge">Active</span>
            </div>
            <div class="product-body">
                <p class="product-desc">Email and SMS notification service with template management.</p>
                <div class="product-stats">
                    <div class="stat">
                        <div class="stat-value">1.2M</div>
                        <div class="stat-label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">99.6%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">156ms</div>
                        <div class="stat-label">Response</div>
                    </div>
                </div>
                <div class="product-actions">
                    <button class="action-btn action-btn-primary">View Details</button>
                    <button class="action-btn action-btn-secondary">Edit</button>
                </div>
            </div>
        </div>

        <!-- Product Card 6 -->
        <div class="product-card">
            <div class="product-header">
                <div>
                    <h3><i class="fas fa-lock"></i> Auth API</h3>
                    <p style="font-size: 13px; margin-top: 8px; opacity: 0.9;">v3.0</p>
                </div>
                <span class="status-badge">Active</span>
            </div>
            <div class="product-body">
                <p class="product-desc">OAuth 2.0 and JWT authentication service with MFA support.</p>
                <div class="product-stats">
                    <div class="stat">
                        <div class="stat-value">2.1M</div>
                        <div class="stat-label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">99.95%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value">89ms</div>
                        <div class="stat-label">Response</div>
                    </div>
                </div>
                <div class="product-actions">
                    <button class="action-btn action-btn-primary">View Details</button>
                    <button class="action-btn action-btn-secondary">Edit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div style="display: flex; justify-content: center; gap: 10px; margin-top: 30px;">
        <button style="padding: 8px 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">← Previous</button>
        <button style="padding: 8px 12px; border: 1px solid #667eea; background: #667eea; color: white; border-radius: 4px; cursor: pointer;">1</button>
        <button style="padding: 8px 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">2</button>
        <button style="padding: 8px 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">3</button>
        <button style="padding: 8px 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">Next →</button>
    </div>
</div>
@endsection
