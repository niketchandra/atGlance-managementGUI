# Service accounts

A service account is a machine identity for the `atglance` CLI. A server that runs as a service account keeps uploading backups when the person who registered it leaves, changes team or loses their key.

Since 2.1.2 there are two kinds of API key:

| Key | Prefix | Belongs to | Used for |
|---|---|---|---|
| Personal access token (PAT) | `atgla-` | A person | `atglance login`, registering a server (together with a service account key), deregister and reactivate |
| Service account key | `atgsa-` | A service account | Everything a registered server does: upload, read, status, rotate its system key |

A service account has no password, PIN, console login or role. It is not a user and never appears in user lists.

## Scopes

| Scope | Can act in | Set up |
|---|---|---|
| Workspace | Exactly one workspace, fixed when the account is created | From the workspace's **Service Accounts** tab, or the **Service Accounts** page |
| Organization | Only the workspaces an admin gives it access to, each with its own permissions | From the **Service Accounts** page |

Belonging to the organization does not give an organization account access to any workspace. Access is per workspace and can be removed at any time; the next request after removal is refused.

## Permissions

Each workspace grant holds some of these permissions:

| Permission | Allows | Default |
|---|---|---|
| `systems.register` | Be the identity of a new server in the workspace, and be assigned existing servers | on |
| `configs.write` | Upload configuration files | on |
| `configs.read` | List and download configuration files | on |
| `systems.rotate_key` | Rotate its own server's system key (no password or PIN needed) | off |

Deregister and reactivate are never done with a service account key. They need a person's `atgla-` key plus password or PIN.

## Who can manage service accounts

All checks run on the server.

| Role | Organization accounts | Workspace accounts |
|---|---|---|
| Super admin, admin | Create and manage, in their own organization | Any workspace of their organization |
| Workspace admin | No | Their workspace, when they have the **Manage service accounts of this workspace** permission (Members tab) |
| Member | No | No |

Service accounts and keys of another organization are never shown and answer `404`.

## Keys

- A key is shown once, when it is created. Only its SHA-256 hash and last 4 characters are stored.
- Every key expires: 90 days by default, at most 365 days (both can be changed in Settings).
- An account has at most 2 active keys, which allows rotation without downtime:
  1. Create key B.
  2. Run `sudo atglance --configure` on each server with key B.
  3. Revoke key A, with a reason.
- A revoked key never works again. Disabling an account stops all of its keys at once; enabling it again restores keys that are neither revoked nor expired.

## Registering a server

The person logs in with `atglance login` (their `atgla-` key). Registration sends both keys:

```
POST /api/v1/systems
Authorization: Bearer atgla-...
X-Atglance-Service-Account-Key: atgsa-...
```

The server checks that the person may register in the workspace, that the service account key is valid and of the same organization, and that the account has `systems.register` in that workspace. The new server records the service account and, as "registered by", the person.

Without the service account header, registration works as before (the server belongs to the person), unless an admin turns on **Require a service account key to register servers**. Then both `POST /api/v1/systems` and the 1.x `POST /api/system-register` answer `422 Registration needs a user key and a service account key`. Turn it on once every server's CLI supports service accounts.

## Moving existing servers

On **Systems**, an admin (or a workspace admin with the service accounts permission) picks a service account in the **Service account** column and clicks **Assign**. Only active accounts with `systems.register` in the server's workspace are offered. Then run `sudo atglance --configure` on the server with a key of that account. The server's existing backup history continues in the same services.

## When a person leaves

When a user is set inactive, deleted or moved to another organization:
- all their `atgla-` keys are revoked at once, with the reason `user_deactivated`, `user_deleted` or `user_org_changed`;
- reactivating the user does not bring the keys back;
- service accounts they owned keep running, show **No owner**, and the organization is notified so an admin can pick a new owner;
- servers they registered that run as a service account keep uploading.

Removing a user from one workspace does not revoke their keys: workspace access is checked on every request.

## Expiry notices

Keys get notices 15, 7, 3, 2 and 1 days before they expire, and on the expiry day, once each:

| Key | User | Workspace | Organization |
|---|---|---|---|
| Person key | The owner, by email | Each workspace with an active server registered with the key | `organization.user_key_expiring` |
| Service account key | The account owner, by email | Each workspace the account has access to | Organization accounts only |

The scheduler runs `php artisan atglance:credentials-sweep` daily at 06:10. The CLI can also check a key and trigger its notice at once; see `GET /api/v1/credentials/status` and `POST /api/v1/credentials/expiry-notice` in [api-reference.md](api-reference.md#cli-api-v1-apiv1). Both share the same once-per-day rule, so a CLI calling often never sends duplicates.

## Settings

On **Service Accounts > Settings** (admins). Stored in `admin_settings`.

| Setting | Default | Meaning |
|---|---|---|
| `sa_default_key_days` | 90 | Pre-filled service account key lifetime |
| `sa_max_key_days` | 365 | Longest service account key lifetime |
| `sa_org_quota` | 10 | Active organization accounts per organization |
| `sa_workspace_quota` | 5 | Active workspace accounts per workspace |
| `sa_expiry_notice_days` | `15,7,3,2,1,0` | Days before expiry when notices go out (0 = expiry day) |
| `sa_require_for_registration` | off | Registration needs a service account key |
| `pat_default_key_days` | 90 | Default personal key lifetime |
| `pat_max_key_days` | 365 | Longest personal key lifetime |
| `pat_user_quota` | 5 | Active personal keys per user |

Disabled accounts and revoked or expired keys do not count toward the limits.

## Code

- Models: `App\Models\ServiceAccount`, `App\Models\ServiceAccountGrant` (table `service_account_workspaces`); keys are `App\Models\PatToken`.
- Rules: `App\Support\ServiceAccountAccess` (who may manage), `App\Services\ServiceAccounts` (create, grants, keys, owner, assign), `App\Services\UserKeys` (personal keys), `App\Services\UserKeyRevoker` (revoke on removal).
- Authentication: `App\Http\Middleware\AuthenticatePatToken` (`auth.pat` and `auth.pat:service`), `AuthenticateSystemKey`, `RequireServiceAccountPermission` (`sa.permission:<permission>`).
- Notices: `App\Support\ServiceAccountNotices`, `App\Services\CredentialSweep`.
- Design: [superpowers/specs/2026-10-13-service-accounts-design.md](superpowers/specs/2026-10-13-service-accounts-design.md).
