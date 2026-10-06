@extends('app')

@section('title', 'Products - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h1 style="font-size: 30px; font-weight: 500; color: var(--ag-text); margin-bottom: 10px;">Products & APIs</h1>
            <p style="color: var(--ag-muted);">Manage your APIs and integrations</p>
        </div>
        <button class="ag-btn">
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
            background: var(--ag-card);
            border: 1px solid var(--ag-line);
            border-radius: 999px;
            cursor: pointer;
            font-weight: 500;
            color: var(--ag-text);
            transition: all 0.3s ease;
        }

        .filter-btn.active {
            background: #7a7a7a;
            color: white;
            border-color: #7a7a7a;
        }

        .filter-btn:hover {
            border-color: #8f8f8f;
        }

        .search-box {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid var(--ag-line);
            border-radius: 12px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--ag-mint);
            box-shadow: 0 0 0 4px rgba(113, 247, 212, 0.3);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .product-card {
            background: var(--ag-card);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--ag-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--ag-shadow);
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
            color: var(--ag-muted);
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
            border-bottom: 1px solid var(--ag-line);
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
            color: var(--ag-muted);
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
            border-radius: 999px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--ag-ink);
        }

        .action-btn-primary:hover {
        }

        .action-btn-secondary {
            background: var(--ag-surface);
            color: var(--ag-text);
        }

        .action-btn-secondary:hover {
            background: var(--ag-line);
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
        <button class="ag-btn ag-btn--ghost">← Previous</button>
        <button style="padding: 8px 12px; border: 1px solid #2cb7d9; background: #2cb7d9; color: white; border-radius: 4px; cursor: pointer;">1</button>
        <button class="ag-btn ag-btn--ghost">2</button>
        <button class="ag-btn ag-btn--ghost">3</button>
        <button class="ag-btn ag-btn--ghost">Next →</button>
    </div>
</div>
@endsection
