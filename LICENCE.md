# Licence

An AtGlance instance needs a licence from [atglance.live](https://atglance.live).
Without an active licence:

- nobody can register (web form, SSO first sign-in, `POST /api/auth/register`);
- admins cannot create users (`/admin/users`, `POST /api/users`);
- nobody can create API keys (Settings > API keys, `POST /api/auth/pat-tokens`).

Existing users can still sign in, and existing API keys keep working.

## Getting a licence key

1. Log in to atglance.live.
2. Generate a licence.
3. Copy the licence key and paste it into the installer or into Admin Settings > Licence.

## Where the key is entered

**Installer (`/install`, Step 1).** Paste the key and click **Verify**, or tick
**I'll add later**. The key is verified again on submit; a failed check stops
the installation.

**Admin Settings > Licence** (`POST /admin/settings/licence`, admins `100`/`101`).
Used when the installer step was skipped or to replace the key.

Both places make the same call:

```
POST https://atglance.live/api/licenses/verify
Accept: application/json
Content-Type: application/json
Authorization: Bearer <licence key>

{"instance_id":"<console UUID>","hostname":"<gethostname()>","version":"<config app.version>"}
```

The call checks the key and, if it is valid, marks the licence In Use for this
console. The first console to verify a licence owns it. atglance.live identifies
the console by `instance_id`. The console generates that UUID once and keeps it
in `storage/app/installer/instance_id` (`License::instanceId()`). The ID is kept
in storage, not in the DB, because the installer verifies before migrations run.
The container hostname also changes when the container is recreated. Do not
delete this file: a new ID makes atglance.live see a different console and
return `409 in_use_elsewhere`. Portal backup/restore does not include it.

| HTTP | `status` | Result in the console |
|---|---|---|
| 201 | `in_use` | Verified; licence saved |
| 200 | `in_use` | Same console verified again (restart, reinstall); licence saved |
| 409 | `in_use_elsewhere` | Rejected: licence used by another console |
| 403 | `unverified` | Rejected: user must enter the emailed code on atglance.live |
| 403 | `under_review` | Rejected: waiting for admin approval on atglance.live |
| 401 | - | Rejected: wrong or revoked key |

On success the console saves `license.name`, `plan` and the other response
fields (`user.*`, `console.*`) flattened to dot keys.

## Storage

`admin_settings`, group `license`:

| Key | Content |
|---|---|
| `license_key` | licence key, encrypted |
| `license_status` | `in_use` when verified |
| `license_name`, `license_plan`, `license_expires_at` | from the verify response |
| `license_details` | JSON of the other scalar response fields |
| `license_verified_at` | time of the last successful verification |

Code: `App\Services\LicenseClient` (API call), `App\Support\License`
(storage and `License::isActive()`), `App\Http\Controllers\LicenseController`.

## Configuration

| Env | Default |
|---|---|
| `ATGLANCE_LICENSE_VERIFY_URL` | `https://atglance.live/api/licenses/verify` |
| `ATGLANCE_LICENSE_PORTAL_URL` | `https://atglance.live` |
