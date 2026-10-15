<?php
/**
 * Create test fixtures for AtGlance CLI testing
 * Usage: php create_fixtures.php
 */

require_once 'composer/vendor/autoload.php';
$app = require_once 'composer/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\PersonalAccessToken;
use App\Models\ServiceAccount;

echo "=== Creating Test Fixtures for AtGlance CLI v2.0.0~alpha4 ===\n\n";

// Get or create users
echo "[USERS] Creating test users...\n";
$admin = User::where('email', 'admin.tester@example.com')->first();
if (!$admin) {
    $admin = new User();
    $admin->name = 'Admin Tester';
    $admin->email = 'admin.tester@example.com';
    $admin->password = bcrypt('AdminTest123!');
    $admin->pin = '1234';
    $admin->save();
}
echo "  ✓ Admin user: " . $admin->id . " (" . $admin->email . ")\n";

$user = User::where('email', 'user.tester@example.com')->first();
if (!$user) {
    $user = new User();
    $user->name = 'User Tester';
    $user->email = 'user.tester@example.com';
    $user->password = bcrypt('UserTest123!');
    $user->pin = '5678';
    $user->save();
}
echo "  ✓ Regular user: " . $user->id . " (" . $user->email . ")\n";

// Create PATs
echo "\n[PATS] Creating Personal Access Tokens...\n";

// PAT 1: Expires in 5 days (wsl-ubuntu)
$pat1 = PersonalAccessToken::create([
    'tokenable_type' => 'App\\Models\\User',
    'tokenable_id' => $admin->id,
    'user_id' => $admin->id,
    'name' => 'wsl-ubuntu-pat',
    'token' => 'test_' . hash('sha256', 'wsl-' . time() . rand()),
    'abilities' => json_encode(['*']),
    'status' => 'active',
    'expires_at' => now()->addDays(5),
]);
echo "  ✓ PAT 1: " . $pat1->id . " (wsl-ubuntu, expires: " . $pat1->expires_at . ")\n";

// PAT 2: Expires in 5 days (pi-raspbian)
$pat2 = PersonalAccessToken::create([
    'tokenable_type' => 'App\\Models\\User',
    'tokenable_id' => $admin->id,
    'user_id' => $admin->id,
    'name' => 'pi-raspbian-pat',
    'token' => 'test_' . hash('sha256', 'pi-' . time() . rand()),
    'abilities' => json_encode(['*']),
    'status' => 'active',
    'expires_at' => now()->addDays(5),
]);
echo "  ✓ PAT 2: " . $pat2->id . " (pi-raspbian, expires: " . $pat2->expires_at . ")\n";

// PAT 3: Never expires
$pat3 = PersonalAccessToken::create([
    'tokenable_type' => 'App\\Models\\User',
    'tokenable_id' => $user->id,
    'user_id' => $user->id,
    'name' => 'never-expire-pat',
    'token' => 'test_' . hash('sha256', 'never-' . time() . rand()),
    'abilities' => json_encode(['*']),
    'status' => 'active',
    'expires_at' => null,
]);
echo "  ✓ PAT 3: " . $pat3->id . " (never-expire, no expiry)\n";

// Create Service Accounts
echo "\n[SAs] Creating Service Accounts...\n";

// SA 1: Workspace-level
$sa1 = ServiceAccount::create([
    'name' => 'sa-workspace-1',
    'scope' => 'workspace',
    'org_id' => 1,
    'workspace_id' => 1,
    'owner_user_id' => $admin->id,
    'created_by_user_id' => $admin->id,
    'status' => 'active',
]);
echo "  ✓ SA 1: " . $sa1->id . " (workspace scope)\n";

// SA 2: Workspace-level
$sa2 = ServiceAccount::create([
    'name' => 'sa-workspace-2',
    'scope' => 'workspace',
    'org_id' => 1,
    'workspace_id' => 1,
    'owner_user_id' => $admin->id,
    'created_by_user_id' => $admin->id,
    'status' => 'active',
]);
echo "  ✓ SA 2: " . $sa2->id . " (workspace scope)\n";

// SA 3: Org-level
$sa3 = ServiceAccount::create([
    'name' => 'sa-org-1',
    'scope' => 'org',
    'org_id' => 1,
    'owner_user_id' => $admin->id,
    'created_by_user_id' => $admin->id,
    'status' => 'active',
]);
echo "  ✓ SA 3: " . $sa3->id . " (org scope)\n";

// SA 4: Org-level
$sa4 = ServiceAccount::create([
    'name' => 'sa-org-2',
    'scope' => 'org',
    'org_id' => 1,
    'owner_user_id' => $admin->id,
    'created_by_user_id' => $admin->id,
    'status' => 'active',
]);
echo "  ✓ SA 4: " . $sa4->id . " (org scope)\n";

// Verify
echo "\n[VERIFICATION] Summary:\n";
$patCount = DB::table('personal_access_tokens')->where('status', 'active')->count();
$saCount = DB::table('service_accounts')->where('status', 'active')->count();
$userCount = DB::table('users')->where('email', 'like', '%tester%')->count();
echo "  Users created: " . $userCount . "\n";
echo "  PATs created: " . $patCount . "\n";
echo "  Service Accounts created: " . $saCount . "\n";

echo "\n✅ Test fixtures created successfully!\n";
