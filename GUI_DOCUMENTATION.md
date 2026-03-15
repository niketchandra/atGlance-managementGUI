# AtGlance GUI - Modern Web Interface

## Overview

The AtGlance application now features a modern, responsive single-page application (SPA) style web interface with a **20:80 layout** design. This document describes the new frontend structure, components, and how to use them.

## Architecture

### 20:80 Layout Split

- **Left Sidebar (20%)**: Contains authentication forms and navigation menu
  - Before login: Login, Registration, and Forgot Password tabs
  - After login: Dashboard navigation menu with quick links
  
- **Right Content Area (80%)**: Displays the main application content
  - Before login: Welcome section with features, screenshots, and contact form
  - After login: Dashboard, settings, profile, and products management pages

### Design

- **Color Scheme**: Modern gradient (Purple to Pink) - `#667eea` to `#764ba2`
- **Font**: Segoe UI, Tahoma, Geneva (system fonts)
- **Icons**: FontAwesome 6.4.0
- **Framework**: Tailwind CSS (via CDN)

## Pages & Views

### 1. **Main Layout** (`resources/views/app.blade.php`)
The master template that handles the entire UI structure.

**Features:**
- Responsive 20:80 layout
- Dynamic sidebar that shows auth forms or dashboard nav
- Header with navigation (public) or user info (authenticated)
- Public content area with welcome, features, screenshots, and contact sections
- Modern CSS with gradients and animations
- Mobile responsive design

**Routes:**
- `GET /` - Main entry point (shows login form or dashboard)

### 2. **Dashboard** (`resources/views/dashboard.blade.php`)
Main dashboard showing API statistics and metrics.

**Features:**
- Welcome card with personalized greeting
- 4-column stats grid (Requests, Success Rate, Response Time, Active APIs)
- Quick actions buttons
- Recent activity table
- Performance chart placeholder

**Route:**
- `GET /dashboard` - Dashboard (authenticated users only)

### 3. **Settings** (`resources/views/settings.blade.php`)
Comprehensive settings management with 5 tabs.

**Tabs:**
- **Account**: Update profile information (name, email, company, phone, timezone)
- **Security**: Change password, 2FA setup, active sessions
- **Notifications**: Email notification preferences
- **Billing**: Subscription info, payment methods, invoices
- **API Keys**: Create and manage API keys

**Route:**
- `GET /settings` - Settings page (authenticated users only)

### 4. **Profile** (`resources/views/profile.blade.php`)
User profile and account overview.

**Features:**
- Profile banner with avatar
- Account statistics (Active APIs, Requests, Uptime, Response Time)
- Account information display
- Recent activity timeline
- Security & Privacy panel
- Preferences section

**Route:**
- `GET /profile` - Profile page (authenticated users only)

### 5. **Products** (`resources/views/products.blade.php`)
API and product management page.

**Features:**
- Search and filter functionality
- 2-column product card grid
- Each card shows:
  - API name and version
  - Status badge (Active/Maintenance)
  - Description
  - Stats (Requests, Uptime, Response Time)
  - View Details and Edit buttons
- Pagination controls

**Route:**
- `GET /products` - Products/APIs page (authenticated users only)

## Form Features

### Login Form
- Email and password fields
- Remember me checkbox
- Form validation with error display

### Registration Form
- Full name, email, password, and confirmation fields
- Form validation
- Password confirmation required

### Forgot Password Form
- Email input
- Sends password reset link
- Status message display

### Contact Form
- Name, email, subject, and message fields
- Textarea for message
- Form submission handling

## Controllers

### `AuthController` (Web)
Handles web-based authentication and form submissions.

**Methods:**
- `register(Request $request)` - User registration
- `login(Request $request)` - User login with session
- `logout(Request $request)` - User logout
- `sendPasswordResetLink(Request $request)` - Password reset email
- `storeContact(Request $request)` - Contact form submission

### `DashboardController`
Handles dashboard and settings pages.

**Methods:**
- `index()` - Show dashboard
- `settings()` - Show settings page
- `updateSettings(Request $request)` - Update user settings
- `updatePassword(Request $request)` - Update password
- `profile()` - Show profile page
- `products()` - Show products page

## Routes

```php
// Public routes (accessible without authentication)
GET  / - Home page with auth forms
POST /login - Handle login
POST /register - Handle registration
POST /logout - Handle logout
POST /password/email - Send password reset email
POST /contact - Submit contact form

// Protected routes (require authentication)
GET  /dashboard - Dashboard page
GET  /settings - Settings page
POST /settings/update - Update settings
POST /password/update - Update password
GET  /profile - Profile page
GET  /products - Products/APIs page
```

## Key Features

### 1. **Responsive Design**
- Works on desktop and mobile devices
- Layout adapts for smaller screens
- Touch-friendly buttons and forms

### 2. **Modern Styling**
- Gradient backgrounds
- Smooth animations and transitions
- Card-based layout
- Consistent color scheme

### 3. **Form Validation**
- Client and server-side validation
- Error message display
- Success notifications

### 4. **Tab Navigation**
- Smooth tab switching
- Active state indicators
- Fade-in animations

### 5. **Interactive Elements**
- Hover effects on buttons and links
- Smooth scrolling
- Toggle switches for preferences
- Status badges

### 6. **Accessibility**
- Semantic HTML
- Form labels
- Icon descriptions
- Keyboard navigation support

## Styling

All styling is embedded in the main layout file using:
- **Inline CSS** for structure
- **Tailwind CSS** (via CDN) for utilities
- **FontAwesome** for icons
- **CSS Variables** for consistency

### Color Variables

```css
Primary Gradient: #667eea to #764ba2
Success: #4caf50
Warning: #ff9800
Error: #f44336
Info: #2196f3
```

## Database

The application uses Laravel's authentication with the User model. The password field is synced with `password_hash` for API compatibility.

### User Model
- `id` - Primary key
- `name` - User's full name
- `email` - User's email (unique)
- `password` - Hashed password
- `password_hash` - API compatibility field
- `dob` - Date of birth (optional)
- `email_verified_at` - Email verification timestamp
- `status` - User status (active/inactive)
- `created_at`, `updated_at` - Timestamps

## Migration Required

Run the migration to add the password column:

```bash
php artisan migrate
```

This will add the `password` column to the users table while maintaining the existing `password_hash` column.

## Usage

### For End Users

1. **Register**: Click "Register" tab, fill in details, and submit
2. **Login**: Click "Login" tab, enter credentials, and submit
3. **Access Dashboard**: After login, you'll see the dashboard
4. **Manage Settings**: Go to Settings to update profile, security, notifications, etc.
5. **View Profile**: Check your profile information and activity
6. **Manage APIs**: View and manage your API integrations

### For Developers

1. Add new pages by creating a Blade template in `resources/views/`
2. Create corresponding controller methods
3. Add routes in `routes/web.php`
4. Use the app.blade.php layout with `@extends('app')`
5. Define page content in the `@section('dashboard-content')` section

## JavaScript Features

### Tab Switching
```javascript
switchTab(tabName) - Switches between login/register/forgot tabs
switchSettingsTab(tabName) - Switches between settings tabs
```

### Other Features
- Smooth scroll for navigation links
- Dynamic visibility toggles
- Form validation helpers

## Future Enhancements

- [ ] Real-time API analytics charts (Chart.js integration)
- [ ] Email verification process
- [ ] Two-factor authentication implementation
- [ ] OAuth integration
- [ ] Dark theme toggle
- [ ] Multi-language support
- [ ] Advanced filtering for products
- [ ] Activity log storage and display
- [ ] File upload for avatar
- [ ] Real API statistics from database

## Notes

- All forms currently submit to route handlers that validate and process data
- The contact form needs email configuration in `.env`
- Password reset functionality needs email configuration
- The API keys section displays mock data - connect to actual API tokens table
- Chart placeholders should be replaced with actual JavaScript charts
- Settings updates should be connected to database models
