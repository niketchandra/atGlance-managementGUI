# Service accounts: design

- **Date:** 2026-10-13
- **Branch:** Version2.1.2
- **Status:** Draft for review
- **Slices:** 1 and 2 of 5, release 2.1.2 (see section 12)

## 1. Goal

A registered server must not depend on the person who registered it. Today the
`atglance` CLI authenticates with a person's `atgla-` key, and the server row
points at that person. When the person leaves:

| Event | What happens today |
|---|---|
| User set to `inactive` | `EnsureUserIsActive` answers 403; every server they registered stops uploading. |
| Their key expires or is revoked | Same: their servers stop uploading. |
| User deleted | `configuration_files`, `raw_data` and `services` cascade on `user_id`: the backup history of their servers is deleted. |
| Key reach | `abilities ['*']`: a server key carries the person's full rights in every workspace they can reach. |

This slice adds **service accounts**: non-human identities, scoped to one
workspace or to chosen workspaces of an organization, with their own keys. A
server runs as a service account. A person's key authorizes human actions only.
It also revokes a person's keys as soon as they are deactivated, deleted or
moved to another organization.

### 1.1 Success criteria

- Deactivating or deleting the person who registered a server does not stop the
  server from uploading, when the server runs as a service account.
- A workspace service account cannot act in any other workspace, whatever ids
  the caller sends.
- An organization service account acts only in workspaces it has an active
  grant for, and only with the permissions of that grant.
- Deactivating, deleting or moving a user revokes all their `atgla-` keys in the
  same transaction.
- Every create, change, key and ownership event is in `activity_logs` and
  reaches the right notification level.
- Existing 1.x and 2.x CLIs keep working until an admin turns on the
  "Require service account for registration" setting.

## 2. Identities and keys

| Identity | Key prefix | Owner of key (`tokenable_type`) | Used for |
|---|---|---|---|
| Person | `atgla-` | `App\Models\User` | `atglance login`; registration (together with a service account key); deregister and reactivate (with password or PIN) |
| Service account | `atgsa-` | `App\Models\ServiceAccount` | Everything a registered server does: upload, read, status, rotate system key |

A key must match its prefix: an `atgsa-` key that resolves to a user, or an
`atgla-` key that resolves to a service account, is rejected with 401.

A service account has no password, no PIN, no session and no role. It never
appears in user lists, member pickers, seat counts or role editors, because it
is not a `users` row.

## 3. Data model

### 3.1 New table `service_accounts`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, auto | |
| `name` | string(100) | Unique per organization for `organization` scope, unique per workspace for `workspace` scope |
| `description` | string(512), nullable | |
| `team` | string(100), nullable | Free-text owning team |
| `scope` | enum `organization`, `workspace` | Never changes after creation |
| `org_id` | bigint, FK `organizations` | |
| `workspace_id` | bigint, nullable, FK `workspaces` | Required for `workspace` scope, null for `organization` scope. Never changes. |
| `owner_user_id` | bigint, nullable, FK `users`, `nullOnDelete` | Accountable owner. Null means "no owner" and is flagged. |
| `created_by_user_id` | bigint, nullable, FK `users`, `nullOnDelete` | Audit only |
| `status` | enum `active`, `disabled` | |
| timestamps | | |

### 3.2 New table `service_account_workspaces` (grants)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, auto | |
| `service_account_id` | FK `service_accounts`, `cascadeOnDelete` | |
| `workspace_id` | FK `workspaces`, `cascadeOnDelete` | Must be in the account's organization |
| `permissions` | JSON | Subset of `ServiceAccount::PERMISSIONS` |
| `status` | enum `active`, `revoked` | |
| `granted_by_user_id` | bigint, nullable, `nullOnDelete` | |
| timestamps | | Unique (`service_account_id`, `workspace_id`) |

A workspace service account gets exactly one grant, for its own workspace,
created with the account. Only its permissions can be edited; it cannot be
revoked or moved. An organization service account gets one grant per workspace
an admin adds.

### 3.3 Permissions

`ServiceAccount::PERMISSIONS`:

| Key | Allows | Default |
|---|---|---|
| `systems.register` | Be the identity of a newly registered server in this workspace | on |
| `configs.write` | Upload configuration files | on |
| `configs.read` | List and download configuration files | on |
| `systems.rotate_key` | Rotate the system key of its own server | off |

Deregister and reactivate are never service account actions (section 5.4).

### 3.4 Changes to existing tables

- `personal_access_tokens`:
  - Service account keys: `tokenable_type = App\Models\ServiceAccount`,
    `user_id = null`, `token_encrypted = null` (hash only),
    `abilities = []`, `expires_at` required.
  - New columns for all keys: `revoked_at` (nullable timestamp),
    `revoked_by_user_id` (nullable), `revoke_reason` (nullable string(50)),
    `expiry_notified_stage` (nullable tinyint: null, 14 or 3).
- `system_register`: new nullable `service_account_id`. `user_id` stays
  required and means "registered by", which is also the owner for the
  deregister and reactivate rules.
- `configuration_files`, `raw_data`, `services`: new nullable
  `service_account_id`; `user_id` becomes nullable. Uploads from a service
  account set `service_account_id` and leave `user_id` null.

### 3.5 Settings (`admin_settings`)

| Key | Default | Meaning |
|---|---|---|
| `sa_default_key_days` | 90 | Pre-filled key lifetime |
| `sa_max_key_days` | 365 | Longest key lifetime allowed |
| `sa_org_quota` | 10 | Active organization service accounts per organization |
| `sa_workspace_quota` | 5 | Active workspace service accounts per workspace |
| `sa_expiry_notice_days` | `14,3` | Days before expiry when notices go out |
| `sa_require_for_registration` | off | When on, registration needs a service account key (section 5.2) |

Disabled accounts and revoked or expired keys do not count toward quotas or
the key limit.

## 4. Who may manage service accounts

Enforced in `ServiceAccountPolicy`, never only in the views.

| Actor | Organization service account | Workspace service account |
|---|---|---|
| Super admin (`rbac_id` 100) | Create and manage in own organization | Any workspace of own organization |
| Admin (101) | Create and manage in own organization | Any workspace of own organization |
| Workspace admin | No | Own workspace only, when they have the new `service_accounts` permission |
| Member, service account | No | No |

- New entry in `Workspace::PERMISSIONS`:
  `'service_accounts' => ['label' => 'Manage service accounts', 'default' => false]`.
  Admin-role workspace admins and the super admin have it, as for every other
  permission.
- A workspace admin cannot create organization accounts or add grants to them.
- An organization account may be granted only workspaces of its own
  organization.
- Grants never hold permissions outside `ServiceAccount::PERMISSIONS`.
- A service account key cannot call any management route. Those are web routes
  behind sessions.
- Every lookup filters by the actor's `org_id`. Ids from another organization
  answer 404, never 403.
- The organization admin role is on hold (section 12). When it lands, it takes
  the Admin row of this table for its own organization.

## 5. Authentication and the CLI flow

### 5.1 `auth.pat`

`AuthenticatePatToken` accepts `atgla-` and `atgsa-` keys:

1. Hash the key and look it up. Unknown: 401 `Invalid key`.
2. Check that the prefix matches `tokenable_type`. Mismatch: 401.
3. `status` must be `active`, with no `revoked_at`. Otherwise: 401 `Key is revoked`.
4. `expires_at` must be in the future. Otherwise: 401 `Key has expired`.
5. User key: the user must be active. Service account key: the account must be
   `active`. Otherwise: 401 `Identity is disabled`.
6. Set `last_used_at`, put the key in `patToken` and the identity in
   `identity`. For a user key, set the user resolver as today. For a service
   account key, `$request->user()` stays null, so every route that needs a
   person refuses it without extra code.

No step falls back to another identity.

### 5.2 Registration: `POST /v1/systems`

The person logs in with `atglance login` (their `atgla-` key). To register, the
CLI sends both keys:

```
Authorization: Bearer atgla-...                  # who authorizes
X-Atglance-Service-Account-Key: atgsa-...        # what the server runs as
```

The server checks, in order:

1. The user key passes `auth.pat` and `active.user`.
2. The user may register in `workspace_id` (as today: a member of that
   workspace, or an admin of the organization).
3. The service account key passes the checks of section 5.1, and the account
   is in the user's organization.
4. The account has an active grant on `workspace_id` with `systems.register`.

The new `system_register` row stores `service_account_id`, `user_id` (the
registering user) and `pat_token_id` (the user's key). The audit record names
both.

Without the service account header:
- `sa_require_for_registration` off: registration works as today (person-owned
  server). The 1.x route `/api/system-register` behaves the same way.
- Setting on: 422 `Registration needs a user key and a service account key`, on
  both routes.

With only an `atgsa-` key (no user key): 401, as for every route that needs a
person.

After registration the CLI keeps both keys on the host (CLI decision). The CLI
should store them readable by root only (`0600`).

### 5.3 Server routes

For a service account key, `atglance.system` (`AuthenticateSystemKey`) checks:
the system key matches, the system's `service_account_id` is the calling
account, and the account has an active grant on the system's workspace.
Anything else answers the same 404 as today. Person keys keep today's rule.

Each route then checks its permission on that grant:

| Route | Permission |
|---|---|
| `POST /v1/config-files` | `configs.write` |
| `GET /v1/config-files`, `GET /v1/config-files/{id}` | `configs.read` |
| `GET /v1/systems/self` | any active grant |
| `POST /v1/systems/self/rotate-key` | `systems.rotate_key` (no PIN; audited) |

Missing permission: 403 `Service account lacks <permission> in this workspace`.

### 5.4 Deregister and reactivate

Always a person: `atgla-` key plus password or PIN. Allowed for:
- the system's owner (`user_id`);
- an admin (100 or 101) of the system's organization;
- **new:** a workspace admin who is a member of the system's workspace.

`SystemRegisterController::canManageSystem` gains the workspace admin case.
With a service account key: 403 `Deregister and reactivate need a user key with
password or PIN`.

When the actor is not the owner (for example, the owner left), the action is
allowed by the rules above, and the audit record and the notification carry
the actor and the original owner.

### 5.5 `GET /v1/whoami`

User key: as today, plus `"identity_type": "user"`.

Service account key:

```json
{
  "identity_type": "service_account",
  "service_account": {"id": 12, "name": "prod-backup", "scope": "workspace", "org_id": 200},
  "workspaces": [{"id": 4, "name": "Production", "permissions": ["systems.register", "configs.write", "configs.read"]}],
  "token": {"id": 88, "name": "host-fleet-a", "expires_at": "2027-01-11T00:00:00+00:00"}
}
```

The CLI uses this to check a service account key before registration. No new
endpoint is needed.

## 6. Key lifecycle

- Key format: `atgsa-` followed by 32 characters from `random_bytes`, base62.
  Only the SHA-256 hash is stored.
- The plaintext is shown once, at creation. It cannot be viewed again.
- `expires_at` is required, defaults to `sa_default_key_days` and cannot be
  later than `sa_max_key_days` from now.
- At most **2 active keys** per service account. To rotate: issue key B, move
  the servers to B, revoke A.
- Revoke stores `revoked_at`, `revoked_by_user_id` and `revoke_reason`. A
  revoked key never becomes active again.
- Disabling an account blocks all its keys at once (section 5.1, step 5);
  enabling it again unblocks keys that are neither revoked nor expired.
- Expiry never moves, whatever the key is used for.

## 7. Revoking a person's keys

These events revoke **every** active `atgla-` key of the user in the same
transaction as the change:

| Event | `revoke_reason` |
|---|---|
| User set to `inactive` | `user_deactivated` |
| User deleted | `user_deleted` (revoked before the row is deleted) |
| User's `org_id` changes | `user_org_changed` |

- Rows are revoked, not deleted, so audit keeps them.
- Reactivating the user does not restore any key; they create new ones.
- Removing a user from one workspace does not revoke keys; workspace access is
  checked live on each request.
- Service accounts the user owned are kept. `owner_user_id` becomes null and
  `service_account.owner_missing` is sent (section 9).
- `auth.pat` still checks that the user is active, as a second line.

## 8. Console

Blade views following the existing settings and workspace tabs.

- **Settings, Service accounts tab** (super admin, admin): list of the
  organization's accounts of both scopes; create organization account; add or
  remove workspace grants and their permissions; keys (create, shown once;
  revoke with a reason); disable or enable; transfer owner; edit name,
  description and team; "No owner" badge and filter; quota usage.
- **Workspace settings, Service accounts tab** (super admin, admin, workspace
  admin with `service_accounts`): workspace accounts of this workspace, with the
  same actions; organization accounts with a grant here are listed read-only.
- **Settings, General**: the key lifetime, quota and notice settings of section
  3.5 (super admin, admin).
- **Registered systems**: the service account column; "Assign to service
  account" action (admin, or workspace admin with `service_accounts`). The
  picker lists accounts with an active grant on the system's workspace that
  include `systems.register`. Assigning sets `service_account_id` and is
  audited. The server then needs `atglance --configure` with an `atgsa-` key.
- **Personal settings, API keys**: shows `status`, `revoked_at` and the reason.

Every action button is shown only when the policy allows it; the server checks
again.

## 9. Notifications

New entries in `NotificationEvents` (organization and workspace levels, through
`Notifier`) and in `AccountAlerts` (user level).

### 9.1 Organization level (organization groups)

| Event | When |
|---|---|
| `service_account.created`, `service_account.disabled` | Organization account |
| `service_account.key_expiring` | Organization account key, at each notice day |
| `service_account.key_expired`, `service_account.key_revoked` | Organization account key |
| `service_account.owner_missing` | Any account loses its owner |
| `user.keys_revoked` | A user's keys were revoked (section 7), with the count |

### 9.2 Workspace level (workspace groups, plus member email as today)

| Event | When |
|---|---|
| `service_account.created`, `service_account.disabled` | Workspace account |
| `service_account.grant_added`, `service_account.grant_removed` | An organization account gains or loses this workspace |
| `service_account.key_expiring`, `.key_expired`, `.key_revoked` | Key of an account with a grant here |
| `system.assigned_to_service_account` | A server moves from a person to an account |
| `system.deregistered` (existing), `system.reactivated` (new) | Now with the facts **Actor** and **Original owner** |

### 9.3 User level (personal email)

| Alert | When | Can be turned off |
|---|---|---|
| Your API key expires soon | Own `atgla-` key, at each notice day | Yes (`api_keys`) |
| Service account key expires soon | User is the owner | Yes (`api_keys`) |
| You are now owner of a service account | Owner transferred to the user | Yes (`api_keys`) |
| Your API keys were revoked | Section 7 | No (security notice) |

### 9.4 Daily sweep

New command `atglance:credentials-sweep`, scheduled daily in the existing
scheduler container:

- For each active key with `expires_at` inside a notice window larger than its
  `expiry_notified_stage`, send the notice and store the stage. Reruns do not
  resend.
- For each key that expired since the last run, send `key_expired` and log it.
- Notices show the key name, last 4 characters, expiry date and account. Never
  the key.

## 10. Audit

`ActivityRecorder` events, with actor, target, organization and workspace, and
no secrets:

`service_account.created`, `.updated`, `.disabled`, `.enabled`,
`.grant_added`, `.grant_changed`, `.grant_removed`, `.key_created`,
`.key_revoked`, `.key_expired`, `.owner_transferred`, `.owner_missing`,
`system.registered` (user and account), `system.assigned_to_service_account`,
`system.deregistered`, `system.reactivated` (actor and original owner),
`system.key_rotated` (by account or user), `user.keys_revoked`, and refused
attempts: wrong prefix, revoked or expired key, missing permission, scope
mismatch.

## 11. Integrity and concurrency

- Account, grant and key creation run in a transaction. Quota and the 2-key
  limit are checked after `lockForUpdate` on the organization row (organization
  accounts), the workspace row (workspace accounts) or the account row (keys),
  so concurrent requests cannot pass the limit.
- `scope`, `org_id` and `workspace_id` of an account are not mass-assignable
  after creation.
- Revoking a person's keys runs in the same transaction as the status, delete or
  organization change.
- Key plaintext exists only in the creation response. It is masked in logs
  (`SecretMask`) and never put in notifications or audit data.

## 12. Slices

| # | Slice | Status |
|---|---|---|
| 1 | Service accounts, CLI identity, revoke on removal, notifications (this document) | This design |
| 2 | Person keys: active-key limit, required expiry, hash-only storage (section 15). Scopes stay later. | This design (2.1.2) |
| 3 | Offboarding: ownership transfer of person-owned servers, orphan report, stop cascade delete of history | Later |
| 4 | Organization admin role | On hold |
| 5 | `atglance` CLI: `atgsa-` support, two-key registration, `whoami` check | CLI repository |

## 13. Tests

Feature tests in `composer/tests/Feature`, following `CliApiV1Test` and
`WorkspaceSettingsTest`.

- **Authentication:** valid user key; valid account key; expired; revoked;
  disabled account; prefix and `tokenable_type` mismatch; inactive user.
- **Registration:** both keys succeed; missing account key with the setting off
  (works) and on (422); account without grant or without `systems.register`
  (refused); account of another organization (refused); 1.x route with the
  setting on (422).
- **Isolation:** workspace account on a system in another workspace (404);
  changed `workspace_id` in the body (refused); organization account works only
  in granted workspaces; revoking a grant blocks the next call.
- **Permissions:** each of the 4; deregister and reactivate with an account key
  (403); workspace admin without `service_accounts` (403); workspace admin
  creating an organization account (403); workspace admin deregistering a system
  in their workspace with PIN (allowed, audited with original owner).
- **Lifecycle:** third active key refused; rotation overlap (both keys work);
  quota refused; two concurrent creates at quota minus one (one succeeds);
  sweep sends each notice once.
- **Revoke on removal:** deactivate, delete, organization change revoke all
  keys; reactivation does not restore them; servers on a service account keep
  uploading after the registering user is deactivated; owned accounts get
  `owner_missing`.
- **Secrets:** account key absent from every response after creation, from
  logs, notifications and `activity_logs`.

## 14. Documentation

- New `docs/service-accounts.md`: concepts, who can do what, CLI flow, key
  rotation, settings.
- Update `docs/api-reference.md` (headers, `whoami`, errors),
  `docs/api-keys.md` (two prefixes, revoke on removal) and
  `docs/notifications.md` (new events).

## 15. Person keys (PAT) hardening (slice 2)

Applies to every `atgla-` key, created in the console (Settings > API keys) or
by the CLI (`POST /api/auth/pat-tokens` after `atglance login`).

### 15.1 Rules

| Rule | Value |
|---|---|
| Storage | SHA-256 hash only. `token_encrypted` is never written. |
| View key later | Removed. `POST /settings/api-keys/view` answers 410 `API keys are shown only when created`. The "View" button is removed. |
| Expiry | Required. Default `pat_default_key_days` (90), at most `pat_max_key_days` (365). A date later than the maximum is refused (422). The 2099 default is gone. |
| Active keys per user | At most `pat_user_quota` (5). Revoked and expired keys do not count. |
| Same name | Creating a key with the name of one of the user's active keys revokes the old one (`revoke_reason = replaced`) in the same transaction. A CLI that logs in again from the same host (same key name) therefore never hits the limit. |
| Over the limit | 409 `You have 5 active API keys. Revoke one or reuse a key name.` |
| Revoke | Sets `status = revoked`, `revoked_at`, `revoked_by_user_id`, `revoke_reason` (`revoked_by_user` from the console). |
| `GET /api/auth/pat-tokens` | No longer returns `token_hash`. Adds `status`, `last_four`, `revoked_at`. |

Settings, in `admin_settings` with the service account settings:
`pat_default_key_days` 90, `pat_max_key_days` 365, `pat_user_quota` 5.

### 15.2 Existing keys

- The migration sets `token_encrypted = null` on every row, so stored plaintext
  is gone. Old keys keep working (their hash is unchanged).
- Existing keys keep their expiry, including the 2099 default, so no server
  stops at upgrade. The release notes tell admins to replace them. They count
  toward the 5-key limit.
- `last_four` is unknown for old keys and shows as `…????`.

### 15.3 Tests

Create in console and via API: hash only, expiry default and maximum, limit 5
(409), same-name replace, revoked and expired keys not counted; view endpoint
410; index has no `token_hash`; migration clears `token_encrypted` and old keys
still authenticate.

## 16. Expiry notice schedule and credential status API (added 2026-10-13)

### 16.1 Schedule

Default `sa_expiry_notice_days` becomes `15,7,3,2,1,0` (0 = the day the key
expires). Days left are counted in calendar days. The same schedule applies to
person keys and service account keys, at every level:

| Key | User level (email) | Workspace level | Organization level |
|---|---|---|---|
| Person key (`atgla-`) | The key's owner | `api_key.expiring`, to each workspace that has an active system registered with this key | `organization.user_key_expiring` |
| Service account key (`atgsa-`) | The account's owner | `service_account.key_expiring`, to each workspace it has a grant on | `organization.service_account_key_expiring` (organization accounts) |

Each key gets each notice once (`expiry_notified_stage` holds the last day
sent). The daily sweep and the API below share this rule, so a CLI calling
often never causes duplicates.

### 16.2 API for the CLI

Both accept user and service account keys (`auth.pat:service`). The system
headers (`X-Atglance-System-Id`, `X-Atglance-System-Key`) are optional; when
sent they must be valid (404 otherwise) and name the machine in the notice.

`GET /api/v1/credentials/status`:

```json
{
  "identity_type": "service_account",
  "key": {"id": 88, "name": "fleet-a", "last_four": "Xy12", "expires_at": "2026-10-20T00:00:00+00:00", "days_left": 7, "state": "expiring"},
  "notice_days": [15, 7, 3, 2, 1, 0],
  "system": {"id": 123456, "name": "web-01", "workspace_id": 4}
}
```

`state` is `expiring` when `days_left` is within the largest notice day, else
`active`. An expired or revoked key cannot call it: it gets the 401 of
section 5.1, which is itself the signal.

`POST /api/v1/credentials/expiry-notice` (throttle 6 per minute): sends the
notices of 16.1 for the calling key's current notice day, if not sent yet.
Answers `{"sent": true, "stage": 7}`, `{"sent": false, "reason": "already_sent", "stage": 7}`
or `{"sent": false, "reason": "not_expiring"}`.
