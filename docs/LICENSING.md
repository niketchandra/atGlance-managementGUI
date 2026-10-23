# Licensing (offline licence files)

The Management Console is licensed with a file, `atglance-LIC-<uuid>.atgl`, that a signed-in user
generates on atglance.live. The console checks the file on its own server with built-in public
keys. It never contacts atglance.live to validate a licence, so it works without internet access.

The source of truth for the file format is `contracts/LICENSE_FORMAT.md` in the AtGlance workspace
(next to the site and console repos). This page repeats it for console maintainers and operators.

## Architecture

```
atglance.live (issuer)                                Management Console (verifier, offline)
──────────────────────                                ─────────────────────────────────────
Licences page / POST /api/license/generate            Installer step 1 / Admin Settings > Licence
  payload from the account and plan                     upload .atgl
  sign:    Ed25519 private key  (env only)               decrypt: AES key       (keyring, built in)
  encrypt: AES-256-GCM key      (env only)               verify:  Ed25519 public key (keyring, built in)
  row in `licenses`                                       check dates, product, version, install ID
  download .atgl  ─────────── user carries the file ──▶  store file + HMAC-signed activation record
                                                         re-verify on every request (no 3-hour rule)
```

- **Unforgeable:** only atglance.live holds the Ed25519 private key. The console holds only public
  keys, so it can check licences but never create them.
- **Opaque:** the signed payload is encrypted with AES-256-GCM. The AES key ships inside every
  console image, so this hides the content from casual reading but is not secrecy. The signature
  is what protects the licence.
- **Limits of offline licensing:** whoever controls the server can modify the console code or the
  stored files. The HMAC on the activation record (keyed from `APP_KEY`) and the clock check stop
  accidental and casual tampering, not a determined operator with root access.

Console code:

| File | Purpose |
|---|---|
| `composer/app/Support/Licensing/Verifier.php` | The format checks, no framework code |
| `composer/app/Support/Licensing/LicenseException.php` | Error codes and messages |
| `composer/app/Support/Licensing/LicenseStore.php` | Stored file, activation record, clock record |
| `composer/app/Support/License.php` | Status, activation, banners, summary for the views |
| `composer/app/Http/Middleware/EnforceLicense.php` | Read-only mode without an active licence |
| `composer/config/licensing.php` | Keyring, storage path, banner days, Installation ID flag |
| `composer/config/license-keyring.json` | Keyring embedded at build time |

## File format

All integers are big-endian.

```
offset  size  field
0       4     magic "ATGL"
4       1     format version = 0x01
5       1     key_id length L (1..64)
6       L     key_id (ASCII, [A-Za-z0-9._-])
6+L     12    AES-GCM IV (random per file)
18+L    16    AES-GCM authentication tag
34+L    N     ciphertext
```

AES-256-GCM uses the key ID's AES key, and the header (bytes `0 .. 6+L-1`) as additional
authenticated data. The plaintext is:

```
[2 bytes signature length = 64][64 bytes Ed25519 signature of the payload][payload bytes]
```

The payload is UTF-8 JSON. Dates are UTC, `YYYY-MM-DDTHH:MM:SSZ`. The payload has its own
`format_version`: 1 or 2. Both use the file layout above (layout byte `0x01`); atglance.live
issues version 2 since 2.3.0.

| Field | Payload | Rule |
|---|---|---|
| `format_version` | both | `1` or `2` |
| `license_id` | both | `LIC-<uuid>` |
| `product` | both | `atglance-console` |
| `key_id` | both | equal to the header key_id |
| `customer`, `email`, `plan` | both | from the atglance.live account |
| `features` | both | v2: enabled feature keys (see "Features"); v1: ignored |
| `issued_at` | both | generation time |
| `activate_before` | both | v1: exactly `issued_at` + 3 hours; v2: `issued_at` + the policy's window, 5 to 180 minutes |
| `expires_at` | both | later than `issued_at` |
| `min_version` | both | lowest console version, `MAJOR.MINOR.PATCH` |
| `install_id` | both | console Installation ID the file is bound to, or `null` |
| `seats` | v1 | integer >= 1, read as `max_users` |
| `max_nodes` | v2 | integer >= 0, `0` = unlimited |
| `max_users` | v2 | integer >= 0, `0` = unlimited |
| `policy_id` | v2 | the policy that produced the file: `global`, `plan:<code>` or `custom:<id>` |
| `policy_type` | v2 | `default` or `custom` |
| `validity_months` | v2 | 1 to 120, informational; `expires_at` is authoritative |

**Version 1 licences** keep working. They are read as: `max_users` = `seats`, `max_nodes` = 0
(unlimited), every feature in the catalog (consoles before feature gating had everything),
policy `v1` / `default`.

Verification order (the first failure wins): magic and version, key ID, decryption, signature,
payload fields (a payload version above 2 gives `E_VERSION`; a malformed v2 field gives
`E_PAYLOAD`), product, `issued_at` not more than 10 minutes in the future, `activate_before`
(activation only), `expires_at`, `min_version`, `install_id`.

## Limits

The console enforces the limits of the active licence on the server (web and API), never only in
the UI. The values come only from the verified file; there is no database or config value that
can change them.

| Limit | What counts | Refused | Code |
|---|---|---|---|
| `max_nodes` | registered systems with status `active`. Deregistered (inactive) systems do not count | registering a node (`POST /api/v1/systems`, legacy `/api/system-register`), reactivating one (`/api/v1/systems/self/reactivate`, `/api/system-reactive`, and the force variants). Replacing a node (`replaces_system_id`) does not add one | `NODE_LIMIT_REACHED`: "Node limit reached (X/Y). Upload a license with a higher node limit." |
| `max_users` | every user account, all roles, including the two accounts the installer creates. Service accounts do not count | creating a user (Users page, `POST /api/users`), registering (`/register`, `/api/auth/register`), first SSO sign-in, queued user creation | `USER_LIMIT_REACHED`: "User limit reached (X/Y). Upload a license with a higher user limit." |

`0` means unlimited. Admin Settings > Licence shows nodes and users as used / limit, in warning
colour from 80 % and at 100 %.

**Lower limits after a renewal.** If a new licence allows fewer nodes or users than exist, nothing
is deleted or disabled. The console shows an "over the licence limit" banner and refuses new nodes
or users until usage is within the limit (deregister nodes or delete users) or a licence with
higher limits is uploaded.

## Features

`features` enables modules from the catalog (workspace `contracts/FEATURES.md`, shipped as
`composer/resources/licensing/features.json`). Keys the console does not know are ignored.
`App\Support\Licensing\Entitlements::isFeatureEnabled($key)` is the only check; without an active
licence nothing is enabled.

| Key | Gated in the console |
|---|---|
| `sso` | `/auth/sso/*`, Admin Settings SSO tab and plugin toggle, SSO PIN reset; SSO buttons on the sign-in page are hidden |
| `ai_connect` | Admin Settings AI routes, AI review of config versions, workspace AI settings, sweeps and progress; schedule `ai:validate-workspace` and the queued `ValidateConfigWithAi` job |
| `mcp_server` | `/connect-ai`, Admin Settings MCP, the read-only MCP API (`/api/mcp/*`); `mcp:manage` stops the MCP container |
| `custom_domain` | Admin Settings domain routes and plugin toggle, `/api/internal/domain/*` (certificates for the built-in proxy) |
| `s3_backup` | Admin Settings S3 routes and plugin toggle |
| `backup_restore` | Backup & Restore settings, backup list, run, restore, workspace backups; schedules `backup:config`, `backup:database`, `backup:workspace` |
| `notifications` | Notifications pages and groups, notification settings and preferences; `Notifier` sends nothing |
| `service_accounts` | Service Accounts pages; service account keys (`atgsa-...`) are refused by the API |
| `workspaces` | Enterprise Console, creating workspaces and organisations. Existing workspaces stay readable |

A refused request returns 403 `{"code": "FEATURE_NOT_LICENSED", "message": "'Notifications' is not
included in your license."}`; a page shows an "upgrade" page instead. Menu entries and plugin cards
show a lock and "Not included in your license". When a renewal removes a feature, its data and
settings are kept and come back when a licence includes it again. Core functions are never gated:
node registration and the CLI API, configuration backups of nodes, users, the activity log and
Admin Settings.

## Keys

Each key ID names one Ed25519 key pair and one AES-256 key.

### Generate

On a trusted machine with the site repo:

```
php artisan license:generate-keys atgl-2026-10
```

It prints, and writes nothing to disk:

- for atglance.live: `LICENSE_KEY_ID`, `LICENSE_SIGNING_PRIVATE_KEY` (base64 of the PKCS#8 DER;
  the PEM is printed too) and `LICENSE_ENC_KEY` (64 hex characters);
- for the console: a keyring entry `{"atgl-2026-10": {"public_key": "...", "enc_key": "..."}}`.

### Deploy

1. **Console first.** Add the entry to the keyring and build the image with it:

   ```
   docker build --build-arg ATGLANCE_LICENSE_KEYRING="$(cat license-keyring.json)" -t atglance/ce-atglance-app:<version> .
   ```

   `license-keyring.json` has the form `{"keys": {"atgl-2026-10": {...}, "atgl-2025-10": {...}}}`. Keep
   it outside the repo, in the release pipeline's secret store. The committed
   `composer/config/license-keyring.json` stays empty. Without the build arg, no licence verifies.
   The keyring is never read from the runtime environment: anyone who controls a console's
   environment could otherwise add their own key and sign their own licences.
2. **Then atglance.live.** Put the three `LICENSE_*` values in the site's secret manager or server
   `.env`, then run `php artisan config:cache`. New files are signed with the new key ID.

### Rotate

1. Generate a new key set with a new key ID.
2. Release a console version whose keyring has **both** the old and the new key IDs. Raise
   `LICENSE_MIN_CONSOLE_VERSION` on atglance.live to that version.
3. Switch atglance.live to the new key ID.
4. Remove the old key ID from the keyring once every file signed with it has expired (one year
   after the switch). A console without the key reports `E_KEY_ID` for such files.

If a private key leaks, skip the waiting period: release a console without the leaked key ID and
ask customers to generate new files.

## Customer install flow

1. Sign in on atglance.live, open **Licences**, and click **Generate licence file**. Depending on
   the licence policy, enter the console's **Installation ID** (shown in the installer and on the
   Licence tab; required or optional) and the 5-digit code emailed to the account.
2. Upload the `.atgl` file in the console installer (step 1) within the window shown (5 to 180
   minutes). The installer previews customer, plan, policy, limits, features and dates, and
   refuses to install without a valid file.
3. Afterwards, the file is re-verified on every request and by `php artisan license:verify` (at
   container start and hourly). The 3-hour rule no longer applies; `expires_at` does.
4. Banners appear 30, 7 and 1 days before expiry. After expiry the console is **read-only**: every
   write is refused, except sign-in, sign-out and the licence upload in **Admin Settings > Licence**.
5. To renew, generate a new file and upload it in **Admin Settings > Licence** (your password is
   required). Renewal files are also subject to their activation window. The current licence stays
   in place if the new file is refused.

Stored files (on the `app-storage` volume, `storage/app/license/`):

| File | Content |
|---|---|
| `license.atgl` | the uploaded file, byte for byte |
| `activation.json` | `license_id`, `activated_at`, `file_sha256`, HMAC |
| `clock.json` | latest time seen, HMAC |

### Upgrading from licence keys (2.2 and earlier)

Consoles up to 2.2 used online licence keys (`atg_...`). Licence keys are no longer supported:
after the update the console is read-only until an admin uploads a licence file in
**Admin Settings > Licence**. Generate the file just before uploading it, because of its activation
window.

## Troubleshooting

| Code | Meaning | What to do |
|---|---|---|
| `E_FORMAT` | The file is truncated or its layout is wrong | Download the file again; upload it without opening or converting it |
| `E_MAGIC` | Not an `.atgl` file | Upload the `.atgl` file from atglance.live, not another file |
| `E_VERSION` | File format newer than this console | Update the console |
| `E_KEY_ID` | The file was signed with a key this console does not know | Update the console. Older consoles do not know newer keys, and a key may have been retired |
| `E_DECRYPT` | The file was changed or damaged (authentication tag mismatch) | Download it again. Transfer it as binary (no FTP ASCII mode, no line-ending conversion) |
| `E_SIGNATURE` | The signature does not match | The file was not issued by atglance.live; generate a new one |
| `E_PAYLOAD` | Content malformed (missing field, wrong date, wrong key_id) | Generate a new file; report it if it repeats |
| `E_PRODUCT` | The licence is for another product | Generate a Management Console licence |
| `E_ISSUED_FUTURE` | The file is dated more than 10 minutes ahead of this server's clock | Set the server's date, time and time zone correctly (NTP) |
| `E_ACTIVATION_WINDOW` | More than 3 hours passed since the file was generated | Generate a new file and upload it right away |
| `E_EXPIRED` | The licence has expired | Renew on atglance.live and upload the new file |
| `E_MIN_VERSION` | The licence needs a newer console | Update the console |
| `E_INSTALL_ID` | The file is bound to another installation | Generate a file with this console's Installation ID, or without one |
| `E_CLOCK` | This server's clock is more than 24 hours behind the latest time the console recorded | Correct the clock. Activation stays refused until the clock has caught up |
| `E_RECORD` | `activation.json` was edited, is missing, or does not match `license.atgl` | Upload the licence file again in Admin Settings > Licence |
| `E_NO_LICENSE` | (API responses) no licence file is installed | Upload one in Admin Settings > Licence |
| `NODE_LIMIT_REACHED` | The active nodes reached `max_nodes` | Deregister nodes you no longer use, or upload a licence with a higher node limit |
| `USER_LIMIT_REACHED` | The users reached `max_users` | Delete users you no longer need, or upload a licence with a higher user limit |
| `FEATURE_NOT_LICENSED` | The licence does not include the feature | Ask your atglance.live admin for a licence that includes it, then upload the new file |

**Offline revocation.** A licence file is checked offline, so atglance.live cannot revoke a file
that was already downloaded: it works until its `expires_at`. Revoking a custom license on
atglance.live only changes the files generated afterwards. Keep `expires_at` short for licences
you may need to take back.

`php artisan license:verify` prints the current state and code. API clients get
`403 {"code": "E_...", "message": "..."}` for writes while the console is read-only.

## Tests

- Shared vectors: `composer/tests/Fixtures/licensing/` is a byte-identical copy of the workspace
  `contracts/fixtures/` (test keys only). `tests/Unit/Licensing/VerifierContractTest.php` runs every
  case in `cases.json`. With `ATGLANCE_CONTRACTS_DIR` set to the workspace `contracts/` folder it also
  fails when the copy is out of date.
  `decoded` values (limits, policy, features) of v1 and v2 samples are compared too.
- `tests/Feature/LicenseTest.php` (settings, lock, banners, clock, record tampering, user limit),
  `tests/Feature/LicenseInstallerTest.php` (installer step) and `tests/Feature/LicenseLimitsTest.php`
  (node limit through the API, user limit, unlimited, over limit after a downgrade, 80 % / 100 %,
  feature gating, service account keys, `limit-cases.json`).
- `tests/Support/TestLicense.php` issues files with the test key (payload v2, unlimited, every
  feature, unless the test says otherwise). The base `TestCase` installs one per test in a
  temporary folder; set `protected bool $withLicense = false;` to start without one.
- Cross-repo end-to-end: `php contracts/tools/e2e.php <site-repo> <console-composer-dir>` in the
  workspace runs the site issuer code and this verifier: the 3-hour rule at +3h01m, and a custom
  policy whose node N+1 is refused.

Run the console tests in Docker:

```
cd composer
docker run --rm --entrypoint "" -e ATGLANCE_CONTRACTS_DIR=/contracts \
  -v "<workspace>/contracts:/contracts:ro" -v "$PWD:/app" -w /app \
  ce-atglance-app:dev php vendor/bin/phpunit
```
