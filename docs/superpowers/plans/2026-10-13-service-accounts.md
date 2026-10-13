# Service Accounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (Release 2.1.2) Add workspace and organization service accounts with their own `atgsa-` keys, so registered servers run as a machine identity instead of a person, revoke a person's `atgla-` keys as soon as they are deactivated, deleted or moved to another organization, and harden person keys (hash only, required expiry, 5 active per user).

**Architecture:** New `service_accounts` and `service_account_workspaces` tables; keys stay in `personal_access_tokens` with `tokenable_type = ServiceAccount`. `auth.pat` resolves either identity into an `ApiIdentity` value object; only `/api/v1` routes that opt in (`auth.pat:service`) accept service account keys. Registration needs both a user key and a service account key. Management is server-side in `ServiceAccountAccess` and `ServiceAccounts`; the console gets an organization page and a workspace tab. Notifications reuse `Notifier` and `AccountAlerts`, plus a daily sweep command.

**Tech Stack:** PHP 8.2, Laravel 12, Blade, MySQL 8 (production) and SQLite in-memory (tests), PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-10-13-service-accounts-design.md`

## Global Constraints

- All code lives in `composer/`. Paths below are relative to `composer/` unless they start with `docs/`.
- Run tests in the dev container: `docker run --rm -e VIEW_COMPILED_PATH=/tmp/views -v "$(pwd):/app" -w /app --entrypoint php ce-atglance-app:dev artisan test --filter=<Name>` from `composer/`. If a local PHP 8.2 with extensions exists, `php artisan test --filter=<Name>` is equivalent.
- Key prefixes: person `atgla-` (unchanged), service account `atgsa-` followed by 32 alphanumeric characters (`Str::random(32)`, backed by `random_bytes`).
- Service account keys: only the SHA-256 hash is stored, `token_encrypted` is null, `expires_at` is required, default 90 days, max 365 days, at most 2 active keys per account.
- Quotas: 10 active organization accounts per organization, 5 active workspace accounts per workspace. Disabled accounts do not count.
- Permissions: exactly `systems.register`, `configs.write`, `configs.read`, `systems.rotate_key`. Default on: the first three.
- Expiry notices at 14 and 3 days before `expires_at`.
- Setting `sa_require_for_registration` default off.
- Deregister and reactivate never accept a service account key.
- A revoked key never becomes active again, including after the user is reactivated.
- Ids from another organization answer 404, never 403.
- Never write a key's plaintext to logs, `activity_logs`, notifications or any response except the creation response.
- Follow the code style of the file you edit (4 spaces, short docblocks that say why, `ActivityRecorder::record` for audit).
- Commit after each task with a Conventional Commits message ending in `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

### Naming decisions made in this plan (not in the spec)

- Authorization lives in `App\Support\ServiceAccountAccess` (a plain class), not a Laravel `Policy`, because the checks take a scope and a workspace, not only a model.
- `NotificationEvents` maps each key to one scope, so organization-level events use the prefix `organization.`: `organization.service_account_created`, `organization.service_account_disabled`, `organization.service_account_key_expiring`, `organization.service_account_key_expired`, `organization.service_account_key_revoked`, `organization.service_account_owner_missing`, `organization.user_keys_revoked`. Workspace-level events use the spec names (`service_account.created`, ...).
- `personal_access_tokens.last_four` (string 4) stores the last 4 characters of a key for notices and lists.
- `personal_access_tokens.expired_notified_at` marks that the expired notice was sent.

## Review Focus

1. A service account key sent to a legacy route (`/api/config-files/upload`, `/api/mcp/*`, `/api/system-register`) must get 401, not a 500 from `$request->user()->id` on null. Test in Task 4.
2. A server first registered by a person and later assigned to a service account must keep one version history per service; the first service-account upload must reuse the existing `services` row, not start a new one at version 1. Test in Task 7.
3. Console pages that list config files or services must still render when `configuration_files.user_id` or `services.user_id` is null. Test in Task 11.
4. A user who is set inactive while their key is mid-request: the revoke and the status change must not leave an active key behind if the save fails. Test in Task 8 (revoke runs in `updated`, after the save succeeded).
5. An organization account whose grant on a workspace is revoked, while its server in that workspace still holds the key: the next upload must get 404 (system middleware), not 403, and not succeed. Test in Task 7.

---

## File map

| File | Responsibility | Task |
|---|---|---|
| `database/migrations/2026_10_13_000000_create_service_accounts.php` | New tables, new columns, nullable `user_id` | 1 |
| `app/Models/ServiceAccount.php` | Account model, permissions, grant lookup | 1 |
| `app/Models/ServiceAccountGrant.php` | Grant model | 1 |
| `app/Support/ServiceAccountSettings.php` | Lifetime, quota, notice and registration settings | 1 |
| `app/Services/ServiceAccounts.php` | Create, edit, grants, keys, owner, assign system | 2 |
| `app/Services/ServiceAccountException.php` | Domain error shown as 422 | 2 |
| `app/Support/ServiceAccountAccess.php` | Who may see and manage what | 3 |
| `app/Models/Workspace.php` | New `service_accounts` permission | 3 |
| `app/Support/ApiIdentity.php` | User or service account behind a request | 4 |
| `app/Http/Middleware/AuthenticatePatToken.php` | Accept `atgsa-` on opted-in routes | 4 |
| `app/Http/Middleware/RequireServiceAccountPermission.php` | `sa.permission:<perm>` on system routes | 7 |
| `app/Http/Middleware/AuthenticateSystemKey.php` | Service account branch | 7 |
| `app/Http/Controllers/Api/V1/MetaController.php` | `whoami` for both identities | 5 |
| `app/Http/Controllers/Api/V1/SystemController.php` | Two-key registration, rotate, deregister rules | 6, 7 |
| `app/Http/Controllers/Api/SystemRegisterController.php` | `createSystem` with account, legacy enforcement, `canManageSystem`, rotate actor | 6, 7 |
| `app/Http/Controllers/Api/FileController.php` | Upload as a service account | 7 |
| `app/Http/Controllers/Api/V1/ConfigFileController.php` | `created_by` for accounts | 7 |
| `app/Services/UserKeyRevoker.php` | Revoke all of a user's keys | 8 |
| `app/Models/User.php` | Call the revoker on status, org, delete | 8 |
| `app/Notifications/NotificationEvents.php` | New events | 9 |
| `app/Support/ServiceAccountNotices.php` | Route account events to the right level | 9 |
| `app/Support/AccountAlerts.php` | New personal alerts | 9 |
| `app/Services/CredentialSweep.php` | Daily expiry notices | 9 |
| `routes/console.php` | Command and schedule | 9 |
| `app/Http/Controllers/ServiceAccountController.php` | Console actions | 10 |
| `resources/views/admin/service-accounts/index.blade.php` | Organization page | 10 |
| `resources/views/admin/service-accounts/show.blade.php` | Account detail | 10 |
| `resources/views/admin/workspace/tab-service-accounts.blade.php` | Workspace tab | 10 |
| `routes/web.php`, `routes/api.php`, `bootstrap/app.php` | Routes and aliases | 4, 6, 7, 10 |
| `tests/Feature/Concerns/MakesServiceAccounts.php` | Shared test helpers | 1 |
| `tests/Feature/ServiceAccount*Test.php` | Tests per task | 1–11 |
| `app/Services/UserKeys.php` | Issue and revoke person keys | 12 |
| `docs/service-accounts.md`, `docs/api-reference.md`, `docs/api-keys.md`, `docs/notifications.md` | Docs | 13 |

---

### Task 1: Schema, models and settings

**Files:**
- Create: `database/migrations/2026_10_13_000000_create_service_accounts.php`
- Create: `app/Models/ServiceAccount.php`
- Create: `app/Models/ServiceAccountGrant.php`
- Create: `app/Support/ServiceAccountSettings.php`
- Create: `tests/Feature/Concerns/MakesServiceAccounts.php`
- Modify: `app/Models/PatToken.php` (fillable, casts)
- Modify: `app/Models/SystemRegister.php`, `app/Models/ConfigurationFile.php`, `app/Models/RawData.php`, `app/Models/Service.php` (fillable `service_account_id`)
- Test: `tests/Feature/ServiceAccountModelTest.php`

**Interfaces:**
- Produces:
  - `ServiceAccount::SCOPE_ORGANIZATION = 'organization'`, `ServiceAccount::SCOPE_WORKSPACE = 'workspace'`
  - `ServiceAccount::PERMISSIONS` (array key => label), `ServiceAccount::DEFAULT_PERMISSIONS` (list)
  - `ServiceAccount::normalizePermissions(mixed $input): array` (list of valid keys, sorted as in `PERMISSIONS`)
  - `ServiceAccount->grants(): HasMany`, `->keys(): MorphMany`, `->owner(): BelongsTo`, `->workspace(): BelongsTo`, `->organization(): BelongsTo`
  - `ServiceAccount->activeGrantFor(int $workspaceId): ?ServiceAccountGrant`
  - `ServiceAccount->can(string $permission, int $workspaceId): bool`
  - `ServiceAccount->isActive(): bool`
  - `ServiceAccount->activeKeys(): MorphMany` (status active, not expired)
  - `ServiceAccountGrant` with `permissions` cast to array, `->allows(string $permission): bool`
  - `ServiceAccountSettings::defaultKeyDays(): int`, `maxKeyDays(): int`, `orgQuota(): int`, `workspaceQuota(): int`, `noticeDays(): array` (ints, descending), `requireForRegistration(): bool`, `save(array $values): void`
  - Test trait `MakesServiceAccounts` with `makeUser()`, `userKey()`, `makeAccount()`, `accountKey()`, `makeSystem()`

- [ ] **Step 1: Write the test helper trait**

```php
<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Users, keys, workspaces, service accounts and systems for the service account tests.
 * Call setUpServiceAccountWorld() from setUp().
 */
trait MakesServiceAccounts
{
    protected const SYSTEM_KEY = 'k1-0123456789abcdef0123456789abcdef01234567';

    protected Workspace $workspace;
    protected Workspace $otherWorkspace;
    protected Workspace $foreignWorkspace;

    protected function setUpServiceAccountWorld(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops', 'status' => 'active']);
        Organization::query()->updateOrCreate(['id' => 300], ['name' => 'Other Org', 'status' => 'active']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'production', 'status' => 'active']);
        $this->otherWorkspace = Workspace::create(['org_id' => 200, 'name' => 'staging', 'status' => 'active']);
        $this->foreignWorkspace = Workspace::create(['org_id' => 300, 'name' => 'theirs', 'status' => 'active']);
    }

    protected function makeUser(string $email, int $rbacId = 102, int $orgId = 200, string $status = 'active'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => $rbacId,
            'org_id' => $orgId,
            'status' => $status,
        ])->save();

        return $user;
    }

    /**
     * @return array{0: string, 1: PatToken} plaintext atgla- key and its row
     */
    protected function userKey(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        $token = PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'cli',
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        return [$plain, $token];
    }

    protected function makeAccount(string $scope = ServiceAccount::SCOPE_WORKSPACE, ?Workspace $workspace = null, array $attributes = []): ServiceAccount
    {
        $workspace ??= $this->workspace;
        $account = ServiceAccount::create(array_merge([
            'name' => 'backup-agent-' . Str::random(4),
            'scope' => $scope,
            'org_id' => (int) $workspace->org_id,
            'workspace_id' => $scope === ServiceAccount::SCOPE_WORKSPACE ? $workspace->id : null,
            'status' => 'active',
        ], $attributes));

        if ($scope === ServiceAccount::SCOPE_WORKSPACE) {
            $account->grants()->create([
                'workspace_id' => $workspace->id,
                'permissions' => ServiceAccount::DEFAULT_PERMISSIONS,
                'status' => 'active',
            ]);
        }

        return $account;
    }

    protected function grant(ServiceAccount $account, Workspace $workspace, ?array $permissions = null): void
    {
        $account->grants()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['permissions' => $permissions ?? ServiceAccount::DEFAULT_PERMISSIONS, 'status' => 'active'],
        );
    }

    /**
     * @return array{0: string, 1: PatToken} plaintext atgsa- key and its row
     */
    protected function accountKey(ServiceAccount $account, array $overrides = []): array
    {
        $plain = 'atgsa-' . Str::random(32);
        $token = PatToken::create(array_merge([
            'tokenable_type' => ServiceAccount::class,
            'tokenable_id' => $account->id,
            'name' => 'fleet',
            'token' => hash('sha256', $plain),
            'abilities' => [],
            'status' => 'active',
            'last_four' => substr($plain, -4),
            'expires_at' => now()->addDays(90),
        ], $overrides));

        return [$plain, $token];
    }

    protected function makeSystem(User $registeredBy, array $overrides = []): SystemRegister
    {
        return SystemRegister::create(array_merge([
            'pat_token_id' => 1,
            'user_id' => $registeredBy->id,
            'org_id' => $registeredBy->org_id,
            'workspace_id' => $this->workspace->id,
            'system_name' => 'web-01',
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'version' => '24.04',
            'ip_address' => '10.0.0.5',
            'status' => 'active',
            'validation_hash' => self::SYSTEM_KEY,
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    protected function systemHeaders(SystemRegister $system): array
    {
        return ['X-Atglance-System-Id' => (string) $system->id, 'X-Atglance-System-Key' => (string) $system->validation_hash];
    }
}
```

- [ ] **Step 2: Write the failing model test**

`tests/Feature/ServiceAccountModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountModelTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_workspace_account_can_act_only_in_its_workspace(): void
    {
        $account = $this->makeAccount();

        $this->assertTrue($account->can('configs.write', $this->workspace->id));
        $this->assertFalse($account->can('configs.write', $this->otherWorkspace->id));
        $this->assertFalse($account->can('systems.rotate_key', $this->workspace->id));
    }

    public function test_organization_account_needs_an_active_grant(): void
    {
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->assertFalse($account->can('configs.read', $this->workspace->id));

        $this->grant($account, $this->workspace, ['configs.read']);
        $this->assertTrue($account->fresh()->can('configs.read', $this->workspace->id));
        $this->assertFalse($account->fresh()->can('configs.write', $this->workspace->id));

        $account->grants()->update(['status' => 'revoked']);
        $this->assertFalse($account->fresh()->can('configs.read', $this->workspace->id));
    }

    public function test_disabled_account_can_do_nothing(): void
    {
        $account = $this->makeAccount();
        $account->update(['status' => 'disabled']);

        $this->assertFalse($account->fresh()->can('configs.write', $this->workspace->id));
    }

    public function test_permissions_are_normalized(): void
    {
        $this->assertSame(['configs.write', 'configs.read'], ServiceAccount::normalizePermissions(['configs.read', 'bogus', 'configs.write', 'configs.read']));
        $this->assertSame([], ServiceAccount::normalizePermissions('configs.read'));
    }

    public function test_scope_and_workspace_cannot_change_after_create(): void
    {
        $account = $this->makeAccount();
        $account->fill(['scope' => ServiceAccount::SCOPE_ORGANIZATION, 'workspace_id' => $this->otherWorkspace->id, 'org_id' => 300])->save();

        $fresh = $account->fresh();
        $this->assertSame(ServiceAccount::SCOPE_WORKSPACE, $fresh->scope);
        $this->assertSame($this->workspace->id, (int) $fresh->workspace_id);
        $this->assertSame(200, (int) $fresh->org_id);
    }

    public function test_settings_defaults_and_clamping(): void
    {
        $this->assertSame(90, ServiceAccountSettings::defaultKeyDays());
        $this->assertSame(365, ServiceAccountSettings::maxKeyDays());
        $this->assertSame(10, ServiceAccountSettings::orgQuota());
        $this->assertSame(5, ServiceAccountSettings::workspaceQuota());
        $this->assertSame([14, 3], ServiceAccountSettings::noticeDays());
        $this->assertFalse(ServiceAccountSettings::requireForRegistration());

        ServiceAccountSettings::save(['sa_max_key_days' => 5000, 'sa_expiry_notice_days' => '3, 30,x', 'sa_require_for_registration' => true]);
        $this->assertSame(3650, ServiceAccountSettings::maxKeyDays());
        $this->assertSame([30, 3], ServiceAccountSettings::noticeDays());
        $this->assertTrue(ServiceAccountSettings::requireForRegistration());
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountModelTest`
Expected: FAIL, `Class "App\Models\ServiceAccount" not found`.

- [ ] **Step 4: Write the migration**

`database/migrations/2026_10_13_000000_create_service_accounts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service accounts (spec 2026-10-13): non-human identities scoped to one workspace
     * or to granted workspaces of an organization. Keys stay in personal_access_tokens.
     * Data a service account uploads has service_account_id set and user_id null.
     */
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 512)->nullable();
            $table->string('team', 100)->nullable();
            $table->string('scope', 20);
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'scope', 'status']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('service_account_workspaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_account_id');
            $table->unsignedBigInteger('workspace_id');
            $table->json('permissions');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('service_account_id')->references('id')->on('service_accounts')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('granted_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['service_account_id', 'workspace_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('last_four', 4)->nullable()->after('token');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoke_reason', 50)->nullable();
            $table->unsignedSmallInteger('expiry_notified_stage')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
        });

        Schema::table('system_register', function (Blueprint $table) {
            $table->unsignedBigInteger('service_account_id')->nullable()->after('user_id');
            $table->index('service_account_id');
        });

        foreach (['configuration_files', 'raw_data', 'services'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('service_account_id')->nullable()->after('user_id');
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->index('service_account_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['configuration_files', 'raw_data', 'services', 'system_register'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['service_account_id']);
                $table->dropColumn('service_account_id');
            });
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['last_four', 'revoked_at', 'revoked_by_user_id', 'revoke_reason', 'expiry_notified_stage', 'expired_notified_at']);
        });

        Schema::dropIfExists('service_account_workspaces');
        Schema::dropIfExists('service_accounts');
    }
};
```

`user_id` is not made NOT NULL again in `down()`: rows written by service accounts would block it.

- [ ] **Step 5: Write the models**

`app/Models/ServiceAccount.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A non-human identity. Workspace scope: one workspace, fixed at creation. Organization
 * scope: only the workspaces it has an active grant for. Keys are PatToken rows with
 * tokenable_type = ServiceAccount and the atgsa- prefix.
 */
class ServiceAccount extends Model
{
    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_WORKSPACE = 'workspace';

    public const PERMISSIONS = [
        'systems.register' => 'Register new servers in this workspace',
        'configs.write' => 'Upload configuration files',
        'configs.read' => 'List and download configuration files',
        'systems.rotate_key' => "Rotate its own server's system key",
    ];

    public const DEFAULT_PERMISSIONS = ['systems.register', 'configs.write', 'configs.read'];

    /** Fixed at creation; see booted(). */
    private const IMMUTABLE = ['scope', 'org_id', 'workspace_id'];

    protected $fillable = [
        'name',
        'description',
        'team',
        'scope',
        'org_id',
        'workspace_id',
        'owner_user_id',
        'created_by_user_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (ServiceAccount $account): void {
            foreach (self::IMMUTABLE as $column) {
                if ($account->isDirty($column)) {
                    $account->{$column} = $account->getOriginal($column);
                }
            }
        });
    }

    /**
     * Valid permission keys from user input, in PERMISSIONS order, without duplicates.
     *
     * @return list<string>
     */
    public static function normalizePermissions(mixed $input): array
    {
        $input = is_array($input) ? array_map('strval', $input) : [];

        return array_values(array_filter(array_keys(self::PERMISSIONS), fn (string $key) => in_array($key, $input, true)));
    }

    public function grants(): HasMany
    {
        return $this->hasMany(ServiceAccountGrant::class);
    }

    public function keys(): MorphMany
    {
        return $this->morphMany(PatToken::class, 'tokenable');
    }

    public function activeKeys(): MorphMany
    {
        return $this->keys()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOrganizationScope(): bool
    {
        return $this->scope === self::SCOPE_ORGANIZATION;
    }

    public function activeGrantFor(int $workspaceId): ?ServiceAccountGrant
    {
        if ($workspaceId <= 0) {
            return null;
        }

        return $this->grants()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Read on every call (no cache), so a revoked grant or a disabled account stops the next request.
     */
    public function can(string $permission, int $workspaceId): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return (bool) $this->activeGrantFor($workspaceId)?->allows($permission);
    }
}
```

`app/Models/ServiceAccountGrant.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAccountGrant extends Model
{
    protected $table = 'service_account_workspaces';

    protected $fillable = [
        'service_account_id',
        'workspace_id',
        'permissions',
        'status',
        'granted_by_user_id',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function allows(string $permission): bool
    {
        return $this->status === 'active' && in_array($permission, (array) $this->permissions, true);
    }
}
```

- [ ] **Step 6: Write the settings class**

`app/Support/ServiceAccountSettings.php`:

```php
<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Service account and key settings, stored in admin_settings (group service_accounts).
 */
final class ServiceAccountSettings
{
    private const GROUP = 'service_accounts';

    private const DEFAULTS = [
        'sa_default_key_days' => 90,
        'sa_max_key_days' => 365,
        'sa_org_quota' => 10,
        'sa_workspace_quota' => 5,
        'sa_expiry_notice_days' => '14,3',
        'sa_require_for_registration' => '0',
    ];

    public static function defaultKeyDays(): int
    {
        return min(self::int('sa_default_key_days', 1, 3650), self::maxKeyDays());
    }

    public static function maxKeyDays(): int
    {
        return self::int('sa_max_key_days', 1, 3650);
    }

    public static function orgQuota(): int
    {
        return self::int('sa_org_quota', 0, 1000);
    }

    public static function workspaceQuota(): int
    {
        return self::int('sa_workspace_quota', 0, 1000);
    }

    /**
     * @return list<int> days before expiry, largest first
     */
    public static function noticeDays(): array
    {
        $days = array_filter(
            array_map(fn ($part) => (int) trim($part), explode(',', (string) self::raw('sa_expiry_notice_days'))),
            fn (int $day) => $day > 0 && $day <= 365,
        );
        $days = array_values(array_unique($days));
        rsort($days);

        return $days;
    }

    public static function requireForRegistration(): bool
    {
        return in_array((string) self::raw('sa_require_for_registration'), ['1', 'true', 'on'], true);
    }

    /**
     * @param array<string, mixed> $values keys from DEFAULTS; others are ignored
     */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($key === 'sa_require_for_registration') {
                $value = $value ? '1' : '0';
            }
            if ($key === 'sa_max_key_days') {
                $value = max(1, min(3650, (int) $value));
            }
            AdminSetting::putValue(self::GROUP, $key, (string) $value);
        }
    }

    private static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::raw($key)));
    }

    private static function raw(string $key): mixed
    {
        if (!Schema::hasTable('admin_settings')) {
            return self::DEFAULTS[$key];
        }

        return AdminSetting::getValue($key, self::DEFAULTS[$key]);
    }
}
```

- [ ] **Step 7: Extend existing models**

In `app/Models/PatToken.php` add to `$fillable`: `'last_four', 'revoked_at', 'revoked_by_user_id', 'revoke_reason', 'expiry_notified_stage', 'expired_notified_at'`, and add:

```php
	protected $casts = [
		'abilities' => 'json',
		'last_used_at' => 'datetime',
		'expires_at' => 'datetime',
		'revoked_at' => 'datetime',
		'expired_notified_at' => 'datetime',
	];

	public function isServiceAccountKey(): bool
	{
		return $this->tokenable_type === ServiceAccount::class;
	}
```

(The file uses tabs; keep tabs.)

In `SystemRegister`, `ConfigurationFile`, `RawData`, `Service`: add `'service_account_id'` to `$fillable`, and add to each:

```php
    public function serviceAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class, 'service_account_id');
    }
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --filter=ServiceAccountModelTest`
Expected: PASS (6 tests). Then run the whole suite: `php artisan test`. Expected: no new failures.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_10_13_000000_create_service_accounts.php app/Models app/Support/ServiceAccountSettings.php tests/Feature/Concerns/MakesServiceAccounts.php tests/Feature/ServiceAccountModelTest.php
git commit -m "feat(service-accounts): schema, models and settings"
```

---

### Task 2: Service account operations (create, grants, keys, owner)

**Files:**
- Create: `app/Services/ServiceAccounts.php`
- Create: `app/Services/ServiceAccountException.php`
- Test: `tests/Feature/ServiceAccountServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models and `ServiceAccountSettings`.
- Produces (`App\Services\ServiceAccounts`, resolved from the container):
  - `create(User $actor, array $data): ServiceAccount` — `$data`: `name`, `description?`, `team?`, `scope`, `workspace_id?` (workspace scope), `owner_user_id?` (defaults to actor), `permissions?` (workspace scope)
  - `update(ServiceAccount $account, User $actor, array $data): void` — `name`, `description`, `team`
  - `setStatus(ServiceAccount $account, User $actor, bool $active): void`
  - `transferOwner(ServiceAccount $account, User $actor, User $newOwner): void`
  - `setGrant(ServiceAccount $account, User $actor, Workspace $workspace, array $permissions): ServiceAccountGrant`
  - `removeGrant(ServiceAccount $account, User $actor, Workspace $workspace): void`
  - `issueKey(ServiceAccount $account, User $actor, string $name, ?int $days = null): array` returning `['token' => PatToken, 'plain' => string]`
  - `revokeKey(PatToken $key, ?User $actor, string $reason): void`
  - `assignSystem(SystemRegister $system, ServiceAccount $account, User $actor): void`
  - `ServiceAccountException extends RuntimeException` (message safe to show)
- Notification calls in this task go through `ServiceAccountNotices` (Task 9). Until Task 9, do not call it; Task 9 adds the calls listed there.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ServiceAccount;
use App\Services\ServiceAccountException;
use App\Services\ServiceAccounts;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountServiceTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    private ServiceAccounts $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        $this->accounts = app(ServiceAccounts::class);
    }

    public function test_create_workspace_account_adds_its_fixed_grant(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $account = $this->accounts->create($admin, [
            'name' => 'prod-backup',
            'scope' => ServiceAccount::SCOPE_WORKSPACE,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertSame($admin->id, (int) $account->owner_user_id);
        $this->assertSame($admin->id, (int) $account->created_by_user_id);
        $this->assertTrue($account->can('configs.write', $this->workspace->id));
        $this->assertSame(1, $account->grants()->count());
        $this->assertTrue(ActivityLog::where('event', 'service_account.created')->exists());
    }

    public function test_create_rejects_workspace_of_other_organization(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $this->expectException(ServiceAccountException::class);
        $this->accounts->create($admin, ['name' => 'x', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->foreignWorkspace->id]);
    }

    public function test_create_rejects_duplicate_name_in_scope(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $this->accounts->create($admin, ['name' => 'dup', 'scope' => ServiceAccount::SCOPE_ORGANIZATION]);

        $this->expectException(ServiceAccountException::class);
        $this->accounts->create($admin, ['name' => 'dup', 'scope' => ServiceAccount::SCOPE_ORGANIZATION]);
    }

    public function test_workspace_quota(): void
    {
        ServiceAccountSettings::save(['sa_workspace_quota' => 1]);
        $admin = $this->makeUser('admin@example.test', 101);
        $first = $this->accounts->create($admin, ['name' => 'a', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->workspace->id]);

        try {
            $this->accounts->create($admin, ['name' => 'b', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->workspace->id]);
            $this->fail('quota not enforced');
        } catch (ServiceAccountException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }

        // Disabled accounts do not count.
        $this->accounts->setStatus($first, $admin, false);
        $this->accounts->create($admin, ['name' => 'b', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->workspace->id]);
        $this->assertSame(2, ServiceAccount::count());
    }

    public function test_grants_for_organization_account(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->accounts->create($admin, ['name' => 'org-agent', 'scope' => ServiceAccount::SCOPE_ORGANIZATION]);

        $this->accounts->setGrant($account, $admin, $this->workspace, ['configs.read', 'nope']);
        $this->assertTrue($account->can('configs.read', $this->workspace->id));
        $this->assertFalse($account->can('configs.write', $this->workspace->id));

        $this->accounts->removeGrant($account, $admin, $this->workspace);
        $this->assertFalse($account->can('configs.read', $this->workspace->id));

        $this->expectException(ServiceAccountException::class);
        $this->accounts->setGrant($account, $admin, $this->foreignWorkspace, ['configs.read']);
    }

    public function test_workspace_account_grant_cannot_be_removed_or_moved(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();

        $this->accounts->setGrant($account, $admin, $this->workspace, ['configs.read']);
        $this->assertFalse($account->can('configs.write', $this->workspace->id));

        $this->expectException(ServiceAccountException::class);
        $this->accounts->setGrant($account, $admin, $this->otherWorkspace, ['configs.read']);
    }

    public function test_workspace_account_grant_cannot_be_removed(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();

        $this->expectException(ServiceAccountException::class);
        $this->accounts->removeGrant($account, $admin, $this->workspace);
    }

    public function test_issue_key_hash_only_with_clamped_expiry_and_two_key_limit(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();

        $issued = $this->accounts->issueKey($account, $admin, 'fleet-a', 9999);
        $this->assertStringStartsWith('atgsa-', $issued['plain']);
        $this->assertSame(38, strlen($issued['plain']));
        $this->assertSame(hash('sha256', $issued['plain']), $issued['token']->token);
        $this->assertNull($issued['token']->token_encrypted);
        $this->assertNull($issued['token']->user_id);
        $this->assertSame(substr($issued['plain'], -4), $issued['token']->last_four);
        $this->assertTrue($issued['token']->expires_at->between(now()->addDays(364), now()->addDays(366)));

        $this->accounts->issueKey($account, $admin, 'fleet-b');
        try {
            $this->accounts->issueKey($account, $admin, 'fleet-c');
            $this->fail('third key allowed');
        } catch (ServiceAccountException) {
        }

        $this->accounts->revokeKey($issued['token'], $admin, 'rotated');
        $this->accounts->issueKey($account, $admin, 'fleet-c');
        $this->assertSame(2, $account->activeKeys()->count());

        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%' . $issued['plain'] . '%')->exists());
    }

    public function test_revoked_key_stays_revoked(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();
        [, $key] = $this->accountKey($account);

        $this->accounts->revokeKey($key, $admin, 'leaked');
        $key->refresh();

        $this->assertSame('revoked', $key->status);
        $this->assertNotNull($key->revoked_at);
        $this->assertSame($admin->id, (int) $key->revoked_by_user_id);
        $this->assertSame('leaked', $key->revoke_reason);
    }

    public function test_transfer_owner_must_stay_in_organization(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $other = $this->makeUser('other@example.test', 101);
        $stranger = $this->makeUser('stranger@example.test', 101, 300);
        $account = $this->makeAccount();

        $this->accounts->transferOwner($account, $admin, $other);
        $this->assertSame($other->id, (int) $account->fresh()->owner_user_id);

        $this->expectException(ServiceAccountException::class);
        $this->accounts->transferOwner($account, $admin, $stranger);
    }

    public function test_assign_system_requires_register_grant_on_its_workspace(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $system = $this->makeSystem($admin);
        $account = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->otherWorkspace);

        try {
            $this->accounts->assignSystem($system, $account, $admin);
            $this->fail('assigned outside grant');
        } catch (ServiceAccountException) {
        }

        $good = $this->makeAccount();
        $this->accounts->assignSystem($system, $good, $admin);
        $this->assertSame($good->id, (int) $system->fresh()->service_account_id);
        $this->assertTrue(ActivityLog::where('event', 'system.assigned_to_service_account')->exists());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountServiceTest`
Expected: FAIL, `Class "App\Services\ServiceAccounts" not found`.

- [ ] **Step 3: Write the exception**

`app/Services/ServiceAccountException.php`:

```php
<?php

namespace App\Services;

use RuntimeException;

/**
 * A refused service account operation. The message is safe to show to the actor.
 */
class ServiceAccountException extends RuntimeException
{
}
```

- [ ] **Step 4: Write the service**

`app/Services/ServiceAccounts.php`:

```php
<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\ServiceAccountGrant;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ActivityRecorder;
use App\Support\ServiceAccountSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to service accounts, their grants and keys. Callers check who may do it
 * (ServiceAccountAccess); this class checks that the change itself is valid (scope,
 * organization, quotas, key limit) and records it.
 */
class ServiceAccounts
{
    public const MAX_ACTIVE_KEYS = 2;
    public const KEY_PREFIX = 'atgsa-';

    public function create(User $actor, array $data): ServiceAccount
    {
        $orgId = (int) ($actor->org_id ?? 200);
        $scope = (string) ($data['scope'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));

        if (!in_array($scope, [ServiceAccount::SCOPE_ORGANIZATION, ServiceAccount::SCOPE_WORKSPACE], true)) {
            throw new ServiceAccountException('Choose organization or workspace scope.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The name must be 1 to 100 characters.');
        }

        $workspace = null;
        if ($scope === ServiceAccount::SCOPE_WORKSPACE) {
            $workspace = $this->workspaceInOrg((int) ($data['workspace_id'] ?? 0), $orgId);
        }

        $owner = $actor;
        if (!empty($data['owner_user_id'])) {
            $owner = $this->userInOrg((int) $data['owner_user_id'], $orgId);
        }

        $account = DB::transaction(function () use ($actor, $data, $orgId, $scope, $name, $workspace, $owner) {
            // Serialize creates in the same quota bucket (no-op on SQLite).
            $workspace
                ? Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first()
                : Organization::query()->whereKey($orgId)->lockForUpdate()->first();

            $sameScope = ServiceAccount::query()->where('org_id', $orgId)->where('scope', $scope)
                ->when($workspace, fn ($query) => $query->where('workspace_id', $workspace->id));

            if ((clone $sameScope)->where('name', $name)->exists()) {
                throw new ServiceAccountException('A service account with this name already exists here.');
            }

            $quota = $workspace ? ServiceAccountSettings::workspaceQuota() : ServiceAccountSettings::orgQuota();
            if ((clone $sameScope)->where('status', 'active')->count() >= $quota) {
                throw new ServiceAccountException("The limit of {$quota} active service accounts is reached.");
            }

            $account = ServiceAccount::create([
                'name' => $name,
                'description' => $data['description'] ?? null,
                'team' => $data['team'] ?? null,
                'scope' => $scope,
                'org_id' => $orgId,
                'workspace_id' => $workspace?->id,
                'owner_user_id' => $owner->id,
                'created_by_user_id' => $actor->id,
                'status' => 'active',
            ]);

            if ($workspace) {
                $permissions = array_key_exists('permissions', $data)
                    ? ServiceAccount::normalizePermissions($data['permissions'])
                    : ServiceAccount::DEFAULT_PERMISSIONS;
                $account->grants()->create([
                    'workspace_id' => $workspace->id,
                    'permissions' => $permissions,
                    'status' => 'active',
                    'granted_by_user_id' => $actor->id,
                ]);
            }

            return $account;
        });

        $this->record($actor, $account, 'service_account.created', 'Created ' . $scope . ' service account ' . $account->name);

        return $account;
    }

    public function update(ServiceAccount $account, User $actor, array $data): void
    {
        $name = trim((string) ($data['name'] ?? $account->name));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The name must be 1 to 100 characters.');
        }

        $clash = ServiceAccount::query()
            ->where('org_id', $account->org_id)
            ->where('scope', $account->scope)
            ->where('workspace_id', $account->workspace_id)
            ->where('name', $name)
            ->whereKeyNot($account->id)
            ->exists();
        if ($clash) {
            throw new ServiceAccountException('A service account with this name already exists here.');
        }

        $account->update([
            'name' => $name,
            'description' => $data['description'] ?? null,
            'team' => $data['team'] ?? null,
        ]);

        $this->record($actor, $account, 'service_account.updated', 'Updated service account ' . $account->name);
    }

    public function setStatus(ServiceAccount $account, User $actor, bool $active): void
    {
        if ($active && !$account->isActive()) {
            DB::transaction(function () use ($account) {
                $bucket = ServiceAccount::query()->where('org_id', $account->org_id)->where('scope', $account->scope)
                    ->where('workspace_id', $account->workspace_id)->where('status', 'active');
                $quota = $account->isOrganizationScope() ? ServiceAccountSettings::orgQuota() : ServiceAccountSettings::workspaceQuota();
                if ($bucket->count() >= $quota) {
                    throw new ServiceAccountException("The limit of {$quota} active service accounts is reached.");
                }
                $account->update(['status' => 'active']);
            });
            $this->record($actor, $account, 'service_account.enabled', 'Enabled service account ' . $account->name);

            return;
        }

        if (!$active && $account->isActive()) {
            $account->update(['status' => 'disabled']);
            $this->record($actor, $account, 'service_account.disabled', 'Disabled service account ' . $account->name);
        }
    }

    public function transferOwner(ServiceAccount $account, User $actor, User $newOwner): void
    {
        $this->userInOrg((int) $newOwner->id, (int) $account->org_id);
        $account->update(['owner_user_id' => $newOwner->id]);

        $this->record($actor, $account, 'service_account.owner_transferred', 'Made ' . $newOwner->email . ' owner of service account ' . $account->name);
    }

    public function setGrant(ServiceAccount $account, User $actor, Workspace $workspace, array $permissions): ServiceAccountGrant
    {
        if ((int) ($workspace->org_id ?? 200) !== (int) $account->org_id) {
            throw new ServiceAccountException('The workspace is not in this organization.');
        }
        if (!$account->isOrganizationScope() && (int) $account->workspace_id !== (int) $workspace->id) {
            throw new ServiceAccountException('A workspace service account works only in its own workspace.');
        }

        $existing = $account->grants()->where('workspace_id', $workspace->id)->first();
        $grant = $account->grants()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'permissions' => ServiceAccount::normalizePermissions($permissions),
                'status' => 'active',
                'granted_by_user_id' => $actor->id,
            ],
        );

        $event = $existing && $existing->status === 'active' ? 'service_account.grant_changed' : 'service_account.grant_added';
        $this->record($actor, $account, $event, 'Set the access of ' . $account->name . ' in workspace ' . $workspace->name, (int) $workspace->id);

        return $grant;
    }

    public function removeGrant(ServiceAccount $account, User $actor, Workspace $workspace): void
    {
        if (!$account->isOrganizationScope()) {
            throw new ServiceAccountException("A workspace service account's own workspace cannot be removed; disable the account instead.");
        }

        $updated = $account->grants()->where('workspace_id', $workspace->id)->where('status', 'active')->update(['status' => 'revoked']);
        if ($updated > 0) {
            $this->record($actor, $account, 'service_account.grant_removed', 'Removed ' . $account->name . ' from workspace ' . $workspace->name, (int) $workspace->id);
        }
    }

    /**
     * @return array{token: PatToken, plain: string} plain is shown once and never stored
     */
    public function issueKey(ServiceAccount $account, User $actor, string $name, ?int $days = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The key name must be 1 to 100 characters.');
        }
        if (!$account->isActive()) {
            throw new ServiceAccountException('Enable the service account before creating keys.');
        }

        $days = max(1, min($days ?? ServiceAccountSettings::defaultKeyDays(), ServiceAccountSettings::maxKeyDays()));

        $issued = DB::transaction(function () use ($account, $name, $days) {
            ServiceAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($account->activeKeys()->count() >= self::MAX_ACTIVE_KEYS) {
                throw new ServiceAccountException('A service account can have at most ' . self::MAX_ACTIVE_KEYS . ' active keys. Revoke one first.');
            }

            do {
                $plain = self::KEY_PREFIX . Str::random(32);
            } while (PatToken::query()->where('token', hash('sha256', $plain))->exists());

            $token = PatToken::create([
                'tokenable_type' => ServiceAccount::class,
                'tokenable_id' => $account->id,
                'user_id' => null,
                'name' => $name,
                'token' => hash('sha256', $plain),
                'token_encrypted' => null,
                'last_four' => substr($plain, -4),
                'abilities' => [],
                'status' => 'active',
                'expires_at' => now()->addDays($days),
            ]);

            return ['token' => $token, 'plain' => $plain];
        });

        $this->record($actor, $account, 'service_account.key_created', 'Created key "' . $name . '" (…' . $issued['token']->last_four . ') for ' . $account->name);

        return $issued;
    }

    public function revokeKey(PatToken $key, ?User $actor, string $reason): void
    {
        if ($key->status === 'revoked') {
            return;
        }

        $key->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor?->id,
            'revoke_reason' => mb_substr($reason, 0, 50),
        ])->save();

        if ($key->isServiceAccountKey() && ($account = ServiceAccount::find($key->tokenable_id))) {
            $this->record($actor, $account, 'service_account.key_revoked', 'Revoked key "' . $key->name . '" (…' . $key->last_four . ') of ' . $account->name . ': ' . $reason);
        }
    }

    public function assignSystem(SystemRegister $system, ServiceAccount $account, User $actor): void
    {
        $workspaceId = (int) ($system->workspace_id ?? 0);
        if ((int) $account->org_id !== (int) ($system->org_id ?? 200) || !$account->can('systems.register', $workspaceId)) {
            throw new ServiceAccountException("The service account needs Register access in the system's workspace.");
        }

        $system->service_account_id = $account->id;
        $system->save();

        ActivityRecorder::record(
            (int) $actor->id,
            'system.assigned_to_service_account',
            'Assigned system ' . $system->system_name . ' to service account ' . $account->name,
            ActivityRecorder::SUCCESS,
            null,
            $workspaceId,
            (int) $system->id,
        );
    }

    private function workspaceInOrg(int $workspaceId, int $orgId): Workspace
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (!$workspace || (int) ($workspace->org_id ?? 200) !== $orgId) {
            throw new ServiceAccountException('The workspace is not in this organization.');
        }

        return $workspace;
    }

    private function userInOrg(int $userId, int $orgId): User
    {
        $user = User::query()->find($userId);
        if (!$user || (int) ($user->org_id ?? 200) !== $orgId || strtolower((string) ($user->status ?? 'active')) !== 'active') {
            throw new ServiceAccountException('The owner must be an active user of this organization.');
        }

        return $user;
    }

    private function record(?User $actor, ServiceAccount $account, string $event, string $description, ?int $workspaceId = null): void
    {
        ActivityRecorder::record($actor?->id, $event, $description, ActivityRecorder::SUCCESS, null, $workspaceId ?? ($account->workspace_id ? (int) $account->workspace_id : null));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=ServiceAccountServiceTest`
Expected: PASS (12 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/ServiceAccounts.php app/Services/ServiceAccountException.php tests/Feature/ServiceAccountServiceTest.php
git commit -m "feat(service-accounts): create, grants, keys, owner and system assignment"
```

---

### Task 3: Who may manage service accounts

**Files:**
- Create: `app/Support/ServiceAccountAccess.php`
- Modify: `app/Models/Workspace.php` (`PERMISSIONS`)
- Test: `tests/Feature/ServiceAccountAccessTest.php`

**Interfaces:**
- Consumes: `ServiceAccount`, `Workspace::allows()`.
- Produces (`App\Support\ServiceAccountAccess`, all static):
  - `canCreateOrganizationAccount(User $actor): bool`
  - `canManageWorkspaceAccounts(User $actor, Workspace $workspace): bool`
  - `canManage(User $actor, ServiceAccount $account): bool`
  - `canGrant(User $actor, ServiceAccount $account, Workspace $workspace): bool`
  - `canAssignSystem(User $actor, SystemRegister $system): bool`
  - `findForActor(User $actor, int $id): ?ServiceAccount` (null for other orgs and for accounts the actor cannot see)
  - `visibleQuery(User $actor): Builder`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Support\ServiceAccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountAccessTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_admins_manage_organization_accounts_members_do_not(): void
    {
        $this->assertTrue(ServiceAccountAccess::canCreateOrganizationAccount($this->makeUser('sa@example.test', 100)));
        $this->assertTrue(ServiceAccountAccess::canCreateOrganizationAccount($this->makeUser('a@example.test', 101)));
        $this->assertFalse(ServiceAccountAccess::canCreateOrganizationAccount($this->makeUser('u@example.test', 102)));
    }

    public function test_workspace_admin_needs_service_accounts_permission(): void
    {
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($wsAdmin->id, true);

        $this->assertFalse(ServiceAccountAccess::canManageWorkspaceAccounts($wsAdmin, $this->workspace));

        $this->workspace->setPermissions($wsAdmin->id, ['service_accounts' => true]);
        $this->assertTrue(ServiceAccountAccess::canManageWorkspaceAccounts($wsAdmin, $this->workspace->fresh()));
        $this->assertFalse(ServiceAccountAccess::canManageWorkspaceAccounts($wsAdmin, $this->otherWorkspace));
        $this->assertFalse(ServiceAccountAccess::canCreateOrganizationAccount($wsAdmin));
    }

    public function test_member_cannot_manage(): void
    {
        $member = $this->makeUser('member@example.test');
        $this->workspace->addUser($member->id, false);

        $this->assertFalse(ServiceAccountAccess::canManageWorkspaceAccounts($member, $this->workspace));
        $this->assertFalse(ServiceAccountAccess::canManage($member, $this->makeAccount()));
    }

    public function test_workspace_admin_cannot_manage_or_grant_organization_accounts(): void
    {
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($wsAdmin->id, true);
        $this->workspace->setPermissions($wsAdmin->id, ['service_accounts' => true]);
        $orgAccount = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);

        $this->assertFalse(ServiceAccountAccess::canManage($wsAdmin, $orgAccount));
        $this->assertFalse(ServiceAccountAccess::canGrant($wsAdmin, $orgAccount, $this->workspace));
    }

    public function test_other_organization_is_invisible(): void
    {
        $admin = $this->makeUser('a@example.test', 101);
        $theirs = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);

        $this->assertNull(ServiceAccountAccess::findForActor($admin, $theirs->id));
        $this->assertFalse(ServiceAccountAccess::canManage($admin, $theirs));
        $this->assertSame(0, ServiceAccountAccess::visibleQuery($admin)->whereKey($theirs->id)->count());
    }

    public function test_admin_of_org_sees_both_scopes(): void
    {
        $admin = $this->makeUser('a@example.test', 101);
        $ws = $this->makeAccount();
        $org = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);

        $this->assertEqualsCanonicalizing([$ws->id, $org->id], ServiceAccountAccess::visibleQuery($admin)->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountAccessTest`
Expected: FAIL, `Class "App\Support\ServiceAccountAccess" not found`.

- [ ] **Step 3: Add the workspace permission**

In `app/Models/Workspace.php`, add the last entry of `PERMISSIONS`:

```php
        'notifications' => ['label' => 'Change Notifications', 'default' => false],
        'service_accounts' => ['label' => 'Manage service accounts of this workspace', 'default' => false],
    ];
```

- [ ] **Step 4: Write the access class**

`app/Support/ServiceAccountAccess.php`:

```php
<?php

namespace App\Support;

use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and change service accounts. Super admin and admin: everything in their
 * organization. Workspace admin with the service_accounts permission: workspace accounts
 * of that workspace only. Nobody else, and never another organization.
 */
final class ServiceAccountAccess
{
    public static function canCreateOrganizationAccount(User $actor): bool
    {
        return self::isOrgAdmin($actor);
    }

    public static function canManageWorkspaceAccounts(User $actor, Workspace $workspace): bool
    {
        if ((int) ($workspace->org_id ?? 200) !== self::orgId($actor)) {
            return false;
        }

        return self::isOrgAdmin($actor) || $workspace->allows($actor, 'service_accounts');
    }

    public static function canManage(User $actor, ServiceAccount $account): bool
    {
        if ((int) $account->org_id !== self::orgId($actor)) {
            return false;
        }
        if (self::isOrgAdmin($actor)) {
            return true;
        }
        if ($account->isOrganizationScope() || !$account->workspace) {
            return false;
        }

        return $account->workspace->allows($actor, 'service_accounts');
    }

    public static function canGrant(User $actor, ServiceAccount $account, Workspace $workspace): bool
    {
        if ((int) ($workspace->org_id ?? 200) !== (int) $account->org_id) {
            return false;
        }
        if ($account->isOrganizationScope()) {
            return self::isOrgAdmin($actor) && (int) $account->org_id === self::orgId($actor);
        }

        return (int) $account->workspace_id === (int) $workspace->id && self::canManage($actor, $account);
    }

    public static function canAssignSystem(User $actor, SystemRegister $system): bool
    {
        $workspace = $system->workspace;
        if (!$workspace || (int) ($system->org_id ?? 200) !== self::orgId($actor)) {
            return false;
        }

        return self::isOrgAdmin($actor) || $workspace->allows($actor, 'service_accounts');
    }

    public static function findForActor(User $actor, int $id): ?ServiceAccount
    {
        $account = self::visibleQuery($actor)->whereKey($id)->first();

        return $account && self::canManage($actor, $account) ? $account : null;
    }

    /**
     * Accounts the actor may list: all of the organization for admins; for others, the
     * workspace accounts of workspaces where they hold the service_accounts permission.
     */
    public static function visibleQuery(User $actor): Builder
    {
        $query = ServiceAccount::query()->where('org_id', self::orgId($actor));
        if (self::isOrgAdmin($actor)) {
            return $query;
        }

        $workspaceIds = $actor->workspaces()->get()
            ->filter(fn (Workspace $workspace) => $workspace->allows($actor, 'service_accounts'))
            ->pluck('id')
            ->all();

        return $query->where('scope', ServiceAccount::SCOPE_WORKSPACE)->whereIn('workspace_id', $workspaceIds ?: [0]);
    }

    private static function isOrgAdmin(User $actor): bool
    {
        return in_array((int) ($actor->rbac_id ?? 0), [100, 101], true);
    }

    private static function orgId(User $actor): int
    {
        return (int) ($actor->org_id ?? 200);
    }
}
```

`Workspace::allows()` already returns all permissions for Admin-role users (100, 101) of the workspace's org via `canBeManagedBy`. For 101 users who are not a workspace admin, `isOrgAdmin` covers them.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=ServiceAccountAccessTest`
Expected: PASS (6 tests). Also run `php artisan test --filter=WorkspaceSettingsTest`: the new permission must not break the Members tab tests. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ServiceAccountAccess.php app/Models/Workspace.php tests/Feature/ServiceAccountAccessTest.php
git commit -m "feat(service-accounts): server-side access rules"
```

---

### Task 4: Authenticate `atgsa-` keys on opted-in routes

**Files:**
- Create: `app/Support/ApiIdentity.php`
- Modify: `app/Http/Middleware/AuthenticatePatToken.php`
- Modify: `routes/api.php` (v1 `whoami` and system group use `auth.pat:service`)
- Test: `tests/Feature/ServiceAccountAuthTest.php`

**Interfaces:**
- Consumes: Task 1 models.
- Produces:
  - `App\Support\ApiIdentity` (final, readonly props): `?User $user`, `?ServiceAccount $serviceAccount`, `PatToken $token`; `static current(?Request $request = null): ?ApiIdentity`; `isServiceAccount(): bool`; `orgId(): int`; `label(): string` (user email or `service account <name>`).
  - Request attributes: `patToken` (as today), `identity` (`ApiIdentity`).
  - Middleware signature: `AuthenticatePatToken::handle(Request $request, Closure $next, string $accepts = 'user')`; `auth.pat` = user keys only; `auth.pat:service` = user or service account keys.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountAuthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountAuthTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_account_key_works_on_v1_whoami(): void
    {
        [$plain] = $this->accountKey($this->makeAccount());

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }

    public function test_expired_revoked_and_disabled_are_401(): void
    {
        $account = $this->makeAccount();
        [$expired] = $this->accountKey($account, ['expires_at' => now()->subMinute()]);
        [$revoked] = $this->accountKey($account, ['status' => 'revoked', 'revoked_at' => now()]);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $expired])->assertStatus(401)->assertJsonPath('message', 'Key has expired');
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $revoked])->assertStatus(401)->assertJsonPath('message', 'Key is revoked');

        $disabled = $this->makeAccount();
        [$plain] = $this->accountKey($disabled);
        $disabled->update(['status' => 'disabled']);
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401)->assertJsonPath('message', 'Identity is disabled');
    }

    public function test_prefix_must_match_owner_type(): void
    {
        $user = $this->makeUser('u@example.test');
        [$userPlain] = $this->userKey($user);
        $relabelled = 'atgsa-' . substr($userPlain, 6);
        // Same hash lookup must not succeed with the other prefix.
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $relabelled])->assertStatus(401);

        // An atgsa- row stored with a User owner is rejected.
        $account = $this->makeAccount();
        [$plain, $token] = $this->accountKey($account);
        $token->forceFill(['tokenable_type' => \App\Models\User::class, 'tokenable_id' => $user->id])->save();
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401)->assertJsonPath('message', 'Invalid key');
    }

    public function test_account_key_refused_on_legacy_and_mcp_routes(): void
    {
        [$plain] = $this->accountKey($this->makeAccount());
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $this->getJson('/api/config-files', $headers)->assertStatus(401);
        $this->postJson('/api/system-register', [], $headers)->assertStatus(401);
        $this->getJson('/api/mcp/me', $headers)->assertStatus(401);
        $this->getJson('/api/services', $headers)->assertStatus(401)
            ->assertJsonPath('message', 'Service account keys work only with the atglance CLI API (/api/v1)');
    }

    public function test_user_keys_still_work_everywhere(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain] = $this->userKey($user);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertOk();
        $this->getJson('/api/config-files', ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }

    public function test_inactive_user_key_is_refused(): void
    {
        $user = $this->makeUser('u@example.test', 102, 200, 'inactive');
        [$plain] = $this->userKey($user);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401)->assertJsonPath('message', 'Identity is disabled');
    }

    public function test_unknown_prefix_is_403_as_before(): void
    {
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer nope-123'])->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountAuthTest`
Expected: FAIL (account key gets 403 "Invalid token format").

- [ ] **Step 3: Write `ApiIdentity`**

`app/Support/ApiIdentity.php`:

```php
<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is behind an API request: a person (atgla- key) or a service account (atgsa- key).
 * Set by AuthenticatePatToken as the request attribute "identity".
 */
final class ApiIdentity
{
    public function __construct(
        public readonly ?User $user,
        public readonly ?ServiceAccount $serviceAccount,
        public readonly PatToken $token,
    ) {
    }

    public static function current(?Request $request = null): ?self
    {
        $request ??= app()->bound('request') ? app('request') : null;
        $identity = $request?->attributes->get('identity');

        return $identity instanceof self ? $identity : null;
    }

    public function isServiceAccount(): bool
    {
        return $this->serviceAccount !== null;
    }

    public function orgId(): int
    {
        return (int) ($this->isServiceAccount() ? $this->serviceAccount->org_id : ($this->user->org_id ?? 200));
    }

    public function label(): string
    {
        return $this->isServiceAccount()
            ? 'service account ' . $this->serviceAccount->name
            : (string) ($this->user->email ?? ('user ' . $this->user?->id));
    }
}
```

- [ ] **Step 4: Rewrite the middleware**

`app/Http/Middleware/AuthenticatePatToken.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\ApiIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API key authentication. atgla- keys belong to a person; atgsa- keys to a service
 * account and are accepted only where the route opts in with auth.pat:service. A
 * failed check never falls back to another identity.
 */
class AuthenticatePatToken
{
    private const USER_PREFIX = 'atgla-';
    private const SERVICE_PREFIX = 'atgsa-';

    public function handle(Request $request, Closure $next, string $accepts = 'user'): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'PAT token required'], 401);
        }

        $isService = str_starts_with($token, self::SERVICE_PREFIX);
        if (!$isService && !str_starts_with($token, self::USER_PREFIX)) {
            return response()->json(['message' => 'Invalid token format. PAT token required (atgla-...)'], 403);
        }

        if ($isService && $accepts !== 'service') {
            return response()->json(['message' => 'Service account keys work only with the atglance CLI API (/api/v1)'], 401);
        }

        $patToken = PatToken::where('token', hash('sha256', $token))->first();
        $expectedType = $isService ? ServiceAccount::class : User::class;

        if (!$patToken || $patToken->tokenable_type !== $expectedType) {
            return response()->json(['message' => 'Invalid key'], 401);
        }

        if (($patToken->status ?? 'active') !== 'active' || $patToken->revoked_at !== null) {
            return response()->json(['message' => 'Key is revoked'], 401);
        }

        if ($patToken->expires_at && $patToken->expires_at->isPast()) {
            return response()->json(['message' => 'Key has expired'], 401);
        }

        $user = $isService ? null : User::query()->find($patToken->tokenable_id);
        $account = $isService ? ServiceAccount::query()->find($patToken->tokenable_id) : null;

        $active = $isService
            ? ($account?->isActive() ?? false)
            : ($user !== null && strtolower((string) ($user->status ?? 'active')) === 'active');
        if (!$active) {
            return response()->json(['message' => 'Identity is disabled'], 401);
        }

        $patToken->forceFill(['last_used_at' => now()])->save();

        // A service account has no user: routes that need a person refuse it.
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('patToken', $patToken);
        $request->attributes->set('identity', new ApiIdentity($user, $account, $patToken));

        return $next($request);
    }
}
```

Note: an inactive user now gets 401 `Identity is disabled` here instead of 403 `User is inactive` from `active.user`. Check `UserApiAuthTest` and `CliApiV1Test` for asserts on `User is inactive` / 403 and update those asserts to 401 and `Identity is disabled` (spec 5.1 step 5).

- [ ] **Step 5: Opt v1 routes in**

In `routes/api.php`, inside `Route::prefix('v1')`:

```php
    Route::get('/whoami', [V1MetaController::class, 'whoami'])->middleware(['auth.pat:service', 'active.user'])->name('whoami');
    Route::post('/systems', [V1SystemController::class, 'store'])->middleware(['auth.pat', 'active.user'])->name('systems.store');
    Route::middleware(['auth.pat:service', 'active.user', 'atglance.system'])->group(function () {
```

(`systems.store` stays `auth.pat`: a person must authorize registration. The rest of the group is unchanged.)

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=ServiceAccountAuthTest`
Expected: PASS (7 tests). Then `php artisan test`. Expected: PASS after updating the inactive-user asserts named in Step 4.

- [ ] **Step 7: Commit**

```bash
git add app/Support/ApiIdentity.php app/Http/Middleware/AuthenticatePatToken.php routes/api.php tests/Feature
git commit -m "feat(service-accounts): authenticate atgsa- keys on v1 routes only"
```

---

### Task 5: `GET /v1/whoami` for both identities

**Files:**
- Modify: `app/Http/Controllers/Api/V1/MetaController.php`
- Test: `tests/Feature/ServiceAccountWhoamiTest.php`

**Interfaces:**
- Consumes: `ApiIdentity::current()`, `ServiceAccount->grants()`.
- Produces: JSON contract of spec 5.5.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountWhoamiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountWhoamiTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_service_account_whoami(): void
    {
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION, null, ['name' => 'org-agent']);
        $this->grant($account, $this->workspace, ['configs.read']);
        $this->grant($account, $this->otherWorkspace);
        $account->grants()->where('workspace_id', $this->otherWorkspace->id)->update(['status' => 'revoked']);
        [$plain, $token] = $this->accountKey($account, ['name' => 'fleet-a']);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])
            ->assertOk()
            ->assertJsonPath('identity_type', 'service_account')
            ->assertJsonPath('service_account.id', $account->id)
            ->assertJsonPath('service_account.name', 'org-agent')
            ->assertJsonPath('service_account.scope', 'organization')
            ->assertJsonPath('service_account.org_id', 200)
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.id', $this->workspace->id)
            ->assertJsonPath('workspaces.0.name', 'production')
            ->assertJsonPath('workspaces.0.permissions', ['configs.read'])
            ->assertJsonPath('token.id', $token->id)
            ->assertJsonPath('token.name', 'fleet-a')
            ->assertJsonMissingPath('user');
    }

    public function test_user_whoami_has_identity_type(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain] = $this->userKey($user);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])
            ->assertOk()
            ->assertJsonPath('identity_type', 'user')
            ->assertJsonPath('user.id', $user->id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountWhoamiTest`
Expected: FAIL (`identity_type` missing; service account response calls `$user->id` on null).

- [ ] **Step 3: Implement**

Replace `whoami` in `app/Http/Controllers/Api/V1/MetaController.php`:

```php
    public function whoami(Request $request): JsonResponse
    {
        $identity = ApiIdentity::current($request);
        $token = $identity->token;
        $tokenInfo = [
            'id' => $token->id,
            'name' => $token->name,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];

        if ($identity->isServiceAccount()) {
            $account = $identity->serviceAccount;

            return response()->json([
                'identity_type' => 'service_account',
                'service_account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'scope' => $account->scope,
                    'org_id' => (int) $account->org_id,
                ],
                'workspaces' => $account->grants()
                    ->where('status', 'active')
                    ->with('workspace:id,name')
                    ->orderBy('workspace_id')
                    ->get()
                    ->map(fn ($grant) => [
                        'id' => (int) $grant->workspace_id,
                        'name' => $grant->workspace?->name,
                        'permissions' => array_values((array) $grant->permissions),
                    ])
                    ->values(),
                'token' => $tokenInfo,
            ]);
        }

        $user = $identity->user;

        return response()->json([
            'identity_type' => 'user',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'status' => $user->status ?? 'active',
                'org_id' => $user->org_id,
            ],
            'token' => $tokenInfo,
        ]);
    }
```

Add `use App\Support\ApiIdentity;` at the top and update the class docblock: "/api/v1 discovery and identity for the atglance CLI v2, for user and service account keys."

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter="ServiceAccountWhoamiTest|CliApiV1Test"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/MetaController.php tests/Feature/ServiceAccountWhoamiTest.php
git commit -m "feat(service-accounts): whoami reports identity type, scope and grants"
```

---

### Task 6: Two-key registration and the registration setting

**Files:**
- Modify: `app/Http/Controllers/Api/V1/SystemController.php` (`store`)
- Modify: `app/Http/Controllers/Api/SystemRegisterController.php` (`createSystem`, `store`)
- Create: `app/Support/ServiceAccountKeyHeader.php`
- Test: `tests/Feature/ServiceAccountRegistrationTest.php`

**Interfaces:**
- Consumes: `ServiceAccountSettings::requireForRegistration()`, `ServiceAccount->can()`.
- Produces:
  - `ServiceAccountKeyHeader::HEADER = 'X-Atglance-Service-Account-Key'`
  - `ServiceAccountKeyHeader::resolve(Request $request): array{0: ?ServiceAccount, 1: ?JsonResponse}` — `[null, null]` when the header is absent; `[null, 401 response]` when invalid; `[account, null]` when valid.
  - `SystemRegisterController::createSystem(Request $request, array $data, ?ServiceAccount $account = null)` — stores `service_account_id`.
  - `SystemRegister::created` audit names the account (via `ApiIdentity`/data, see Step 4).

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountRegistrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountRegistrationTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'system_name' => 'db-01',
            'os_type' => 'linux',
            'ip_address' => '10.0.0.9',
            'workspace_id' => $this->workspace->id,
            'system_key' => str_repeat('a', 40),
        ], $overrides);
    }

    private function headers(string $userKey, ?string $accountKey): array
    {
        return array_filter([
            'Authorization' => 'Bearer ' . $userKey,
            'X-Atglance-Service-Account-Key' => $accountKey,
        ]);
    }

    public function test_both_keys_register_a_system_owned_by_the_account(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey, $userToken] = $this->userKey($user);
        $account = $this->makeAccount();
        [$accountKey] = $this->accountKey($account);

        $response = $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $accountKey))->assertCreated();

        $system = SystemRegister::findOrFail($response->json('system.id'));
        $this->assertSame($account->id, (int) $system->service_account_id);
        $this->assertSame($user->id, (int) $system->user_id);
        $this->assertSame($userToken->id, (int) $system->pat_token_id);
        $response->assertJsonPath('system.service_account_id', $account->id);
        $this->assertTrue(ActivityLog::where('event', 'system.registered')->where('description', 'like', '%' . $account->name . '%')->exists());
    }

    public function test_user_key_alone_works_when_setting_off_and_fails_when_on(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey] = $this->userKey($user);

        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, null))->assertCreated();

        ServiceAccountSettings::save(['sa_require_for_registration' => true]);
        $this->postJson('/api/v1/systems', $this->body(['system_name' => 'db-02']), $this->headers($userKey, null))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registration needs a user key and a service account key');

        $this->postJson('/api/system-register', ['system_name' => 'db-03', 'os_type' => 'linux', 'ip_address' => '10.0.0.3'], ['Authorization' => 'Bearer ' . $userKey])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registration needs a user key and a service account key');
    }

    public function test_account_key_alone_is_401(): void
    {
        [$accountKey] = $this->accountKey($this->makeAccount());

        $this->postJson('/api/v1/systems', $this->body(), ['Authorization' => 'Bearer ' . $accountKey])->assertStatus(401);
    }

    public function test_account_without_grant_or_permission_is_refused(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey] = $this->userKey($user);

        $elsewhere = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->otherWorkspace);
        [$elsewhereKey] = $this->accountKey($elsewhere);
        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $elsewhereKey))
            ->assertStatus(403)->assertJsonPath('message', 'Service account lacks systems.register in this workspace');

        $readOnly = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($readOnly, $this->workspace, ['configs.read']);
        [$readOnlyKey] = $this->accountKey($readOnly);
        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $readOnlyKey))->assertStatus(403);
    }

    public function test_account_of_other_organization_or_bad_key_is_refused(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey] = $this->userKey($user);

        $theirs = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);
        [$theirKey] = $this->accountKey($theirs);
        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $theirKey))->assertStatus(401);

        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, 'atgsa-' . str_repeat('x', 32)))->assertStatus(401)
            ->assertJsonPath('message', 'Invalid service account key');

        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $userKey))->assertStatus(401);
        $this->assertSame(0, SystemRegister::count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountRegistrationTest`
Expected: FAIL (`service_account_id` is null; setting not enforced).

- [ ] **Step 3: Write the header resolver**

`app/Support/ServiceAccountKeyHeader.php`:

```php
<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The service account key sent next to a user key when registering a system
 * (X-Atglance-Service-Account-Key). Same checks as AuthenticatePatToken; any failure,
 * including an account of another organization, is the same 401.
 */
final class ServiceAccountKeyHeader
{
    public const HEADER = 'X-Atglance-Service-Account-Key';

    /**
     * @return array{0: ?ServiceAccount, 1: ?JsonResponse}
     */
    public static function resolve(Request $request, int $orgId): array
    {
        $plain = trim((string) $request->header(self::HEADER, ''));
        if ($plain === '') {
            return [null, null];
        }

        $invalid = [null, response()->json(['message' => 'Invalid service account key'], 401)];
        if (!str_starts_with($plain, 'atgsa-')) {
            return $invalid;
        }

        $token = PatToken::query()->where('token', hash('sha256', $plain))->first();
        if (
            !$token
            || $token->tokenable_type !== ServiceAccount::class
            || $token->status !== 'active'
            || $token->revoked_at !== null
            || ($token->expires_at && $token->expires_at->isPast())
        ) {
            return $invalid;
        }

        $account = ServiceAccount::query()->find($token->tokenable_id);
        if (!$account || !$account->isActive() || (int) $account->org_id !== $orgId) {
            return $invalid;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return [$account, null];
    }
}
```

- [ ] **Step 4: Store the account on the system and audit it**

In `SystemRegisterController`:

1. Change the signature and the create call of `createSystem`:

```php
    public function createSystem(Request $request, array $data, ?ServiceAccount $account = null)
```

and add to the `SystemRegister::create([...])` array, after `'user_id' => $user->id,`:

```php
            'service_account_id' => $account?->id,
```

Add `use App\Models\ServiceAccount;` and `use App\Support\ServiceAccountSettings;`.

2. At the top of `store()` (legacy 1.x route), before `$request->validate(...)`:

```php
        if (ServiceAccountSettings::requireForRegistration()) {
            return response()->json(['message' => 'Registration needs a user key and a service account key'], 422);
        }
```

In `app/Models/SystemRegister.php`, change the `created` audit so it names the account:

```php
        static::created(function (SystemRegister $system) {
            $account = $system->service_account_id ? ServiceAccount::query()->find($system->service_account_id) : null;
            ActivityRecorder::record(
                (int) $system->user_id,
                'system.registered',
                'Registered system ' . $system->system_name . ($account ? ' as service account ' . $account->name : ''),
                ActivityRecorder::SUCCESS,
                null,
                $system->workspace_id ? (int) $system->workspace_id : null,
                (int) $system->id,
            );
```

(keep the rest of the closure unchanged; `ServiceAccount` is in the same namespace).

- [ ] **Step 5: Two-key `store` in v1**

In `app/Http/Controllers/Api/V1/SystemController.php`, replace the body of `store` up to `$system = $this->systems->createSystem(...)`:

```php
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = (int) ($user->org_id ?? 200);

        [$account, $denied] = ServiceAccountKeyHeader::resolve($request, $orgId);
        if ($denied) {
            return $denied;
        }
        if ($account === null && ServiceAccountSettings::requireForRegistration()) {
            return response()->json(['message' => 'Registration needs a user key and a service account key'], 422);
        }

        $data = $request->validate(SystemRegisterController::systemFieldRules() + [
            // A workspace of the user's org, and one the user is a member of (admins: any).
            'workspace_id' => array_merge(SystemRegisterController::workspaceIdRule($orgId), [
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    $workspaceId = (int) $value;
                    if ($workspaceId > 0 && !$user->isAdmin() && !$user->workspaces()->whereKey($workspaceId)->exists()) {
                        $fail('The selected workspace is invalid.');
                    }
                },
            ]),
            'system_key' => ['required', 'string', 'min:32', 'max:255'],
        ]);

        if ($account !== null && !$account->can('systems.register', (int) ($data['workspace_id'] ?? 0))) {
            return response()->json(['message' => 'Service account lacks systems.register in this workspace'], 403);
        }

        $data['validation_hash'] = $data['system_key'];
        unset($data['system_key']);

        $system = $this->systems->createSystem($request, $data, $account);
```

Add `'service_account_id' => $system->service_account_id ? (int) $system->service_account_id : null,` to the `system` array of the 201 response. Imports: `App\Support\ServiceAccountKeyHeader`, `App\Support\ServiceAccountSettings`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="ServiceAccountRegistrationTest|CliApiV1Test"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/ServiceAccountKeyHeader.php app/Http/Controllers/Api app/Models/SystemRegister.php tests/Feature/ServiceAccountRegistrationTest.php
git commit -m "feat(service-accounts): two-key system registration and require setting"
```

---

### Task 7: Server routes as a service account

**Files:**
- Create: `app/Http/Middleware/RequireServiceAccountPermission.php`
- Modify: `bootstrap/app.php` (alias `sa.permission`)
- Modify: `routes/api.php` (permissions on v1 system routes)
- Modify: `app/Http/Middleware/AuthenticateSystemKey.php`
- Modify: `app/Http/Controllers/Api/FileController.php` (`uploadConfigFile`)
- Modify: `app/Http/Controllers/Api/V1/ConfigFileController.php` (`index`)
- Modify: `app/Http/Controllers/Api/V1/SystemController.php` (`rotateKey`, `deregister`, `reactivate`, `authorizeChange`)
- Modify: `app/Http/Controllers/Api/SystemRegisterController.php` (`canManageSystem`, `rotateSystemKey`)
- Modify: `app/Models/SystemRegister.php` (deregister and reactivate audit with actor and original owner)
- Test: `tests/Feature/ServiceAccountSystemRoutesTest.php`

**Interfaces:**
- Consumes: `ApiIdentity`, `ServiceAccount->can()`, request attribute `system`.
- Produces:
  - `sa.permission:<permission>` middleware: no-op for user identities.
  - `SystemRegisterController::rotateSystemKey(SystemRegister $system, string $newKey, ?User $actor, ?ServiceAccount $account = null): void`
  - `SystemRegisterController::canManageSystem(User $user, SystemRegister $system): bool` now also true for a workspace admin of the system's workspace.
  - `SystemRegister::actorFacts(): array` — `['Actor' => ..., 'Original owner' => ...]` used by the workspace notification in Task 9.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountSystemRoutesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ConfigurationFile;
use App\Models\ServiceAccount;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountSystemRoutesTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Storage::fake('local');
    }

    private function upload(array $headers, string $service = 'sshd')
    {
        return $this->post('/api/v1/config-files', [
            'service_name' => $service,
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"),
        ], $headers + ['Accept' => 'application/json']);
    }

    /**
     * @return array{0: \App\Models\SystemRegister, 1: array<string, string>, 2: ServiceAccount}
     */
    private function accountSystem(?array $permissions = null): array
    {
        $registrar = $this->makeUser('registrar@example.test');
        $account = $this->makeAccount();
        if ($permissions !== null) {
            $this->grant($account, $this->workspace, $permissions);
        }
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);

        return [$system, ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system), $account];
    }

    public function test_upload_list_and_show_as_service_account(): void
    {
        [$system, $headers, $account] = $this->accountSystem();

        $id = $this->upload($headers)->assertCreated()->json('file.id');
        $file = ConfigurationFile::findOrFail($id);
        $this->assertNull($file->user_id);
        $this->assertSame($account->id, (int) $file->service_account_id);

        $this->getJson('/api/v1/config-files', $headers)->assertOk()
            ->assertJsonPath('files.0.id', $id)
            ->assertJsonPath('files.0.created_by.service_account.id', $account->id);
        $this->get('/api/v1/config-files/' . $id, $headers)->assertOk();
        $this->getJson('/api/v1/systems/self', $headers)->assertOk();
    }

    public function test_upload_continues_version_history_of_person_owned_service(): void
    {
        [$system, $headers, $account] = $this->accountSystem();
        $registrar = \App\Models\User::where('email', 'registrar@example.test')->first();
        Service::create(['service_name' => 'sshd', 'system_id' => $system->id, 'user_id' => $registrar->id, 'status' => 'active']);

        $this->upload($headers)->assertCreated();

        $this->assertSame(1, Service::where('system_id', $system->id)->where('service_name', 'sshd')->count());
    }

    public function test_each_permission_is_enforced(): void
    {
        [, $headers] = $this->accountSystem(['configs.read']);
        $this->upload($headers)->assertStatus(403)->assertJsonPath('message', 'Service account lacks configs.write in this workspace');
        $this->getJson('/api/v1/config-files', $headers)->assertOk();

        [, $writeOnly] = $this->accountSystem(['configs.write']);
        $this->getJson('/api/v1/config-files', $writeOnly)->assertStatus(403);

        $this->postJson('/api/v1/systems/self/rotate-key', ['new_key' => str_repeat('b', 40)], $writeOnly)->assertStatus(403);
    }

    public function test_rotate_key_with_permission_needs_no_pin(): void
    {
        [$system, $headers] = $this->accountSystem(['systems.rotate_key', 'configs.read']);

        $this->postJson('/api/v1/systems/self/rotate-key', ['new_key' => str_repeat('b', 40)], $headers)
            ->assertOk()->assertJsonPath('rotated', true);
        $this->assertSame(str_repeat('b', 40), $system->fresh()->validation_hash);
        $this->assertTrue(ActivityLog::where('event', 'system.key_rotated')->whereNull('user_id')->where('description', 'like', '%service account%')->exists());
    }

    public function test_deregister_and_reactivate_refuse_service_accounts(): void
    {
        [, $headers] = $this->accountSystem();

        foreach (['deregister', 'reactivate'] as $action) {
            $this->postJson('/api/v1/systems/self/' . $action, ['pin' => '1234'], $headers)
                ->assertStatus(403)
                ->assertJsonPath('message', 'Deregister and reactivate need a user key with password or PIN');
        }
    }

    public function test_account_cannot_use_system_of_other_account_or_workspace(): void
    {
        [$system] = $this->accountSystem();
        $other = $this->makeAccount();
        [$otherKey] = $this->accountKey($other);

        $this->getJson('/api/v1/systems/self', ['Authorization' => 'Bearer ' . $otherKey] + $this->systemHeaders($system))->assertNotFound();
    }

    public function test_revoked_grant_blocks_next_upload_with_404(): void
    {
        $registrar = $this->makeUser('registrar@example.test');
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($account, $this->workspace);
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);
        $headers = ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system);

        $this->upload($headers)->assertCreated();
        $account->grants()->update(['status' => 'revoked']);
        $this->upload($headers)->assertNotFound();
    }

    public function test_workspace_admin_may_deregister_after_owner_left(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($owner->id);
        $this->workspace->addUser($wsAdmin->id, true);
        $system = $this->makeSystem($owner);
        $owner->update(['status' => 'inactive']);
        [$plain] = $this->userKey($wsAdmin);

        $this->postJson('/api/v1/systems/self/deregister', ['pin' => '1234'], ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system))
            ->assertOk();

        $log = ActivityLog::where('event', 'system.deregistered')->latest('id')->first();
        $this->assertSame($wsAdmin->id, (int) $log->user_id);
        $this->assertStringContainsString('owner owner@example.test', $log->description);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountSystemRoutesTest`
Expected: FAIL (system middleware calls `userMayUseSystem` with a null user, so 404 for every request).

- [ ] **Step 3: System key middleware, service account branch**

In `AuthenticateSystemKey::handle`, replace the block from `$user = $request->user();` to the 404 return with:

```php
        $identity = ApiIdentity::current($request);
        $user = $request->user();
        $system = preg_match('/^[0-9]{1,18}$/', $systemId) ? SystemRegister::find((int) $systemId) : null;

        $keyMatches = $system !== null
            && !empty($system->validation_hash)
            && hash_equals((string) $system->validation_hash, $key)
            && $identity !== null
            && (int) ($system->org_id ?? 200) === $identity->orgId();

        $allowed = $keyMatches && ($identity->isServiceAccount()
            ? self::accountMayUseSystem($identity->serviceAccount, $system)
            : ($user !== null && self::userMayUseSystem($user, $system)));

        if (!$allowed) {
            return response()->json(['message' => 'System not found'], 404);
        }
```

and add:

```php
    /**
     * A service account acts only for systems it owns, and only while it holds an active
     * grant on the system's workspace (any permission; each route checks its own).
     */
    public static function accountMayUseSystem(ServiceAccount $account, SystemRegister $system): bool
    {
        return (int) $system->service_account_id === (int) $account->id
            && $account->isActive()
            && $account->activeGrantFor((int) ($system->workspace_id ?? 0)) !== null;
    }
```

Imports: `App\Models\ServiceAccount`, `App\Support\ApiIdentity`. Update the class docblock: "...the caller is the owner, an admin or a member of the system's workspace, or the service account the system runs as, with a grant on its workspace."

- [ ] **Step 4: Permission middleware and routes**

`app/Http/Middleware/RequireServiceAccountPermission.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\ApiIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * sa.permission:<permission>, after atglance.system. A service account must hold the
 * permission on the calling system's workspace. Person keys pass through unchanged.
 */
class RequireServiceAccountPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $identity = ApiIdentity::current($request);
        $system = $request->attributes->get('system');

        if ($identity?->isServiceAccount() && !$identity->serviceAccount->can($permission, (int) ($system->workspace_id ?? 0))) {
            return response()->json(['message' => 'Service account lacks ' . $permission . ' in this workspace'], 403);
        }

        return $next($request);
    }
}
```

`bootstrap/app.php`, add to the alias array:

```php
            'sa.permission' => \App\Http\Middleware\RequireServiceAccountPermission::class,
```

`routes/api.php`, inside the `['auth.pat:service', 'active.user', 'atglance.system']` group:

```php
        Route::get('/systems/self', [V1SystemController::class, 'show'])->name('systems.self');
        Route::post('/systems/self/deregister', [V1SystemController::class, 'deregister'])->name('systems.deregister');
        Route::post('/systems/self/reactivate', [V1SystemController::class, 'reactivate'])->name('systems.reactivate');
        Route::post('/systems/self/rotate-key', [V1SystemController::class, 'rotateKey'])->middleware('sa.permission:systems.rotate_key')->name('systems.rotate-key');
        Route::post('/config-files', [V1ConfigFileController::class, 'store'])->middleware('sa.permission:configs.write')->name('config-files.store');
        Route::get('/config-files', [V1ConfigFileController::class, 'index'])->middleware('sa.permission:configs.read')->name('config-files.index');
        Route::get('/config-files/{id}', [V1ConfigFileController::class, 'show'])->middleware('sa.permission:configs.read')->where('id', '[0-9]{1,18}')->name('config-files.show');
```

- [ ] **Step 5: Upload as a service account**

In `FileController::uploadConfigFile`, replace `$user = $request->user();` and the organization check with:

```php
        $identity = ApiIdentity::current($request);
        $user = $request->user();
        $account = $identity?->isServiceAccount() ? $identity->serviceAccount : null;
        $orgId = $identity?->orgId() ?? (int) ($user->org_id ?? 200);
        $file = $request->file('file');

        $systemId = $request->input('system_register_id', $request->input('system_id'));

        // Only systems of the uploader's organisation (teammates included).
        if ($systemId) {
            $systemOrgId = SystemRegister::query()->whereKey((int) $systemId)->value('org_id');
            if ((int) ($systemOrgId ?? 200) !== $orgId) {
                return response()->json(['message' => 'System not found'], 404);
            }
        }
```

In the `service_id` lookup, `->where('user_id', $user->id)` stays (service accounts never send `service_id`: the v1 controller sets it to null).

Replace the storage path line:

```php
        // Define storage path: config_files/{user_id}/ or config_files/sa-{service_account_id}/
        $storagePath = $account ? "config_files/sa-{$account->id}" : "config_files/{$user->id}";
```

Replace the `Service::firstOrCreate` block with:

```php
        if (!$service && $systemId && $request->filled('service_name')) {
            $serviceDefaults = [
                'system_hash' => $request->input('system_hash', $request->input('validation_hash')),
                'org_id' => $request->input('org_id'),
                'share_with' => $request->input('share_with'),
                'status' => 'active',
            ];

            // A service account continues the system's existing service rows, whoever
            // created them, so the version history stays in one line.
            $service = $account
                ? (Service::query()->where('system_id', $systemId)->where('service_name', $request->input('service_name'))->orderBy('service_id')->first()
                    ?? Service::create($serviceDefaults + [
                        'user_id' => null,
                        'service_account_id' => $account->id,
                        'system_id' => $systemId,
                        'service_name' => $request->input('service_name'),
                    ]))
                : Service::firstOrCreate(
                    ['user_id' => $user->id, 'system_id' => $systemId, 'service_name' => $request->input('service_name')],
                    $serviceDefaults,
                );
        }
```

In the transaction closure, change `use ($user, ...` to `use ($user, $account, ...` and in both payloads replace `'user_id' => $user->id,` with:

```php
                    'user_id' => $user?->id,
                    'service_account_id' => $account?->id,
```

(`RawData::create` too.) Add `use App\Support\ApiIdentity;`.

- [ ] **Step 6: `created_by` in the v1 list**

In `V1\ConfigFileController::index`, change `->with('user:id,name')` to `->with(['user:id,name', 'serviceAccount:id,name'])` and the `created_by` entry to:

```php
                'created_by' => $file->user
                    ? ['id' => $file->user->id, 'name' => $file->user->name]
                    : ($file->serviceAccount ? ['service_account' => ['id' => $file->serviceAccount->id, 'name' => $file->serviceAccount->name]] : null),
```

- [ ] **Step 7: Rotate, deregister, reactivate rules**

In `V1\SystemController`:

```php
    public function deregister(Request $request)
    {
        return $this->refuseServiceAccount($request) ?? $this->authorizeChange($request) ?? $this->systems->deregisterSystem($this->system($request));
    }

    public function reactivate(Request $request)
    {
        return $this->refuseServiceAccount($request) ?? $this->authorizeChange($request) ?? $this->systems->reactivateSystem($this->system($request));
    }

    public function rotateKey(Request $request)
    {
        $identity = ApiIdentity::current($request);

        // A service account reaches here only with systems.rotate_key (route middleware);
        // it has no password or PIN. A person still needs one.
        if (!$identity?->isServiceAccount() && ($denied = $this->authorizeChange($request))) {
            return $denied;
        }

        $system = $this->system($request);
        // (validation block unchanged)
        ...
        $this->systems->rotateSystemKey($system, $data['new_key'], $request->user(), $identity?->serviceAccount);

        return response()->json(['system_id' => $system->id, 'rotated' => true]);
    }

    private function refuseServiceAccount(Request $request): ?JsonResponse
    {
        return ApiIdentity::current($request)?->isServiceAccount()
            ? response()->json(['message' => 'Deregister and reactivate need a user key with password or PIN'], 403)
            : null;
    }
```

Keep the existing `new_key` validation block in `rotateKey` between the two shown parts. Change the `authorizeChange` 403 message to `'Only the system owner, a workspace admin of its workspace or an admin of its organisation can change this system'`. Import `App\Support\ApiIdentity`.

In `SystemRegisterController`:

```php
    /**
     * Who may change a system from the host (/api/v1/systems/self/*): its owner, an admin
     * of the system's organisation, or a workspace admin of the system's workspace (for
     * example after the owner left).
     */
    public function canManageSystem(User $user, SystemRegister $system): bool
    {
        if ((int) $system->user_id === (int) $user->id) {
            return true;
        }

        if ((int) ($system->org_id ?? 200) !== (int) ($user->org_id ?? 200)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $workspaceId = (int) ($system->workspace_id ?? 0);

        return $workspaceId > 0 && (bool) $system->workspace?->hasUserAsAdmin($user->id);
    }

    /**
     * Replaces the system's key (validation_hash) and records who did it: a person or the
     * service account the system runs as.
     */
    public function rotateSystemKey(SystemRegister $system, string $newKey, ?User $actor, ?ServiceAccount $account = null): void
    {
        // (transaction unchanged)

        ActivityRecorder::record(
            $actor?->id,
            'system.key_rotated',
            'Rotated the key of system ' . $system->system_name . ($account ? ' (service account ' . $account->name . ')' : ''),
            ActivityRecorder::SUCCESS,
            null,
            $system->workspace_id ? (int) $system->workspace_id : null,
            (int) $system->id,
        );
    }
```

- [ ] **Step 8: Deregister and reactivate audit with actor and original owner**

In `app/Models/SystemRegister.php`, add:

```php
    /**
     * Who made the current change and who registered the system, for audit and notices.
     *
     * @return array{Actor: string, Original owner: string}
     */
    public function actorFacts(): array
    {
        $identity = \App\Support\ApiIdentity::current();
        $actor = $identity?->label() ?? (\Illuminate\Support\Facades\Auth::user()?->email ?? 'system');
        $owner = User::query()->find($this->user_id)?->email ?? ('user ' . $this->user_id);

        return ['Actor' => (string) $actor, 'Original owner' => (string) $owner];
    }
```

In the `updated` closure, use the actor as the activity user and add both names to the description:

```php
            $facts = $system->actorFacts();
            $actorId = \App\Support\ApiIdentity::current()?->user?->id ?? \Illuminate\Support\Facades\Auth::id() ?? (int) $system->user_id;
            $suffix = ' by ' . $facts['Actor'] . ', owner ' . $facts['Original owner'];

            if ($system->status === 'active') {
                ActivityRecorder::record((int) $actorId, 'system.reactivated', 'Reactivated system ' . $system->system_name . $suffix, ActivityRecorder::SUCCESS, null, $system->workspace_id ? (int) $system->workspace_id : null, (int) $system->id);
                $system->notifyWorkspace(NotificationEvents::SYSTEM_REGISTERED, 'System reactivated');
            } elseif ($system->getOriginal('status') === 'active') {
                ActivityRecorder::record((int) $actorId, 'system.deregistered', 'Deregistered system ' . $system->system_name . $suffix, ActivityRecorder::SUCCESS, null, $system->workspace_id ? (int) $system->workspace_id : null, (int) $system->id);
```

(The notification event changes in Task 9.)

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter="ServiceAccountSystemRoutesTest|CliApiV1Test|ConsoleListsTest"`
Expected: PASS. Then the whole suite: `php artisan test`. Expected: PASS (fix any existing test that asserted the old 403 message of `authorizeChange`).

- [ ] **Step 10: Commit**

```bash
git add app bootstrap/app.php routes/api.php tests/Feature/ServiceAccountSystemRoutesTest.php
git commit -m "feat(service-accounts): upload, read and rotate as a service account; human-only deregister"
```

---

### Task 8: Revoke a person's keys when they are removed

**Files:**
- Create: `app/Services/UserKeyRevoker.php`
- Modify: `app/Models/User.php` (`booted`)
- Test: `tests/Feature/UserKeyRevocationTest.php`

**Interfaces:**
- Consumes: `PatToken`, `ServiceAccount`.
- Produces:
  - `UserKeyRevoker::revokeAll(User $user, string $reason): int` (count revoked)
  - `UserKeyRevoker::releaseOwnedAccounts(User $user): Collection<ServiceAccount>` (sets `owner_user_id` null; returns the accounts)
  - Reasons: `user_deactivated`, `user_deleted`, `user_org_changed`.
  - Hook point for Task 9: `UserKeyRevoker` calls `ServiceAccountNotices::userKeysRevoked(...)` and `::ownerMissing(...)` (added in Task 9).

- [ ] **Step 1: Write the failing test**

`tests/Feature/UserKeyRevocationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PatToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class UserKeyRevocationTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_deactivation_revokes_all_keys_and_reactivation_does_not_restore(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $this->actingAs($admin);
        $user = $this->makeUser('leaver@example.test');
        [, $a] = $this->userKey($user);
        [, $b] = $this->userKey($user);

        $user->status = 'inactive';
        $user->save();

        foreach ([$a, $b] as $key) {
            $key->refresh();
            $this->assertSame('revoked', $key->status);
            $this->assertSame('user_deactivated', $key->revoke_reason);
            $this->assertSame($admin->id, (int) $key->revoked_by_user_id);
        }
        $this->assertTrue(ActivityLog::where('event', 'user.keys_revoked')->exists());

        $user->status = 'active';
        $user->save();
        $this->assertSame('revoked', $a->fresh()->status);
    }

    public function test_org_change_and_delete_revoke(): void
    {
        $mover = $this->makeUser('mover@example.test');
        [, $moverKey] = $this->userKey($mover);
        $mover->org_id = 300;
        $mover->save();
        $this->assertSame('user_org_changed', $moverKey->fresh()->revoke_reason);

        $gone = $this->makeUser('gone@example.test');
        [, $goneKey] = $this->userKey($gone);
        $gone->delete();
        $row = PatToken::find($goneKey->id);
        $this->assertNotNull($row);
        $this->assertSame('revoked', $row->status);
        $this->assertSame('user_deleted', $row->revoke_reason);
    }

    public function test_other_changes_do_not_revoke(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);
        $user->name = 'Renamed';
        $user->save();
        $this->workspace->addUser($user->id);
        $this->workspace->removeUser($user->id);

        $this->assertSame('active', $key->fresh()->status);
    }

    public function test_owned_accounts_lose_owner_and_keep_working(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser('owner@example.test', 101);
        $account = $this->makeAccount(attributes: ['owner_user_id' => $owner->id]);
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($owner, ['service_account_id' => $account->id]);

        $owner->status = 'inactive';
        $owner->save();

        $this->assertNull($account->fresh()->owner_user_id);
        $this->assertSame('active', $account->fresh()->status);

        $this->post('/api/v1/config-files', [
            'service_name' => 'sshd',
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "x\n"),
        ], ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'] + $this->systemHeaders($system))->assertCreated();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=UserKeyRevocationTest`
Expected: FAIL (keys stay `active`).

- [ ] **Step 3: Write the revoker**

`app/Services/UserKeyRevoker.php`:

```php
<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Revokes every active atgla- key of a person who is deactivated, deleted or moved to
 * another organization. Rows are kept for audit; a revoked key never comes back. The
 * service accounts they owned keep running and lose their owner.
 */
class UserKeyRevoker
{
    public const REASON_DEACTIVATED = 'user_deactivated';
    public const REASON_DELETED = 'user_deleted';
    public const REASON_ORG_CHANGED = 'user_org_changed';

    public function revokeAll(User $user, string $reason): int
    {
        $count = PatToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by_user_id' => Auth::id(),
                'revoke_reason' => $reason,
            ]);

        if ($count > 0) {
            ActivityRecorder::record(Auth::id() ?? $user->id, 'user.keys_revoked', "Revoked {$count} API key(s) of {$user->email}: {$reason}");
        }

        return $count;
    }

    /**
     * @return Collection<int, ServiceAccount>
     */
    public function releaseOwnedAccounts(User $user): Collection
    {
        $accounts = ServiceAccount::query()->where('owner_user_id', $user->id)->get();

        foreach ($accounts as $account) {
            $account->update(['owner_user_id' => null]);
            ActivityRecorder::record(Auth::id() ?? $user->id, 'service_account.owner_missing', 'Service account ' . $account->name . ' has no owner (' . $user->email . ' left)');
        }

        return $accounts;
    }
}
```

- [ ] **Step 4: Hook into `User`**

In `User::booted()` add after the `saving` listener:

```php
        // Removal from the organization ends every API key at once (spec 2026-10-13, section 7).
        static::updated(function (User $user) {
            $reason = null;
            if ($user->wasChanged('status') && strtolower((string) $user->status) !== 'active') {
                $reason = UserKeyRevoker::REASON_DEACTIVATED;
            } elseif ($user->wasChanged('org_id')) {
                $reason = UserKeyRevoker::REASON_ORG_CHANGED;
            }

            if ($reason !== null) {
                $revoker = app(UserKeyRevoker::class);
                $revoker->revokeAll($user, $reason);
                $revoker->releaseOwnedAccounts($user);
            }
        });

        static::deleting(function (User $user) {
            $revoker = app(UserKeyRevoker::class);
            $revoker->revokeAll($user, UserKeyRevoker::REASON_DELETED);
            $revoker->releaseOwnedAccounts($user);
        });
```

Add `use App\Services\UserKeyRevoker;`. The `updated` event fires only after the row was saved, so a failed save revokes nothing (Review Focus 4). Callers that wrap the change in `DB::transaction` get the revoke in the same transaction.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter="UserKeyRevocationTest|UserApiAuthTest|SecurityPrivacyTest"`
Expected: PASS. Then `php artisan test`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/UserKeyRevoker.php app/Models/User.php tests/Feature/UserKeyRevocationTest.php
git commit -m "feat(service-accounts): revoke a person's keys on deactivate, delete or org change"
```

---

### Task 9: Notifications and the daily credential sweep

**Files:**
- Modify: `app/Notifications/NotificationEvents.php`
- Create: `app/Support/ServiceAccountNotices.php`
- Modify: `app/Support/AccountAlerts.php`
- Create: `app/Services/CredentialSweep.php`
- Modify: `routes/console.php`
- Modify: `app/Services/ServiceAccounts.php`, `app/Services/UserKeyRevoker.php`, `app/Models/SystemRegister.php` (send notices)
- Create: `database/migrations/2026_10_13_010000_subscribe_reactivated_event.php`
- Test: `tests/Feature/ServiceAccountNotificationsTest.php`

**Interfaces:**
- Consumes: `Notifier::notify(string $event, ?int $workspaceId, string $title, array $facts = [], array $data = [])`, `AccountAlerts::send` (private; add public methods).
- Produces:
  - New `NotificationEvents` constants (see Step 3).
  - `ServiceAccountNotices::accountChanged(ServiceAccount $account, string $what)` — `$what` in `created`, `disabled`
  - `ServiceAccountNotices::keyEvent(ServiceAccount $account, PatToken $key, string $what)` — `$what` in `expiring`, `expired`, `revoked`
  - `ServiceAccountNotices::grantChanged(ServiceAccount $account, Workspace $workspace, bool $added)`
  - `ServiceAccountNotices::systemAssigned(SystemRegister $system, ServiceAccount $account)`
  - `ServiceAccountNotices::ownerMissing(ServiceAccount $account, User $formerOwner)`
  - `ServiceAccountNotices::userKeysRevoked(User $user, int $count, string $reason)`
  - `AccountAlerts::apiKeyExpiring(User $user, PatToken $key)`, `serviceAccountKeyExpiring(User $owner, ServiceAccount $account, PatToken $key)`, `serviceAccountOwnerAssigned(User $owner, ServiceAccount $account)`, `keysRevoked(User $user, int $count)`
  - `CredentialSweep::run(): array{expiring: int, expired: int}`
  - Artisan `atglance:credentials-sweep`, scheduled daily at 06:10.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountNotificationsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\NotificationGroup;
use App\Models\ServiceAccount;
use App\Notifications\NotificationEvents;
use App\Services\CredentialSweep;
use App\Services\Notifier;
use App\Services\ServiceAccounts;
use App\Jobs\SendAccountAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountNotificationsTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    /** @var list<array{event: string, workspace: ?int, title: string, facts: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('notify')->andReturnUsing(function (string $event, ?int $workspaceId, string $title, array $facts = []) {
            $this->sent[] = ['event' => $event, 'workspace' => $workspaceId, 'title' => $title, 'facts' => $facts];
        });
        $this->app->instance(Notifier::class, $notifier);
    }

    private function events(): array
    {
        return array_column($this->sent, 'event');
    }

    public function test_events_have_scopes(): void
    {
        $this->assertSame('workspace', NotificationEvents::scope(NotificationEvents::SERVICE_ACCOUNT_KEY_EXPIRING));
        $this->assertSame('organization', NotificationEvents::scope(NotificationEvents::ORG_SERVICE_ACCOUNT_KEY_EXPIRING));
        $this->assertSame('organization', NotificationEvents::scope(NotificationEvents::ORG_USER_KEYS_REVOKED));
        $this->assertSame('workspace', NotificationEvents::scope(NotificationEvents::SYSTEM_REACTIVATED));
    }

    public function test_create_routes_by_scope(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $accounts = app(ServiceAccounts::class);

        $accounts->create($admin, ['name' => 'ws', 'scope' => 'workspace', 'workspace_id' => $this->workspace->id]);
        $accounts->create($admin, ['name' => 'org', 'scope' => 'organization']);

        $this->assertContains(['event' => NotificationEvents::SERVICE_ACCOUNT_CREATED, 'workspace' => $this->workspace->id], array_map(fn ($s) => ['event' => $s['event'], 'workspace' => $s['workspace']], $this->sent));
        $this->assertContains(NotificationEvents::ORG_SERVICE_ACCOUNT_CREATED, $this->events());
    }

    public function test_org_account_key_revoked_goes_to_org_and_each_granted_workspace(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($account, $this->workspace);
        $this->grant($account, $this->otherWorkspace);
        [$plain, $key] = $this->accountKey($account);

        app(ServiceAccounts::class)->revokeKey($key, $admin, 'leaked');

        $this->assertContains(NotificationEvents::ORG_SERVICE_ACCOUNT_KEY_REVOKED, $this->events());
        $workspaces = array_column(array_filter($this->sent, fn ($s) => $s['event'] === NotificationEvents::SERVICE_ACCOUNT_KEY_REVOKED), 'workspace');
        $this->assertEqualsCanonicalizing([$this->workspace->id, $this->otherWorkspace->id], $workspaces);

        foreach ($this->sent as $notice) {
            $this->assertStringNotContainsString($plain, json_encode($notice));
        }
    }

    public function test_sweep_sends_each_notice_once(): void
    {
        Queue::fake();
        config(['mail.default' => 'array']);
        $owner = $this->makeUser('owner@example.test', 101);
        $account = $this->makeAccount(attributes: ['owner_user_id' => $owner->id]);
        [, $soon] = $this->accountKey($account, ['expires_at' => now()->addDays(10)]);
        [, $late] = $this->accountKey($account, ['expires_at' => now()->addDays(2)]);
        $user = $this->makeUser('u@example.test');
        [, $userKey] = $this->userKey($user);
        $userKey->update(['expires_at' => now()->addDays(13)]);
        $expiredUser = $this->makeUser('e@example.test');
        [, $expiredKey] = $this->userKey($expiredUser);
        $expiredKey->update(['expires_at' => now()->subHour()]);

        $first = app(CredentialSweep::class)->run();
        $this->assertSame(['expiring' => 3, 'expired' => 1], $first);
        $this->assertSame(14, (int) $soon->fresh()->expiry_notified_stage);
        $this->assertSame(3, (int) $late->fresh()->expiry_notified_stage);
        $this->assertNotNull($expiredKey->fresh()->expired_notified_at);

        $this->assertSame(['expiring' => 0, 'expired' => 0], app(CredentialSweep::class)->run());

        $this->travel(5)->days();
        $this->assertSame(1, app(CredentialSweep::class)->run()['expiring']); // $soon enters the 3-day window
    }

    public function test_user_keys_revoked_notice(): void
    {
        $user = $this->makeUser('leaver@example.test');
        $this->userKey($user);
        $user->status = 'inactive';
        $user->save();

        $notice = collect($this->sent)->firstWhere('event', NotificationEvents::ORG_USER_KEYS_REVOKED);
        $this->assertNotNull($notice);
        $this->assertSame(1, (int) $notice['facts']['Keys revoked']);
    }

    public function test_reactivation_uses_its_own_event_with_actor_facts(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $system = $this->makeSystem($owner, ['status' => 'inactive']);
        $this->actingAs($this->makeUser('admin@example.test', 101));

        $system->status = 'active';
        $system->save();

        $notice = collect($this->sent)->firstWhere('event', NotificationEvents::SYSTEM_REACTIVATED);
        $this->assertSame('admin@example.test', $notice['facts']['Actor']);
        $this->assertSame('owner@example.test', $notice['facts']['Original owner']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountNotificationsTest`
Expected: FAIL (`Undefined constant ...SERVICE_ACCOUNT_KEY_EXPIRING`).

- [ ] **Step 3: Add the events**

In `NotificationEvents`, add constants:

```php
    public const SYSTEM_REACTIVATED = 'system.reactivated';
    public const SYSTEM_ASSIGNED_TO_SERVICE_ACCOUNT = 'system.assigned_to_service_account';
    public const SERVICE_ACCOUNT_CREATED = 'service_account.created';
    public const SERVICE_ACCOUNT_DISABLED = 'service_account.disabled';
    public const SERVICE_ACCOUNT_GRANT_ADDED = 'service_account.grant_added';
    public const SERVICE_ACCOUNT_GRANT_REMOVED = 'service_account.grant_removed';
    public const SERVICE_ACCOUNT_KEY_EXPIRING = 'service_account.key_expiring';
    public const SERVICE_ACCOUNT_KEY_EXPIRED = 'service_account.key_expired';
    public const SERVICE_ACCOUNT_KEY_REVOKED = 'service_account.key_revoked';
    public const ORG_SERVICE_ACCOUNT_CREATED = 'organization.service_account_created';
    public const ORG_SERVICE_ACCOUNT_DISABLED = 'organization.service_account_disabled';
    public const ORG_SERVICE_ACCOUNT_KEY_EXPIRING = 'organization.service_account_key_expiring';
    public const ORG_SERVICE_ACCOUNT_KEY_EXPIRED = 'organization.service_account_key_expired';
    public const ORG_SERVICE_ACCOUNT_KEY_REVOKED = 'organization.service_account_key_revoked';
    public const ORG_SERVICE_ACCOUNT_OWNER_MISSING = 'organization.service_account_owner_missing';
    public const ORG_USER_KEYS_REVOKED = 'organization.user_keys_revoked';
```

and entries in `EVENTS` (workspace ones after `SYSTEM_DEREGISTERED`, organization ones after `BACKUP_FAILED`):

```php
        self::SYSTEM_REACTIVATED => ['label' => 'System reactivated', 'scope' => self::SCOPE_WORKSPACE],
        self::SYSTEM_ASSIGNED_TO_SERVICE_ACCOUNT => ['label' => 'System moved to a service account', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_CREATED => ['label' => 'Service account created', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_DISABLED => ['label' => 'Service account disabled', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_GRANT_ADDED => ['label' => 'Organization service account given access', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_GRANT_REMOVED => ['label' => 'Organization service account access removed', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_KEY_EXPIRING => ['label' => 'Service account key expires soon', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_KEY_EXPIRED => ['label' => 'Service account key expired', 'scope' => self::SCOPE_WORKSPACE],
        self::SERVICE_ACCOUNT_KEY_REVOKED => ['label' => 'Service account key revoked', 'scope' => self::SCOPE_WORKSPACE],
        // ...
        self::ORG_SERVICE_ACCOUNT_CREATED => ['label' => 'Organization service account created', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_DISABLED => ['label' => 'Organization service account disabled', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_KEY_EXPIRING => ['label' => 'Organization service account key expires soon', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_KEY_EXPIRED => ['label' => 'Organization service account key expired', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_KEY_REVOKED => ['label' => 'Organization service account key revoked', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_SERVICE_ACCOUNT_OWNER_MISSING => ['label' => 'Service account has no owner', 'scope' => self::SCOPE_ORGANIZATION],
        self::ORG_USER_KEYS_REVOKED => ['label' => "A user's API keys were revoked", 'scope' => self::SCOPE_ORGANIZATION],
```

Migration `database/migrations/2026_10_13_010000_subscribe_reactivated_event.php` so groups that got reactivations under `system.registered` keep getting them:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reactivations used to be sent as system.registered. Groups subscribed to that event
     * also get the new system.reactivated, so nobody stops receiving them.
     */
    public function up(): void
    {
        if (!Schema::hasTable('notification_groups')) {
            return;
        }

        foreach (DB::table('notification_groups')->get(['id', 'events']) as $group) {
            $events = json_decode((string) $group->events, true) ?: [];
            if (in_array('system.registered', $events, true) && !in_array('system.reactivated', $events, true)) {
                $events[] = 'system.reactivated';
                DB::table('notification_groups')->where('id', $group->id)->update(['events' => json_encode($events)]);
            }
        }
    }

    public function down(): void
    {
    }
};
```

- [ ] **Step 4: Write `ServiceAccountNotices`**

`app/Support/ServiceAccountNotices.php`:

```php
<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationEvents as E;
use App\Services\Notifier;

/**
 * Sends service account events to the right level: organization accounts to the
 * organization groups (and, for key events, every workspace they can act in);
 * workspace accounts to their workspace. Never includes a key, only its last 4.
 */
final class ServiceAccountNotices
{
    public static function accountChanged(ServiceAccount $account, string $what): void
    {
        $title = 'Service account ' . $what . ': ' . $account->name;
        $facts = self::facts($account);

        if ($account->isOrganizationScope()) {
            self::send($what === 'created' ? E::ORG_SERVICE_ACCOUNT_CREATED : E::ORG_SERVICE_ACCOUNT_DISABLED, null, $title, $facts);

            return;
        }

        self::send($what === 'created' ? E::SERVICE_ACCOUNT_CREATED : E::SERVICE_ACCOUNT_DISABLED, (int) $account->workspace_id, $title, $facts);
    }

    public static function keyEvent(ServiceAccount $account, PatToken $key, string $what): void
    {
        $title = match ($what) {
            'expiring' => 'Service account key expires soon: ' . $account->name,
            'expired' => 'Service account key expired: ' . $account->name,
            default => 'Service account key revoked: ' . $account->name,
        };
        $facts = self::facts($account) + [
            'Key' => $key->name . ' (…' . $key->last_four . ')',
            'Expires' => (string) $key->expires_at?->toDateString(),
        ];
        if ($what === 'revoked' && $key->revoke_reason) {
            $facts['Reason'] = (string) $key->revoke_reason;
        }

        [$orgEvent, $workspaceEvent] = match ($what) {
            'expiring' => [E::ORG_SERVICE_ACCOUNT_KEY_EXPIRING, E::SERVICE_ACCOUNT_KEY_EXPIRING],
            'expired' => [E::ORG_SERVICE_ACCOUNT_KEY_EXPIRED, E::SERVICE_ACCOUNT_KEY_EXPIRED],
            default => [E::ORG_SERVICE_ACCOUNT_KEY_REVOKED, E::SERVICE_ACCOUNT_KEY_REVOKED],
        };

        if ($account->isOrganizationScope()) {
            self::send($orgEvent, null, $title, $facts);
        }

        foreach ($account->grants()->where('status', 'active')->pluck('workspace_id') as $workspaceId) {
            self::send($workspaceEvent, (int) $workspaceId, $title, $facts);
        }

        if ($what === 'expiring' && $account->owner) {
            AccountAlerts::serviceAccountKeyExpiring($account->owner, $account, $key);
        }
    }

    public static function grantChanged(ServiceAccount $account, Workspace $workspace, bool $added): void
    {
        self::send(
            $added ? E::SERVICE_ACCOUNT_GRANT_ADDED : E::SERVICE_ACCOUNT_GRANT_REMOVED,
            (int) $workspace->id,
            ($added ? 'Service account given access: ' : 'Service account access removed: ') . $account->name,
            self::facts($account),
        );
    }

    public static function systemAssigned(SystemRegister $system, ServiceAccount $account): void
    {
        self::send(E::SYSTEM_ASSIGNED_TO_SERVICE_ACCOUNT, (int) $system->workspace_id, 'System moved to service account: ' . $system->system_name, [
            'System' => (string) $system->system_name,
            'Service account' => $account->name,
        ] + $system->actorFacts());
    }

    public static function ownerMissing(ServiceAccount $account, User $formerOwner): void
    {
        self::send(E::ORG_SERVICE_ACCOUNT_OWNER_MISSING, null, 'Service account has no owner: ' . $account->name, self::facts($account) + [
            'Former owner' => (string) $formerOwner->email,
        ]);
    }

    public static function userKeysRevoked(User $user, int $count, string $reason): void
    {
        self::send(E::ORG_USER_KEYS_REVOKED, null, 'API keys revoked for ' . $user->email, [
            'User' => (string) $user->email,
            'Keys revoked' => (string) $count,
            'Reason' => $reason,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function facts(ServiceAccount $account): array
    {
        return array_filter([
            'Service account' => $account->name,
            'Scope' => $account->scope,
            'Workspace' => (string) $account->workspace?->name,
            'Owner' => (string) ($account->owner?->email ?? 'none'),
            'Team' => (string) $account->team,
        ], fn (string $value) => $value !== '');
    }

    private static function send(string $event, ?int $workspaceId, string $title, array $facts): void
    {
        app(Notifier::class)->notify($event, $workspaceId, $title, $facts);
    }
}
```

- [ ] **Step 5: Personal alerts**

In `AccountAlerts`, add:

```php
    public static function apiKeyExpiring(User $user, PatToken $key): void
    {
        if (UserPreferences::wantsAlert($user, 'api_keys')) {
            self::send($user, 'API key expires soon', 'Your API key "' . $key->name . '" expires on ' . $key->expires_at?->toDateString() . '. Create a new key and replace it before then.');
        }
    }

    public static function serviceAccountKeyExpiring(User $owner, ServiceAccount $account, PatToken $key): void
    {
        if (UserPreferences::wantsAlert($owner, 'api_keys')) {
            self::send($owner, 'Service account key expires soon: ' . $account->name, 'The key "' . $key->name . '" (…' . $key->last_four . ') of service account "' . $account->name . '", which you own, expires on ' . $key->expires_at?->toDateString() . '. Create a second key, move the servers to it, then revoke the old one.');
        }
    }

    public static function serviceAccountOwnerAssigned(User $owner, ServiceAccount $account): void
    {
        if (UserPreferences::wantsAlert($owner, 'api_keys')) {
            self::send($owner, 'You now own service account ' . $account->name, 'You are now the accountable owner of service account "' . $account->name . '". You will get its key expiry notices.');
        }
    }

    /**
     * Always sent: a security notice, like password changes.
     */
    public static function keysRevoked(User $user, int $count): void
    {
        self::send($user, 'Your API keys were revoked', "{$count} API key(s) on your account were revoked because your account was deactivated or moved. They will not work again; create new keys if your access is restored.");
    }
```

Imports: `App\Models\PatToken`, `App\Models\ServiceAccount`.

- [ ] **Step 6: Send the notices from the operations**

In `ServiceAccounts` (Task 2):
- end of `create`: `ServiceAccountNotices::accountChanged($account, 'created');`
- `setStatus` disable branch: `ServiceAccountNotices::accountChanged($account, 'disabled');`
- `transferOwner`: `AccountAlerts::serviceAccountOwnerAssigned($newOwner, $account);`
- `setGrant` when `$event === 'service_account.grant_added'` and the account is organization scope: `ServiceAccountNotices::grantChanged($account, $workspace, true);`
- `removeGrant` when `$updated > 0`: `ServiceAccountNotices::grantChanged($account, $workspace, false);`
- `revokeKey` inside the `if` for service account keys: `ServiceAccountNotices::keyEvent($account, $key, 'revoked');`
- `assignSystem` after the audit: `ServiceAccountNotices::systemAssigned($system, $account);`

In `UserKeyRevoker`:
- `revokeAll` inside `if ($count > 0)`: `ServiceAccountNotices::userKeysRevoked($user, $count, $reason);` and, when `$reason !== self::REASON_DELETED`, `AccountAlerts::keysRevoked($user, $count);`
- `releaseOwnedAccounts` per account: `ServiceAccountNotices::ownerMissing($account, $user);`

In `SystemRegister::updated`, change the reactivation notice to the new event and pass the actor facts. Change `notifyWorkspace` to accept extra facts:

```php
    private function notifyWorkspace(string $event, string $action, array $extraFacts = []): void
```

merging `+ $extraFacts` into the `array_filter([...])` facts, and call:

```php
$system->notifyWorkspace(NotificationEvents::SYSTEM_REACTIVATED, 'System reactivated', $facts);
// ...
$system->notifyWorkspace(NotificationEvents::SYSTEM_DEREGISTERED, 'System deregistered', $facts);
```

- [ ] **Step 7: Write the sweep**

`app/Services/CredentialSweep.php`:

```php
<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\AccountAlerts;
use App\Support\ActivityRecorder;
use App\Support\ServiceAccountNotices;
use App\Support\ServiceAccountSettings;

/**
 * Daily: expiry notices for person and service account keys at each notice day
 * (default 14 and 3 days before), and one "expired" notice per key. The stored
 * stage makes reruns send nothing new.
 */
class CredentialSweep
{
    /**
     * @return array{expiring: int, expired: int}
     */
    public function run(): array
    {
        $expiring = 0;
        $windows = ServiceAccountSettings::noticeDays();

        if ($windows !== []) {
            $keys = PatToken::query()
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays($windows[0]))
                ->get();

            foreach ($keys as $key) {
                $daysLeft = (int) ceil(now()->diffInHours($key->expires_at) / 24);
                $stage = $this->smallestWindowCovering($windows, $daysLeft);
                if ($stage === null || ($key->expiry_notified_stage !== null && (int) $key->expiry_notified_stage <= $stage)) {
                    continue;
                }

                $this->notifyExpiring($key);
                $key->forceFill(['expiry_notified_stage' => $stage])->save();
                $expiring++;
            }
        }

        $expired = 0;
        $done = PatToken::query()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNull('expired_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($done as $key) {
            if ($key->isServiceAccountKey() && ($account = ServiceAccount::find($key->tokenable_id))) {
                ServiceAccountNotices::keyEvent($account, $key, 'expired');
                ActivityRecorder::record(null, 'service_account.key_expired', 'Key "' . $key->name . '" (…' . $key->last_four . ') of ' . $account->name . ' expired');
            }
            $key->forceFill(['expired_notified_at' => now()])->save();
            $expired++;
        }

        return ['expiring' => $expiring, 'expired' => $expired];
    }

    /**
     * @param list<int> $windows largest first
     */
    private function smallestWindowCovering(array $windows, int $daysLeft): ?int
    {
        $covering = array_filter($windows, fn (int $window) => $daysLeft <= $window);

        return $covering === [] ? null : min($covering);
    }

    private function notifyExpiring(PatToken $key): void
    {
        if ($key->isServiceAccountKey()) {
            if ($account = ServiceAccount::find($key->tokenable_id)) {
                ServiceAccountNotices::keyEvent($account, $key, 'expiring');
            }

            return;
        }

        if ($user = User::find($key->tokenable_id)) {
            AccountAlerts::apiKeyExpiring($user, $key);
        }
    }
}
```

In `routes/console.php`, add after the `ai:validate-workspace` command:

```php
Artisan::command('atglance:credentials-sweep', function (\App\Services\CredentialSweep $sweep) {
    $result = $sweep->run();
    $this->info("Expiry notices: {$result['expiring']}, expired notices: {$result['expired']}.");

    return 0;
})->purpose('Send API key and service account key expiry notices');

if (InstallationState::isInstalled()) {
    Schedule::command('atglance:credentials-sweep')->dailyAt('06:10')->withoutOverlapping();
}
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --filter="ServiceAccountNotificationsTest|NotificationsTest|NotificationChannelSetupTest"`
Expected: PASS. Then `php artisan test`.

- [ ] **Step 9: Commit**

```bash
git add app database/migrations/2026_10_13_010000_subscribe_reactivated_event.php routes/console.php tests/Feature/ServiceAccountNotificationsTest.php
git commit -m "feat(service-accounts): organization, workspace and personal notices; daily expiry sweep"
```

---

### Task 10: Console pages

**Files:**
- Create: `app/Http/Controllers/ServiceAccountController.php`
- Create: `resources/views/admin/service-accounts/index.blade.php`
- Create: `resources/views/admin/service-accounts/show.blade.php`
- Create: `resources/views/admin/service-accounts/flash.blade.php` (partial: success and error messages)
- Create: `resources/views/admin/workspace/tab-service-accounts.blade.php`
- Modify: `resources/views/admin/workspace-detail.blade.php` (tab)
- Modify: `app/Http/Controllers/AdminDashboardController.php` (`viewWorkspace` passes `serviceAccountsTab`)
- Modify: `resources/views/systems-registered.blade.php` (account column, assign form)
- Modify: `routes/web.php`
- Modify: the sidebar partial that lists admin links (find it with `grep -rn "admin.notifications" resources/views/layouts resources/views/app.blade.php`)
- Test: `tests/Feature/ServiceAccountConsoleTest.php`

**Interfaces:**
- Consumes: `ServiceAccounts`, `ServiceAccountAccess`, `ServiceAccountSettings`.
- Produces routes (all inside the existing `['auth', 'active.user', 'profile.completed']` group):
  - `GET  /admin/service-accounts` → `index` (name `service-accounts.index`) — admins
  - `PUT  /admin/service-accounts/settings` → `updateSettings` (name `service-accounts.settings`) — admins
  - `POST /service-accounts` → `store` (name `service-accounts.store`)
  - `GET  /service-accounts/{id}` → `show` (name `service-accounts.show`)
  - `PUT  /service-accounts/{id}` → `update` (name `service-accounts.update`)
  - `POST /service-accounts/{id}/status` → `status` (name `service-accounts.status`)
  - `POST /service-accounts/{id}/owner` → `owner` (name `service-accounts.owner`)
  - `POST /service-accounts/{id}/grants` → `grant` (name `service-accounts.grants.store`)
  - `DELETE /service-accounts/{id}/grants/{workspaceId}` → `revokeGrant` (name `service-accounts.grants.destroy`)
  - `POST /service-accounts/{id}/keys` → `issueKey` (name `service-accounts.keys.store`)
  - `POST /service-accounts/{id}/keys/{keyId}/revoke` → `revokeKey` (name `service-accounts.keys.revoke`)
  - `POST /systems-registered/{systemId}/service-account` → `assignSystem` (name `systems-registered.service-account`)

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountConsoleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountConsoleTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);
    }

    public function test_admin_creates_org_account_and_key_is_shown_once(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $this->actingAs($admin)->post(route('service-accounts.store'), ['name' => 'org-agent', 'scope' => 'organization'])
            ->assertRedirect();
        $account = ServiceAccount::where('name', 'org-agent')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('service-accounts.keys.store', $account->id), ['name' => 'fleet', 'days' => 30]);
        $response->assertRedirect(route('service-accounts.show', $account->id));
        $plain = session('new_service_account_key');
        $this->assertStringStartsWith('atgsa-', $plain);

        $this->actingAs($admin)->get(route('service-accounts.show', $account->id))->assertOk()->assertSee($plain);
        $this->actingAs($admin)->get(route('service-accounts.show', $account->id))->assertOk()->assertDontSee($plain);
        $this->assertNull(PatToken::where('tokenable_id', $account->id)->first()->token_encrypted);
    }

    public function test_member_cannot_create_or_view(): void
    {
        $member = $this->makeUser('member@example.test');
        $this->workspace->addUser($member->id);
        $account = $this->makeAccount();

        $this->actingAs($member)->post(route('service-accounts.store'), ['name' => 'x', 'scope' => 'workspace', 'workspace_id' => $this->workspace->id])->assertForbidden();
        $this->actingAs($member)->get(route('service-accounts.show', $account->id))->assertNotFound();
        $this->actingAs($member)->get(route('service-accounts.index'))->assertRedirect();
    }

    public function test_workspace_admin_with_permission_creates_workspace_account_only(): void
    {
        $wsAdmin = $this->makeUser('ws@example.test');
        $this->workspace->addUser($wsAdmin->id, true);
        $this->workspace->setPermissions($wsAdmin->id, ['service_accounts' => true]);

        $this->actingAs($wsAdmin)->post(route('service-accounts.store'), ['name' => 'ws-agent', 'scope' => 'workspace', 'workspace_id' => $this->workspace->id])->assertRedirect();
        $this->actingAs($wsAdmin)->post(route('service-accounts.store'), ['name' => 'org-agent', 'scope' => 'organization'])->assertForbidden();
        $this->actingAs($wsAdmin)->post(route('service-accounts.store'), ['name' => 'other', 'scope' => 'workspace', 'workspace_id' => $this->otherWorkspace->id])->assertForbidden();

        $this->actingAs($wsAdmin)->get(route('admin.workspaces.show', ['workspaceId' => $this->workspace->id, 'tab' => 'service-accounts']))
            ->assertOk()->assertSee('ws-agent');
    }

    public function test_other_organization_ids_are_404(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $theirs = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);

        $this->actingAs($admin)->get(route('service-accounts.show', $theirs->id))->assertNotFound();
        $this->actingAs($admin)->post(route('service-accounts.keys.store', $theirs->id), ['name' => 'x'])->assertNotFound();
    }

    public function test_quota_error_is_shown_not_500(): void
    {
        \App\Support\ServiceAccountSettings::save(['sa_org_quota' => 0]);
        $admin = $this->makeUser('admin@example.test', 101);

        $this->actingAs($admin)->from(route('service-accounts.index'))
            ->post(route('service-accounts.store'), ['name' => 'x', 'scope' => 'organization'])
            ->assertRedirect(route('service-accounts.index'))
            ->assertSessionHasErrors('service_account');
    }

    public function test_assign_system_to_service_account(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $system = $this->makeSystem($admin);
        $account = $this->makeAccount();

        $this->actingAs($admin)->post(route('systems-registered.service-account', $system->id), ['service_account_id' => $account->id])->assertRedirect();
        $this->assertSame($account->id, (int) $system->fresh()->service_account_id);
    }

    public function test_settings_update_by_admin(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $this->actingAs($admin)->put(route('service-accounts.settings'), [
            'sa_default_key_days' => 30, 'sa_max_key_days' => 180, 'sa_org_quota' => 3, 'sa_workspace_quota' => 2,
            'sa_expiry_notice_days' => '7,1', 'sa_require_for_registration' => '1',
        ])->assertRedirect();

        $this->assertSame(180, \App\Support\ServiceAccountSettings::maxKeyDays());
        $this->assertTrue(\App\Support\ServiceAccountSettings::requireForRegistration());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ServiceAccountConsoleTest`
Expected: FAIL, `Route [service-accounts.store] not defined`.

- [ ] **Step 3: Routes**

In `routes/web.php`, inside `Route::middleware(['auth', 'active.user', 'profile.completed'])->group(...)`, before the `admin.role` group:

```php
        // Service accounts: ServiceAccountController checks ServiceAccountAccess on every action.
        Route::post('/service-accounts', [ServiceAccountController::class, 'store'])->name('service-accounts.store');
        Route::get('/service-accounts/{id}', [ServiceAccountController::class, 'show'])->whereNumber('id')->name('service-accounts.show');
        Route::put('/service-accounts/{id}', [ServiceAccountController::class, 'update'])->whereNumber('id')->name('service-accounts.update');
        Route::post('/service-accounts/{id}/status', [ServiceAccountController::class, 'status'])->whereNumber('id')->name('service-accounts.status');
        Route::post('/service-accounts/{id}/owner', [ServiceAccountController::class, 'owner'])->whereNumber('id')->name('service-accounts.owner');
        Route::post('/service-accounts/{id}/grants', [ServiceAccountController::class, 'grant'])->whereNumber('id')->name('service-accounts.grants.store');
        Route::delete('/service-accounts/{id}/grants/{workspaceId}', [ServiceAccountController::class, 'revokeGrant'])->whereNumber(['id', 'workspaceId'])->name('service-accounts.grants.destroy');
        Route::post('/service-accounts/{id}/keys', [ServiceAccountController::class, 'issueKey'])->whereNumber('id')->middleware('throttle:20,1')->name('service-accounts.keys.store');
        Route::post('/service-accounts/{id}/keys/{keyId}/revoke', [ServiceAccountController::class, 'revokeKey'])->whereNumber(['id', 'keyId'])->name('service-accounts.keys.revoke');
        Route::post('/systems-registered/{systemId}/service-account', [ServiceAccountController::class, 'assignSystem'])->whereNumber('systemId')->name('systems-registered.service-account');
```

Inside the `admin.role` group (prefix `admin`):

```php
            Route::get('/service-accounts', [ServiceAccountController::class, 'index'])->name('service-accounts.index');
            Route::put('/service-accounts/settings', [ServiceAccountController::class, 'updateSettings'])->name('service-accounts.settings');
```

Add `use App\Http\Controllers\ServiceAccountController;`.

- [ ] **Step 4: Controller**

`app/Http/Controllers/ServiceAccountController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ServiceAccountException;
use App\Services\ServiceAccounts;
use App\Support\ServiceAccountAccess;
use App\Support\ServiceAccountSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Console pages for service accounts. Every action checks ServiceAccountAccess; an
 * account the actor may not manage (or of another organization) is a 404.
 */
class ServiceAccountController extends Controller
{
    public function __construct(private readonly ServiceAccounts $accounts)
    {
    }

    public function index(): View
    {
        $actor = $this->actor();

        return view('admin.service-accounts.index', [
            'accounts' => ServiceAccountAccess::visibleQuery($actor)
                ->with(['workspace:id,name', 'owner:id,email'])
                ->withCount(['grants as active_grants_count' => fn ($query) => $query->where('status', 'active')])
                ->orderBy('scope')->orderBy('name')
                ->get(),
            'workspaces' => Workspace::query()->where('org_id', (int) ($actor->org_id ?? 200))->orderBy('name')->get(['id', 'name']),
            'settings' => [
                'sa_default_key_days' => ServiceAccountSettings::defaultKeyDays(),
                'sa_max_key_days' => ServiceAccountSettings::maxKeyDays(),
                'sa_org_quota' => ServiceAccountSettings::orgQuota(),
                'sa_workspace_quota' => ServiceAccountSettings::workspaceQuota(),
                'sa_expiry_notice_days' => implode(',', ServiceAccountSettings::noticeDays()),
                'sa_require_for_registration' => ServiceAccountSettings::requireForRegistration(),
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sa_default_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sa_max_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sa_org_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sa_workspace_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sa_expiry_notice_days' => ['required', 'string', 'max:50', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
        ]);

        ServiceAccountSettings::save($validated + ['sa_require_for_registration' => $request->boolean('sa_require_for_registration')]);

        return back()->with('success', 'Service account settings saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:512'],
            'team' => ['nullable', 'string', 'max:100'],
            'scope' => ['required', 'in:organization,workspace'],
            'workspace_id' => ['required_if:scope,workspace', 'nullable', 'integer'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $allowed = $data['scope'] === ServiceAccount::SCOPE_ORGANIZATION
            ? ServiceAccountAccess::canCreateOrganizationAccount($actor)
            : (($workspace = Workspace::query()->find((int) $data['workspace_id'])) && ServiceAccountAccess::canManageWorkspaceAccounts($actor, $workspace));
        abort_unless($allowed, 403);

        return $this->attempt(fn () => $this->accounts->create($actor, $data), function (ServiceAccount $account) {
            return redirect()->route('service-accounts.show', $account->id)->with('success', 'Service account created. Create a key next.');
        });
    }

    public function show(int $id): View
    {
        $actor = $this->actor();
        $account = $this->findOr404($id);

        return view('admin.service-accounts.show', [
            'account' => $account->load(['workspace:id,name', 'owner:id,email,name', 'grants.workspace:id,name']),
            'keys' => $account->keys()->orderByDesc('id')->get(),
            'activeKeyCount' => $account->activeKeys()->count(),
            'grantableWorkspaces' => $account->isOrganizationScope() && ServiceAccountAccess::canCreateOrganizationAccount($actor)
                ? Workspace::query()->where('org_id', $account->org_id)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'possibleOwners' => User::query()->where('org_id', $account->org_id)->where('status', 'active')->orderBy('email')->get(['id', 'email']),
            'newKey' => session('new_service_account_key'),
            'settings' => ['default_days' => ServiceAccountSettings::defaultKeyDays(), 'max_days' => ServiceAccountSettings::maxKeyDays()],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:512'],
            'team' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->attempt(fn () => $this->accounts->update($account, $this->actor(), $data), fn () => $this->backTo($account, 'Service account updated.'));
    }

    public function status(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $active = $request->boolean('active');

        return $this->attempt(fn () => $this->accounts->setStatus($account, $this->actor(), $active), fn () => $this->backTo($account, $active ? 'Service account enabled.' : 'Service account disabled.'));
    }

    public function owner(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate(['owner_user_id' => ['required', 'integer']]);
        $newOwner = User::query()->find((int) $data['owner_user_id']);
        abort_unless($newOwner, 422);

        return $this->attempt(fn () => $this->accounts->transferOwner($account, $this->actor(), $newOwner), fn () => $this->backTo($account, 'Owner changed.'));
    }

    public function grant(Request $request, int $id): RedirectResponse
    {
        $actor = $this->actor();
        $account = $this->findOr404($id);
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);
        $workspace = Workspace::query()->find((int) $data['workspace_id']);
        abort_unless($workspace && ServiceAccountAccess::canGrant($actor, $account, $workspace), 404);

        return $this->attempt(fn () => $this->accounts->setGrant($account, $actor, $workspace, $data['permissions'] ?? []), fn () => $this->backTo($account, 'Access saved.'));
    }

    public function revokeGrant(int $id, int $workspaceId): RedirectResponse
    {
        $actor = $this->actor();
        $account = $this->findOr404($id);
        $workspace = Workspace::query()->find($workspaceId);
        abort_unless($workspace && ServiceAccountAccess::canGrant($actor, $account, $workspace), 404);

        return $this->attempt(fn () => $this->accounts->removeGrant($account, $actor, $workspace), fn () => $this->backTo($account, 'Access removed.'));
    }

    public function issueKey(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return $this->attempt(
            fn () => $this->accounts->issueKey($account, $this->actor(), $data['name'], isset($data['days']) ? (int) $data['days'] : null),
            // flash(): shown on the next page load only, never stored.
            fn (array $issued) => redirect()->route('service-accounts.show', $account->id)
                ->with('new_service_account_key', $issued['plain'])
                ->with('success', 'Key created. Copy it now: it will not be shown again.'),
        );
    }

    public function revokeKey(Request $request, int $id, int $keyId): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:50']]);
        $key = PatToken::query()
            ->where('tokenable_type', ServiceAccount::class)
            ->where('tokenable_id', $account->id)
            ->whereKey($keyId)
            ->firstOrFail();

        $this->accounts->revokeKey($key, $this->actor(), $data['reason']);

        return $this->backTo($account, 'Key revoked.');
    }

    public function assignSystem(Request $request, int $systemId): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate(['service_account_id' => ['required', 'integer']]);
        $system = SystemRegister::query()->find($systemId);
        abort_unless($system && ServiceAccountAccess::canAssignSystem($actor, $system), 404);

        $account = ServiceAccount::query()->where('org_id', (int) ($actor->org_id ?? 200))->find((int) $data['service_account_id']);
        abort_unless($account, 404);

        return $this->attempt(fn () => $this->accounts->assignSystem($system, $account, $actor), fn () => back()->with(
            'success',
            'System assigned to ' . $account->name . '. Run "sudo atglance --configure" on the server with a key of this service account.',
        ));
    }

    private function actor(): User
    {
        return Auth::user();
    }

    private function findOr404(int $id): ServiceAccount
    {
        return ServiceAccountAccess::findForActor($this->actor(), $id) ?? abort(404);
    }

    private function backTo(ServiceAccount $account, string $message): RedirectResponse
    {
        return redirect()->route('service-accounts.show', $account->id)->with('success', $message);
    }

    /**
     * Runs a ServiceAccounts operation; its refusals return to the form as errors.
     */
    private function attempt(callable $operation, callable $onSuccess): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (ServiceAccountException $e) {
            return back()->withInput()->withErrors(['service_account' => $e->getMessage()]);
        }

        return $onSuccess($result);
    }
}
```

`index` is behind `admin.role`, which redirects non-admins (test `test_member_cannot_create_or_view` expects a redirect there).

- [ ] **Step 5: Organization page view**

`resources/views/admin/service-accounts/index.blade.php`:

```blade
@extends('app')

@section('title', 'Service Accounts - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <h1 style="font-size: 30px; color: var(--ag-text); margin-bottom: 8px;">Service Accounts</h1>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 20px;">
        Machine identities for the <code>atglance</code> CLI. A server registered with a service account keeps working when the person who registered it leaves.
    </p>

    @include('admin.service-accounts.flash')

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Accounts</h2>
        <table class="ag-table" style="width: 100%;">
            <thead>
                <tr><th>Name</th><th>Scope</th><th>Workspaces</th><th>Owner</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse($accounts as $account)
                    <tr>
                        <td><a href="{{ route('service-accounts.show', $account->id) }}">{{ $account->name }}</a></td>
                        <td>{{ ucfirst($account->scope) }}</td>
                        <td>{{ $account->scope === 'workspace' ? $account->workspace?->name : $account->active_grants_count . ' granted' }}</td>
                        <td>
                            @if($account->owner)
                                {{ $account->owner->email }}
                            @else
                                <span class="status-badge status-inactive">No owner</span>
                            @endif
                        </td>
                        <td><span class="status-badge {{ $account->status === 'active' ? 'status-active' : 'status-inactive' }}">{{ ucfirst($account->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="color: var(--ag-muted);">No service accounts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Create service account</h2>
        <form method="POST" action="{{ route('service-accounts.store') }}" style="display: grid; gap: 12px; max-width: 520px;">
            @csrf
            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" value="{{ old('name') }}" required></label>
            <label>Description <input class="ag-input" type="text" name="description" maxlength="512" value="{{ old('description') }}"></label>
            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100" value="{{ old('team') }}"></label>
            <label>Scope
                <select class="ag-input" name="scope" id="saScope">
                    <option value="organization" @selected(old('scope') === 'organization')>Organization (choose workspaces after creating)</option>
                    <option value="workspace" @selected(old('scope', 'workspace') === 'workspace')>One workspace</option>
                </select>
            </label>
            <label id="saWorkspaceField">Workspace
                <select class="ag-input" name="workspace_id">
                    @foreach($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected((int) old('workspace_id') === $workspace->id)>{{ $workspace->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="ag-btn" type="submit">Create</button>
        </form>
    </div>

    <div class="ag-card" style="padding: 24px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Settings</h2>
        <form method="POST" action="{{ route('service-accounts.settings') }}" style="display: grid; gap: 12px; max-width: 520px;">
            @csrf
            @method('PUT')
            <label>Default key lifetime (days) <input class="ag-input" type="number" name="sa_default_key_days" min="1" max="3650" value="{{ $settings['sa_default_key_days'] }}"></label>
            <label>Longest key lifetime (days) <input class="ag-input" type="number" name="sa_max_key_days" min="1" max="3650" value="{{ $settings['sa_max_key_days'] }}"></label>
            <label>Active organization accounts allowed <input class="ag-input" type="number" name="sa_org_quota" min="0" max="1000" value="{{ $settings['sa_org_quota'] }}"></label>
            <label>Active accounts allowed per workspace <input class="ag-input" type="number" name="sa_workspace_quota" min="0" max="1000" value="{{ $settings['sa_workspace_quota'] }}"></label>
            <label>Expiry notices, days before (comma-separated) <input class="ag-input" type="text" name="sa_expiry_notice_days" value="{{ $settings['sa_expiry_notice_days'] }}"></label>
            <label><input type="checkbox" name="sa_require_for_registration" value="1" @checked($settings['sa_require_for_registration'])> Require a service account key to register servers (turn on after every server runs atglance CLI 2.2 or later)</label>
            <button class="ag-btn" type="submit">Save settings</button>
        </form>
    </div>
</div>

<script>
    (function () {
        const scope = document.getElementById('saScope');
        const field = document.getElementById('saWorkspaceField');
        function refresh() { field.style.display = scope.value === 'workspace' ? 'block' : 'none'; }
        scope.addEventListener('change', refresh);
        refresh();
    })();
</script>
@endsection
```

`resources/views/admin/service-accounts/flash.blade.php`:

```blade
@if(session('success'))
    <div style="padding: 12px; border-radius: 12px; background: var(--ag-success-soft); color: var(--ag-success); margin-bottom: 16px;">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div style="padding: 12px; border-radius: 12px; background: var(--ag-danger-soft); color: var(--ag-danger); margin-bottom: 16px;">
        <ul style="margin-left: 16px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
```
- [ ] **Step 6: Account detail view**

`resources/views/admin/service-accounts/show.blade.php`:

```blade
@extends('app')

@section('title', 'Service Account - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">{{ $account->name }}</h1>
        <span class="status-badge {{ $account->status === 'active' ? 'status-active' : 'status-inactive' }}">{{ ucfirst($account->status) }}</span>
    </div>

    @include('admin.service-accounts.flash')

    @if($newKey)
        <div class="ag-card" style="padding: 20px; margin-bottom: 20px; border: 2px solid var(--ag-success);">
            <strong>New key. Copy it now: it will not be shown again.</strong>
            <pre style="margin-top: 8px; user-select: all; white-space: pre-wrap; word-break: break-all;">{{ $newKey }}</pre>
            <p style="font-size: 13px; color: var(--ag-muted);">On the server: <code>sudo atglance --configure</code>, then paste this as the service account key.</p>
        </div>
    @endif

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Details</h2>
        <p>Scope: <strong>{{ ucfirst($account->scope) }}</strong>@if($account->workspace) · Workspace: <strong>{{ $account->workspace->name }}</strong>@endif</p>
        <p>Owner: <strong>{{ $account->owner?->email ?? 'No owner' }}</strong></p>
        <form method="POST" action="{{ route('service-accounts.update', $account->id) }}" style="display: grid; gap: 10px; max-width: 520px; margin-top: 12px;">
            @csrf
            @method('PUT')
            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" value="{{ old('name', $account->name) }}" required></label>
            <label>Description <input class="ag-input" type="text" name="description" maxlength="512" value="{{ old('description', $account->description) }}"></label>
            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100" value="{{ old('team', $account->team) }}"></label>
            <button class="ag-btn" type="submit">Save</button>
        </form>
        <form method="POST" action="{{ route('service-accounts.owner', $account->id) }}" style="display: flex; gap: 10px; margin-top: 12px;">
            @csrf
            <select class="ag-input" name="owner_user_id">
                @foreach($possibleOwners as $candidate)
                    <option value="{{ $candidate->id }}" @selected($candidate->id === $account->owner_user_id)>{{ $candidate->email }}</option>
                @endforeach
            </select>
            <button class="ag-btn ag-btn--ghost" type="submit">Change owner</button>
        </form>
        <form method="POST" action="{{ route('service-accounts.status', $account->id) }}" style="margin-top: 12px;">
            @csrf
            <input type="hidden" name="active" value="{{ $account->status === 'active' ? 0 : 1 }}">
            <button class="ag-btn ag-btn--ghost" type="submit">{{ $account->status === 'active' ? 'Disable (all keys stop working)' : 'Enable' }}</button>
        </form>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Workspace access</h2>
        <table class="ag-table" style="width: 100%;">
            <thead><tr><th>Workspace</th><th>Permissions</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @foreach($account->grants as $grant)
                    <tr>
                        <td>{{ $grant->workspace?->name }}</td>
                        <td>
                            <form method="POST" action="{{ route('service-accounts.grants.store', $account->id) }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                                @csrf
                                <input type="hidden" name="workspace_id" value="{{ $grant->workspace_id }}">
                                @foreach(\App\Models\ServiceAccount::PERMISSIONS as $key => $label)
                                    <label title="{{ $label }}"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, (array) $grant->permissions, true))> {{ $key }}</label>
                                @endforeach
                                <button class="ag-btn ag-btn--ghost" type="submit">Save</button>
                            </form>
                        </td>
                        <td>{{ ucfirst($grant->status) }}</td>
                        <td>
                            @if($account->scope === 'organization' && $grant->status === 'active')
                                <form method="POST" action="{{ route('service-accounts.grants.destroy', [$account->id, $grant->workspace_id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="ag-btn ag-btn--ghost" type="submit">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($grantableWorkspaces->isNotEmpty())
            <form method="POST" action="{{ route('service-accounts.grants.store', $account->id) }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px;">
                @csrf
                <select class="ag-input" name="workspace_id">
                    @foreach($grantableWorkspaces as $workspace)
                        <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                    @endforeach
                </select>
                @foreach(\App\Models\ServiceAccount::PERMISSIONS as $key => $label)
                    <label title="{{ $label }}"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, \App\Models\ServiceAccount::DEFAULT_PERMISSIONS, true))> {{ $key }}</label>
                @endforeach
                <button class="ag-btn" type="submit">Give access</button>
            </form>
        @endif
    </div>

    <div class="ag-card" style="padding: 24px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Keys ({{ $activeKeyCount }} of 2 active)</h2>
        <table class="ag-table" style="width: 100%;">
            <thead><tr><th>Name</th><th>Key</th><th>Expires</th><th>Last used</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($keys as $key)
                    @php($expired = $key->expires_at && $key->expires_at->isPast())
                    <tr>
                        <td>{{ $key->name }}</td>
                        <td><code>atgsa-…{{ $key->last_four }}</code></td>
                        <td>{{ $key->expires_at?->toDateString() }}</td>
                        <td>{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
                        <td>{{ $key->status === 'revoked' ? 'Revoked: ' . $key->revoke_reason : ($expired ? 'Expired' : 'Active') }}</td>
                        <td>
                            @if($key->status === 'active' && !$expired)
                                <form method="POST" action="{{ route('service-accounts.keys.revoke', [$account->id, $key->id]) }}" style="display: flex; gap: 6px;">
                                    @csrf
                                    <input class="ag-input" type="text" name="reason" maxlength="50" placeholder="Reason" required>
                                    <button class="ag-btn ag-btn--ghost" type="submit">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="color: var(--ag-muted);">No keys yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <form method="POST" action="{{ route('service-accounts.keys.store', $account->id) }}" style="display: flex; gap: 10px; margin-top: 12px;">
            @csrf
            <input class="ag-input" type="text" name="name" maxlength="100" placeholder="Key name, e.g. fleet-a" required>
            <input class="ag-input" type="number" name="days" min="1" max="{{ $settings['max_days'] }}" value="{{ $settings['default_days'] }}" style="width: 120px;" aria-label="Days valid">
            <button class="ag-btn" type="submit">Create key</button>
        </form>
        <p style="font-size: 13px; color: var(--ag-muted); margin-top: 8px;">To rotate: create a second key, move the servers to it, then revoke the old key.</p>
    </div>
</div>
@endsection
```

- [ ] **Step 7: Workspace tab**

`resources/views/admin/workspace/tab-service-accounts.blade.php`:

```blade
{{-- Service accounts tab: this workspace's accounts, plus organization accounts with access here (read-only). --}}
<div class="ag-card" style="padding: 24px;">
    <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Service Accounts</h2>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">Machine identities the <code>atglance</code> CLI uses on servers of this workspace.</p>

    <table class="ag-table" style="width: 100%; margin-bottom: 16px;">
        <thead><tr><th>Name</th><th>Scope</th><th>Owner</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($serviceAccountsTab['accounts'] as $saRow)
                <tr>
                    <td>
                        @if($saRow->scope === 'workspace')
                            <a href="{{ route('service-accounts.show', $saRow->id) }}">{{ $saRow->name }}</a>
                        @else
                            {{ $saRow->name }}
                        @endif
                    </td>
                    <td>{{ $saRow->scope === 'workspace' ? 'This workspace' : 'Organization (read-only here)' }}</td>
                    <td>{{ $saRow->owner?->email ?? 'No owner' }}</td>
                    <td>{{ ucfirst($saRow->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="color: var(--ag-muted);">No service accounts in this workspace.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($serviceAccountsTab['canCreate'])
        <form method="POST" action="{{ route('service-accounts.store') }}" style="display: grid; gap: 10px; max-width: 520px;">
            @csrf
            <input type="hidden" name="scope" value="workspace">
            <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" required></label>
            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100"></label>
            <button class="ag-btn" type="submit">Create service account</button>
        </form>
    @endif
</div>
```

In `workspace-detail.blade.php`, add `'service-accounts' => ['Service Accounts', 'fa-robot'],` to `$workspaceTabs` before `'activity'`, and the panel before the activity panel:

```blade
    <div id="tab-service-accounts" class="workspace-tab-content" style="display:{{ $activeTab === 'service-accounts' ? 'block' : 'none' }};">
        @include('admin.workspace.tab-service-accounts')
    </div>
```

In `AdminDashboardController::viewWorkspace`, add to the view data:

```php
            'serviceAccountsTab' => [
                'canCreate' => \App\Support\ServiceAccountAccess::canManageWorkspaceAccounts($actor, $workspace),
                'accounts' => \App\Models\ServiceAccount::query()
                    ->with('owner:id,email')
                    ->where('org_id', (int) ($workspace->org_id ?? 200))
                    ->where(fn ($query) => $query
                        ->where('workspace_id', $workspace->id)
                        ->orWhereHas('grants', fn ($grants) => $grants->where('workspace_id', $workspace->id)->where('status', 'active')))
                    ->orderBy('scope')->orderBy('name')
                    ->get(),
            ],
```

- [ ] **Step 8: Registered systems column and assign action**

In `resources/views/systems-registered.blade.php`, find the table header row and add a `<th>Service account</th>`; in each row add:

```blade
<td>
    {{ $system->serviceAccount?->name ?? '—' }}
    @if(\App\Support\ServiceAccountAccess::canAssignSystem(auth()->user(), $system))
        <form method="POST" action="{{ route('systems-registered.service-account', $system->id) }}" style="display: inline-flex; gap: 6px;">
            @csrf
            <select class="ag-input" name="service_account_id" aria-label="Service account">
                @foreach(\App\Models\ServiceAccount::query()->where('org_id', $system->org_id)->where('status', 'active')->get() as $candidate)
                    @if($candidate->can('systems.register', (int) $system->workspace_id))
                        <option value="{{ $candidate->id }}" @selected($candidate->id === $system->service_account_id)>{{ $candidate->name }}</option>
                    @endif
                @endforeach
            </select>
            <button class="ag-btn ag-btn--ghost" type="submit">Assign</button>
        </form>
    @endif
</td>
```

If `$system` in that view is a stdClass from a query builder (not a `SystemRegister` model), load models in `DashboardController::systemsRegistered` with `SystemRegister::with('serviceAccount')->whereIn('id', $ids)->get()->keyBy('id')` and use `$models[$system->id]` in the snippet above. Check the controller before editing.

Add a sidebar link for admins (rbac 100/101) next to the existing admin links: `<a href="{{ route('service-accounts.index') }}"><i class="fas fa-robot"></i> Service Accounts</a>`, using the markup of the neighbouring links.

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter="ServiceAccountConsoleTest|WorkspaceSettingsTest|ConsoleListsTest"`
Expected: PASS. Then start the dev stack (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build` from the repo root), sign in as the super admin, and click through: create an organization account, give it a workspace, create a key, revoke it, open a workspace's Service Accounts tab, assign a system.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers routes/web.php resources/views tests/Feature/ServiceAccountConsoleTest.php
git commit -m "feat(service-accounts): console pages, workspace tab and system assignment"
```

---

### Task 11: Console pages with service-account-owned data

**Files:**
- Modify: whichever of `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/AdminDashboardController.php`, `app/Http/Controllers/Api/McpController.php`, `app/Services/BackupService.php` the audit below finds
- Test: `tests/Feature/ServiceAccountOwnedDataTest.php`

**Interfaces:**
- Consumes: Task 7 upload path.
- Produces: no new interfaces.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ServiceAccountOwnedDataTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ConfigurationFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountOwnedDataTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    private ConfigurationFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Storage::fake('local');
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);

        $registrar = $this->makeUser('registrar@example.test');
        $this->workspace->addUser($registrar->id);
        $account = $this->makeAccount();
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);

        $id = $this->post('/api/v1/config-files', [
            'service_name' => 'sshd',
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"),
        ], ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'] + $this->systemHeaders($system))->assertCreated()->json('file.id');
        $this->file = ConfigurationFile::findOrFail($id);
    }

    public function test_pages_render_for_member_and_admin(): void
    {
        $member = \App\Models\User::where('email', 'registrar@example.test')->first();
        $admin = $this->makeUser('admin@example.test', 100);

        foreach ([$member, $admin] as $viewer) {
            $this->actingAs($viewer)->get(route('dashboard'))->assertOk();
            $this->actingAs($viewer)->get(route('configuration-backups'))->assertOk()->assertSee('sshd');
            $this->actingAs($viewer)->get(route('configuration-backups.view', $this->file->id))->assertOk();
            $this->actingAs($viewer)->get(route('configuration-backups.download', $this->file->id))->assertOk();
            $this->actingAs($viewer)->get(route('configuration-backups.service-versions', $this->file->service_id))->assertOk();
            $this->actingAs($viewer)->get(route('systems-registered'))->assertOk();
        }
    }

    public function test_mcp_lists_service_account_files(): void
    {
        $member = \App\Models\User::where('email', 'registrar@example.test')->first();
        [$plain] = $this->userKey($member);

        $this->getJson('/api/mcp/config-files', ['Authorization' => 'Bearer ' . $plain])->assertOk()->assertSee('sshd_config');
    }
}
```

- [ ] **Step 2: Run the test to see what breaks**

Run: `php artisan test --filter=ServiceAccountOwnedDataTest`
Expected: some FAIL with 500s or missing rows. Note each failing route.

- [ ] **Step 3: Audit `user_id` uses on the 4 tables**

Run from `composer/`:

```bash
grep -rn "join('users\|join(\"users\|->user->\|user_id'" app --include=*.php | grep -i "config\|raw_data\|services\|cf\.\|rd\.\|s\." 
grep -rn "where('user_id'\|where('cf.user_id'\|where('services.user_id'" app --include=*.php
```

For each hit on `configuration_files`, `raw_data` or `services`:
- `->join('users', ...)` on these tables: change to `->leftJoin('users', ...)` and print the uploader as `COALESCE(users.name, service_accounts.name)` via `->leftJoin('service_accounts', 'service_accounts.id', '=', '<alias>.service_account_id')`.
- `$file->user->name` (or similar): change to `$file->user?->name ?? $file->serviceAccount?->name`.
- Visibility filters `where('<table>.user_id', $actor->id)`: leave them only where they mean "the caller's own uploads"; workspace visibility already comes from `ScopesWorkspaceData` through the system's `workspace_id`.
- `config_files/{user_id}` storage paths in `BackupService` or migration code: also include `config_files/sa-*`.

Make the smallest change per hit and rerun the test after each file.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=ServiceAccountOwnedDataTest`
Expected: PASS. Then the whole suite: `php artisan test`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app tests/Feature/ServiceAccountOwnedDataTest.php
git commit -m "fix(service-accounts): console and MCP views handle uploads without a user"
```

---

### Task 12: Person key (PAT) hardening

**Files:**
- Create: `app/Services/UserKeys.php`
- Create: `database/migrations/2026_10_13_020000_clear_encrypted_pat_tokens.php`
- Modify: `app/Support/ServiceAccountSettings.php` (PAT settings)
- Modify: `app/Http/Controllers/DashboardController.php` (`createApiKey`, `viewApiKey`, `revokeApiKey`)
- Modify: `app/Http/Controllers/Api/PatTokenController.php` (`index`, `store`)
- Modify: `resources/views/settings.blade.php` (remove the View button and its script; expiry required)
- Modify: `resources/views/admin/service-accounts/index.blade.php` (3 PAT settings fields)
- Modify: `app/Http/Controllers/ServiceAccountController.php` (`index` passes and `updateSettings` saves PAT settings)
- Test: `tests/Feature/PatHardeningTest.php`

**Interfaces:**
- Consumes: `PatToken`, `ServiceAccountException`.
- Produces:
  - `ServiceAccountSettings::patDefaultKeyDays(): int` (90), `patMaxKeyDays(): int` (365), `patUserQuota(): int` (5); `save()` accepts `pat_default_key_days`, `pat_max_key_days`, `pat_user_quota`.
  - `UserKeys::issue(User $user, string $name, ?CarbonInterface $expiresAt): array` returning `['token' => PatToken, 'plain' => string]`; throws `ServiceAccountException` with code `UserKeys::LIMIT` (409) or `UserKeys::INVALID` (422).
  - `UserKeys::revoke(PatToken $key, User $actor, string $reason): void`

- [ ] **Step 1: Write the failing test**

`tests/Feature/PatHardeningTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PatToken;
use App\Models\User;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class PatHardeningTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);
        $this->activateLicence();
    }

    /**
     * createApiKey and PatTokenController::store refuse without an active licence.
     * Set whatever App\Support\License::isActive() reads (see LicenseTest for the pattern).
     */
    private function activateLicence(): void
    {
        // Copy the licence setup used in tests/Feature/LicenseTest.php here.
    }

    public function test_console_key_is_hash_only_with_default_expiry(): void
    {
        $user = $this->makeUser('u@example.test');

        $response = $this->actingAs($user)->postJson(route('settings.api-keys.create'), ['name' => 'laptop'])->assertOk();
        $key = PatToken::findOrFail($response->json('key_id'));

        $this->assertNull($key->token_encrypted);
        $this->assertSame(hash('sha256', $response->json('token')), $key->token);
        $this->assertSame(substr($response->json('token'), -4), $key->last_four);
        $this->assertTrue($key->expires_at->between(now()->addDays(89), now()->addDays(91)));
    }

    public function test_expiry_over_maximum_is_refused(): void
    {
        $user = $this->makeUser('u@example.test');

        $this->actingAs($user)->postJson(route('settings.api-keys.create'), ['name' => 'x', 'expiration_date' => now()->addDays(400)->toDateString()])
            ->assertStatus(422);
    }

    public function test_limit_and_same_name_replace(): void
    {
        ServiceAccountSettings::save(['pat_user_quota' => 2]);
        $user = $this->makeUser('u@example.test');
        $hostA = $this->namedKey($user, 'host-a');
        $this->namedKey($user, 'host-b');
        $this->actingAs($user);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'host-c'])->assertStatus(409);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'host-a'])->assertOk();
        $old = PatToken::where('token', hash('sha256', $hostA))->first();
        $this->assertSame('revoked', $old->status);
        $this->assertSame('replaced', $old->revoke_reason);
        $this->assertSame(2, PatToken::where('tokenable_id', $user->id)->where('tokenable_type', User::class)->where('status', 'active')->count());
    }

    public function test_expired_and_revoked_keys_do_not_count(): void
    {
        ServiceAccountSettings::save(['pat_user_quota' => 1]);
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);
        $key->update(['expires_at' => now()->subDay()]);

        $this->actingAs($user)->postJson(route('settings.api-keys.create'), ['name' => 'new'])->assertOk();
    }

    public function test_view_endpoint_is_gone(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);

        $this->actingAs($user)->postJson(route('settings.api-keys.view'), ['key_id' => $key->id, 'pin' => '1234'])
            ->assertStatus(410)->assertJsonPath('message', 'API keys are shown only when created');
    }

    public function test_api_index_hides_hashes_and_store_uses_the_same_rules(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->userKey($user);
        $headers = $this->sessionHeaders($user);

        $this->getJson('/api/auth/pat-tokens', $headers)
            ->assertOk()->assertJsonMissingPath('tokens.0.token_hash')->assertJsonPath('tokens.0.status', 'active');

        $created = $this->postJson('/api/auth/pat-tokens', ['name' => 'cli-host'], $headers)->assertCreated();
        $this->assertNull(PatToken::findOrFail($created->json('token.id'))->token_encrypted);
    }

    public function test_console_revoke_records_actor_and_reason(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);

        $this->actingAs($user)->postJson(route('settings.api-keys.revoke'), ['key_id' => $key->id, 'pin' => '1234'])->assertOk();
        $key->refresh();
        $this->assertSame('revoked', $key->status);
        $this->assertSame('revoked_by_user', $key->revoke_reason);
        $this->assertSame($user->id, (int) $key->revoked_by_user_id);
    }

    public function test_migration_clears_stored_plaintext_and_old_key_still_works(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain, $key] = $this->userKey($user);
        $key->forceFill(['token_encrypted' => Crypt::encryptString($plain)])->save();

        $migration = require database_path('migrations/2026_10_13_020000_clear_encrypted_pat_tokens.php');
        $migration->up();

        $this->assertNull($key->fresh()->token_encrypted);
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }

    private function namedKey(User $user, string $name): string
    {
        [$plain, $token] = $this->userKey($user);
        $token->update(['name' => $name]);

        return $plain;
    }

    /**
     * /api/auth/pat-tokens uses auth.session (the session token from /api/auth/login).
     *
     * @return array<string, string>
     */
    private function sessionHeaders(User $user): array
    {
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();

        return ['Authorization' => 'Bearer ' . ($login->json('token') ?? $login->json('access_token') ?? $login->json('session_token'))];
    }
}
```

Before running: fill `activateLicence()` with the setup `tests/Feature/LicenseTest.php` uses for an active licence, and check which field `Api\AuthController::login` returns for the session token.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PatHardeningTest`
Expected: FAIL (`token_encrypted` is set; no limit; view returns 200 or 401).

- [ ] **Step 3: PAT settings**

In `ServiceAccountSettings`, add to `DEFAULTS`:

```php
        'pat_default_key_days' => 90,
        'pat_max_key_days' => 365,
        'pat_user_quota' => 5,
```

add methods:

```php
    public static function patDefaultKeyDays(): int
    {
        return min(self::int('pat_default_key_days', 1, 3650), self::patMaxKeyDays());
    }

    public static function patMaxKeyDays(): int
    {
        return self::int('pat_max_key_days', 1, 3650);
    }

    public static function patUserQuota(): int
    {
        return self::int('pat_user_quota', 1, 100);
    }
```

and in `save()` replace the `sa_max_key_days`-only clamp with:

```php
            if (in_array($key, ['sa_max_key_days', 'pat_max_key_days'], true)) {
                $value = max(1, min(3650, (int) $value));
            }
```

- [ ] **Step 4: Write `UserKeys`**

`app/Services/UserKeys.php`:

```php
<?php

namespace App\Services;

use App\Models\PatToken;
use App\Models\User;
use App\Support\ServiceAccountSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues and revokes a person's atgla- keys (console and CLI login). Hash only, an
 * expiry is required, at most patUserQuota() active keys; a new key with the name of
 * an active key replaces it, so a CLI logging in again from the same host never
 * reaches the limit.
 */
class UserKeys
{
    public const LIMIT = 409;
    public const INVALID = 422;

    /**
     * @return array{token: PatToken, plain: string}
     */
    public function issue(User $user, string $name, ?CarbonInterface $expiresAt): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The key name must be 1 to 100 characters.', self::INVALID);
        }

        $max = ServiceAccountSettings::patMaxKeyDays();
        $expiresAt ??= now()->addDays(ServiceAccountSettings::patDefaultKeyDays())->endOfDay();
        if ($expiresAt->isPast() || $expiresAt->greaterThan(now()->addDays($max)->endOfDay())) {
            throw new ServiceAccountException("The expiry date must be within the next {$max} days.", self::INVALID);
        }

        return DB::transaction(function () use ($user, $name, $expiresAt) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $active = PatToken::query()
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));

            foreach ((clone $active)->where('name', $name)->get() as $sameName) {
                $this->revoke($sameName, $user, 'replaced');
            }

            $quota = ServiceAccountSettings::patUserQuota();
            if ((clone $active)->count() >= $quota) {
                throw new ServiceAccountException("You have {$quota} active API keys. Revoke one or reuse a key name.", self::LIMIT);
            }

            $plain = PatToken::generateCustomToken();
            $token = PatToken::create([
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
                'user_id' => $user->id,
                'name' => $name,
                'token' => hash('sha256', $plain),
                'token_encrypted' => null,
                'last_four' => substr($plain, -4),
                'abilities' => ['*'],
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);

            return ['token' => $token, 'plain' => $plain];
        });
    }

    public function revoke(PatToken $key, User $actor, string $reason): void
    {
        $key->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'revoke_reason' => mb_substr($reason, 0, 50),
        ])->save();
    }
}
```

The same-name revoke and the quota check run in one transaction: if the quota check throws, the same-name revoke rolls back too.

- [ ] **Step 5: Use it in the console**

In `DashboardController::createApiKey`, replace everything from `/** @var User $user */` to the `ActivityRecorder::record(...)` line with:

```php
        /** @var User $user */
        $user = Auth::user();
        $requestedExpiry = !empty($validated['expiration_date']) ? Carbon::parse($validated['expiration_date'])->endOfDay() : null;

        try {
            ['token' => $token, 'plain' => $plainToken] = app(UserKeys::class)->issue($user, $validated['name'], $requestedExpiry);
        } catch (ServiceAccountException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 422);
        }
        $expiresAt = $token->expires_at;

        ActivityRecorder::record($user->id, 'apikey.created', 'Created API key "' . $token->name . '" (…' . $token->last_four . ')', ActivityRecorder::SUCCESS, $request);
```

Replace the whole `viewApiKey` method with:

```php
    /**
     * Keys are stored as a hash only (2.1.2), so they cannot be shown again.
     */
    public function viewApiKey(Request $request)
    {
        return response()->json(['success' => false, 'message' => 'API keys are shown only when created'], 410);
    }
```

In `revokeApiKey`, replace `$token->update(['status' => 'revoked']);` with:

```php
        app(UserKeys::class)->revoke($token, $user, 'revoked_by_user');
```

The failure branch in `revokeApiKey` still mentions `settings.api-keys.revoke`/`apikey.viewed`; change its event to plain `'apikey.revoked'`.

Imports: `App\Services\UserKeys`, `App\Services\ServiceAccountException`. Remove the `Crypt` import only if `grep -n "Crypt::" app/Http/Controllers/DashboardController.php` finds no other use.

- [ ] **Step 6: Use it in the CLI API**

In `PatTokenController::store`, replace from `$user = $request->user();` to the end of the method with:

```php
        $user = $request->user();
        $requestedExpiry = !empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

        try {
            ['token' => $token, 'plain' => $plainToken] = app(UserKeys::class)->issue($user, $data['name'], $requestedExpiry);
        } catch (ServiceAccountException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        }

        return response()->json([
            'access_token' => $plainToken,
            'token_type' => 'pat',
            'message' => 'Save this key securely: it will not be shown again. It expires on ' . $token->expires_at->toDateString() . '.',
            'token' => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ],
        ], 201);
```

The `abilities` input is still validated but ignored: every key gets `['*']` until scopes land.

Replace `PatTokenController::index` with:

```php
    public function index(Request $request)
    {
        $tokens = PatToken::query()
            ->where('tokenable_type', \App\Models\User::class)
            ->where('tokenable_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Keys are shown only when created.',
            'tokens' => $tokens->map(fn (PatToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_four' => $token->last_four,
                'status' => $token->status,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at,
                'last_used_at' => $token->last_used_at,
                'revoked_at' => $token->revoked_at,
                'created_at' => $token->created_at,
            ]),
        ]);
    }
```

Imports: `App\Services\UserKeys`, `App\Services\ServiceAccountException`.

- [ ] **Step 7: Migration to clear stored plaintext**

`database/migrations/2026_10_13_020000_clear_encrypted_pat_tokens.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * From 2.1.2 keys are stored as a hash only. Remove the recoverable copies kept for
     * the old "view key" button. The keys keep working: their hash is unchanged.
     */
    public function up(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'token_encrypted')) {
            DB::table('personal_access_tokens')->whereNotNull('token_encrypted')->update(['token_encrypted' => null]);
        }
    }

    public function down(): void
    {
        // The plaintext cannot be restored.
    }
};
```

- [ ] **Step 8: Console views and settings**

In `resources/views/settings.blade.php`:
- Remove the "View" button in each `apiKeysList` row and the script that posts to `route("settings.api-keys.view")` (around line 880). Keep Revoke.
- Make expiry required: remove any "never expires" choice of the expiration selector (around line 781), default it to 90 days, and set `max` on the date input to `{{ now()->addDays(\App\Support\ServiceAccountSettings::patMaxKeyDays())->toDateString() }}`.
- Show `…{{ $apiKey->last_four ?? '????' }}` next to each key name.

In `resources/views/admin/service-accounts/index.blade.php`, add to the Settings form before the checkbox:

```blade
            <label>Personal API key default lifetime (days) <input class="ag-input" type="number" name="pat_default_key_days" min="1" max="3650" value="{{ $settings['pat_default_key_days'] }}"></label>
            <label>Personal API key longest lifetime (days) <input class="ag-input" type="number" name="pat_max_key_days" min="1" max="3650" value="{{ $settings['pat_max_key_days'] }}"></label>
            <label>Active personal API keys per user <input class="ag-input" type="number" name="pat_user_quota" min="1" max="100" value="{{ $settings['pat_user_quota'] }}"></label>
```

In `ServiceAccountController::index`, add to `settings`:

```php
                'pat_default_key_days' => ServiceAccountSettings::patDefaultKeyDays(),
                'pat_max_key_days' => ServiceAccountSettings::patMaxKeyDays(),
                'pat_user_quota' => ServiceAccountSettings::patUserQuota(),
```

and to the `updateSettings` validation:

```php
            'pat_default_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'pat_max_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'pat_user_quota' => ['required', 'integer', 'min:1', 'max:100'],
```

Update `ServiceAccountConsoleTest::test_settings_update_by_admin` to send these 3 fields too.

- [ ] **Step 9: Run the tests**

Run: `php artisan test --filter="PatHardeningTest|UserApiAuthTest|SecurityPrivacyTest|ServiceAccountConsoleTest"`
Expected: PASS. Then `php artisan test`. Update existing tests that read `token_encrypted`, call the view endpoint, or expect the 2099 expiry.

- [ ] **Step 10: Commit**

```bash
git add app database/migrations/2026_10_13_020000_clear_encrypted_pat_tokens.php resources/views tests/Feature
git commit -m "feat(pat): hash-only keys, required expiry, per-user limit, same-name replace"
```

---

### Task 13: Documentation

**Files:**
- Create: `docs/service-accounts.md`
- Modify: `docs/api-reference.md`, `docs/api-keys.md`, `docs/notifications.md`, `README.md` (Documentation table)

**Interfaces:** none.

- [ ] **Step 1: Write `docs/service-accounts.md`**

Sections, each short, with the exact values from Global Constraints:

1. **What a service account is.** Machine identity; workspace or organization scope; why (servers survive people leaving).
2. **Who can do what.** The table of spec section 4, with the `service_accounts` workspace permission.
3. **Permissions.** The 4 permissions and defaults; deregister and reactivate are human only.
4. **Keys.** `atgsa-` format, shown once, 90 days default, 365 max, 2 active, rotation steps (create B, move servers, revoke A).
5. **Registering a server.** The CLI flow with both headers:

   ```
   POST /api/v1/systems
   Authorization: Bearer atgla-...
   X-Atglance-Service-Account-Key: atgsa-...
   ```

   The "Require service account for registration" setting and when to turn it on.
6. **Moving existing servers.** Registered systems > Assign, then `sudo atglance --configure` with an `atgsa-` key.
7. **When a person leaves.** Their keys are revoked on deactivation, deletion or organization change; reactivation does not restore them; owned accounts show "No owner".
8. **Notifications.** The three levels and the daily `atglance:credentials-sweep` at 06:10.
9. **Settings.** The six `admin_settings` keys with defaults.

- [ ] **Step 2: Update the other docs**

- `docs/api-reference.md`: `whoami` response for both identity types (spec 5.5 JSON); `POST /v1/systems` header and errors (401 invalid service account key, 403 missing `systems.register`, 422 when the setting requires it); permission per v1 route and the 403 message; deregister and reactivate 403 for service accounts; 401 messages `Invalid key`, `Key is revoked`, `Key has expired`, `Identity is disabled`; legacy routes reject `atgsa-` keys.
- `docs/api-keys.md`: two prefixes; hash-only storage (no View button); required expiry (90 days default, 365 max); 5 active keys per user and same-name replace; revoke on deactivation, deletion and organization change; reactivation does not restore keys; existing keys keep their expiry and should be replaced.
- `docs/notifications.md`: add every new event from Task 9 with its level, and note that `system.reactivated` replaced reactivations sent as `system.registered` (existing subscriptions were migrated).
- `README.md`: add `docs/service-accounts.md` to the Documentation table under "Use".

- [ ] **Step 3: Commit**

```bash
git add docs README.md
git commit -m "docs: service accounts, API changes and new notification events"
```

---

## Final verification

- [ ] Run the whole suite in the container: `docker run --rm -e VIEW_COMPILED_PATH=/tmp/views -v "$(pwd)/composer:/app" -w /app --entrypoint php ce-atglance-app:dev artisan test` (from the repo root). Expected: all PASS.
- [ ] Run the migrations on MySQL in the dev stack: `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build`, then `docker exec ce-atglance-app php artisan migrate:status`. Expected: both new migrations `Ran`.
- [ ] Manual CLI check with curl against `http://localhost:8002` (gateway): `whoami` with an `atgsa-` key; register with both keys; upload with the account key and system headers; deregister with the account key (403).
- [ ] Confirm Kong passes the new header: `kong/kong.yml` must not strip `X-Atglance-Service-Account-Key`. If routes list allowed headers, add it and rebuild the gateway image.
