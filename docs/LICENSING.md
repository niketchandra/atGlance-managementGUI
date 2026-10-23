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

## File format (version 1)

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

The payload is UTF-8 JSON. Dates are UTC, `YYYY-MM-DDTHH:MM:SSZ`.

| Field | Rule |
|---|---|
| `format_version` | `1` |
| `license_id` | `LIC-<uuid>` |
| `product` | `atglance-console` |
| `key_id` | equal to the header key_id |
| `customer`, `email`, `plan` | from the atglance.live account |
| `seats` | integer >= 1: the most user accounts the console may hold, all roles together. Service accounts do not count; the two accounts the installer creates do. Creating, registering or SSO-provisioning a user past the limit is refused |
| `features` | array of strings |
| `issued_at` | generation time |
| `activate_before` | exactly `issued_at` + 3 hours |
| `expires_at` | later than `issued_at` |
| `min_version` | lowest console version, `MAJOR.MINOR.PATCH` |
| `install_id` | console Installation ID the file is bound to, or `null` |

Verification order (the first failure wins): magic and version, key ID, decryption, signature,
payload fields, product, `issued_at` not more than 10 minutes in the future, `activate_before`
(activation only), `expires_at`, `min_version`, `install_id`.

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

1. Sign in on atglance.live, open **Licences**, and click **Generate licence file**. To bind the
   file to one console, first enter that console's **Installation ID**. It is shown when
   `ATGLANCE_LICENSE_SHOW_INSTALL_ID=true` (off by default).
2. Within **3 hours**, upload the `.atgl` file in the console installer (step 1). The installer shows
   customer, plan, seats, issue date and expiry, and refuses to install without a valid file.
3. Afterwards, the file is re-verified on every request and by `php artisan license:verify` (at
   container start and hourly). The 3-hour rule no longer applies; `expires_at` does.
4. Banners appear 30, 7 and 1 days before expiry. After expiry the console is **read-only**: every
   write is refused, except sign-in, sign-out and the licence upload in **Admin Settings > Licence**.
5. To renew, generate a new file and upload it in **Admin Settings > Licence** (your password is
   required). Renewal files are also subject to the 3-hour window. The current licence stays in
   place if the new file is refused.

Stored files (on the `app-storage` volume, `storage/app/license/`):

| File | Content |
|---|---|
| `license.atgl` | the uploaded file, byte for byte |
| `activation.json` | `license_id`, `activated_at`, `file_sha256`, HMAC |
| `clock.json` | latest time seen, HMAC |

### Upgrading from licence keys (2.2 and earlier)

Consoles up to 2.2 used online licence keys (`atg_...`). From this version on, a stored key is
ignored. After the update, the console is read-only until an admin uploads a licence file in
**Admin Settings > Licence**. Generate the file just before uploading it, because of the 3-hour
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

`php artisan license:verify` prints the current state and code. API clients get
`403 {"code": "E_...", "message": "..."}` for writes while the console is read-only.

## Tests

- Shared vectors: `composer/tests/Fixtures/licensing/` is a byte-identical copy of the workspace
  `contracts/fixtures/` (test keys only). `tests/Unit/Licensing/VerifierContractTest.php` runs every
  case in `cases.json`. With `ATGLANCE_CONTRACTS_DIR` set to the workspace `contracts/` folder it also
  fails when the copy is out of date.
- `tests/Feature/LicenseTest.php` (settings, lock, banners, clock, record tampering) and
  `tests/Feature/LicenseInstallerTest.php` (installer step).
- `tests/Support/TestLicense.php` issues files with the test key. The base `TestCase` installs one
  per test in a temporary folder; set `protected bool $withLicense = false;` to start without one.
- Cross-repo end-to-end: `php contracts/tools/e2e.php <site-repo> <console-composer-dir>` in the
  workspace runs the site issuer code and this verifier, then checks the 3-hour rule at +3h01m.

Run the console tests in Docker:

```
cd composer
docker run --rm --entrypoint "" -e ATGLANCE_CONTRACTS_DIR=/contracts \
  -v "<workspace>/contracts:/contracts:ro" -v "$PWD:/app" -w /app \
  ce-atglance-app:dev php vendor/bin/phpunit
```
