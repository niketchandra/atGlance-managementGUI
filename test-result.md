# Test results: licence workflow (site + console)

Date: 2026-10-24
Branch: `feature/license-policy` in both repos

| Repo | Commit tested | Working tree |
|---|---|---|
| Console (`atGlance-managementGUI`) | `0ff9793` plus the fixes for the 45 failures | modified; the fixes are not committed yet |
| Site (`aglance-site`) | `0a4fb9c` update Licence worflow code base | clean |

## Summary

| Suite | Tests | Assertions | Passed | Failed | Skipped | Time |
|---|---|---|---|---|---|---|
| Site (PHPUnit) | 287 | 1787 | **287** | 0 | 0 | 2 min 51 s |
| Console (PHPUnit) | 653 | 2978 | **652** | **0** | 1 | 3 min 20 s |
| Contract end-to-end (site issues a file, console verifies it) | 9 | - | **9** | 0 | - | - |
| Live smoke checks (local site and console preview) | 9 | - | **9** | 0 | - | - |

- **Both suites pass.** The first console run had 45 failures; they are fixed (see "Console fixes" below).
- **One skipped test:** `VerifierContractTest::test_fixture_copy_matches_the_workspace_contract`. It needs the workspace `contracts/` folder, which is not mounted in the test container. The site suite runs the same check, and it passes there.

## How the tests were run

| Suite | Command / environment |
|---|---|
| Site | `ATGLANCE_CONTRACTS_DIR=<workspace>/contracts php vendor/bin/phpunit` in `C:\xampp\htdocs\aglance-site`, PHP 8.2.12, SQLite in memory |
| Console | `php -d memory_limit=-1 vendor/bin/phpunit` in a container from image `ce-atglance-app:offline-licence` (PHP 8.2.35), on a copy of `composer/` with a writable empty `.env`, SQLite in memory |
| Contract e2e | `php contracts/tools/e2e.php <site-repo> <console-composer-dir>` in the workspace |
| Smoke checks | `curl` against the local site (`http://localhost:8080`) and the console preview (`http://localhost:8090`, containers `atglp-*`) |

## Site: 287 / 287 passed

All 32 test classes pass. The licence areas they cover:

| Area | Covered by |
|---|---|
| Licence keys: create, confirm with the emailed code, approval, key viewable for 30 minutes | `LicenseTest`, `LicenseApprovalTest`, `LicenseKeyVisibilityTest` |
| Online activation API (compatible with 2.2), one licence = one console | `ConsoleLicenseTest` |
| A licence is used one way only: after a file is generated, the key is refused (`409 in_use_file`) and hidden; after the key is activated, no file can be generated | `ConsoleLicenseTest` |
| Licence files: signing and encryption against the shared test vectors, auth, account-only values | `LicenseFileTest`, `LicenseFileContractTest` |
| Installation ID required or optional per policy | `LicenseFileTest` |
| A used licence cannot be revoked until it expires | `LicenseFileTest`, `LicenseRevokeTest` |
| Licence policy: global / plan / custom, licence limit, Premium features, validation, audit log | `LicensePolicyTest` |
| Feature catalog synced from the contract (only `premium`) | `LicensePolicyTest` |
| "Need more licences" link, and the contact topic with the subject "Need help to get more Licences" | `LicensePolicyTest`, `ContactFormTest` |

## Console: 652 / 653 passed, 0 failed, 1 skipped

All licence, installer, plugin, profile, SSO, service account, CLI API and system key tests pass.

### Console fixes (the 45 earlier failures)

| Earlier failures | Cause | Fix |
|---|---|---|
| About 30 tests: `ServiceAccountSystemRoutesTest` (15), `ConfigVersionHashTest` (4), `ServiceAccountOwnedDataTest` (2), `ServiceAccountReviewFixesTest` (2), `CredentialExpiryTest` (2), `UserKeyRevocationTest` (1), part of `CliApiV1Test` | The shared helper `MakesServiceAccounts::systemHeaders` built the key header from `system_register.validation_hash`. That column is now empty because keys are kept only as hashes, so the requests got 400 | The helper sends the plain key each test created the system with |
| New uploads lost their key link (code bug) | `V1/ConfigFileController` copied the empty column into each upload, so the legacy `config-files/filter?validation_hash=` lookup could not find them | The upload stores the key the host sent in `X-Atglance-System-Key`, which the middleware has already checked against the hash |
| Key rotation left files on the old key (code bug) | `rotateSystemKey` read the old key from the empty column | The old key comes from the caller's header and is checked with `matchesKey()`. Then the `configuration_files` and `raw_data` copies move to the new key |
| Old rows still held plaintext keys (code bug) | Rows saved before the change kept `validation_hash` until they were saved again | New migration `2026_10_24_000001_clear_plaintext_system_keys` hashes rows that have no hash and clears the plaintext of every row |
| `SystemKeyHashTest` (1), `SystemKeyHashV1Test` (2), `SystemKeyHashLegacyTest` (4), part of `CliApiV1Test` | The tests still expected the plaintext key to be stored, or echoed by the legacy register response | The tests check that `validation_hash` is null and the hash matches. The legacy response no longer echoes the key (deliberate change, `29d4547`) |
| `CliApiV1Test::test_successful_attempt_clears_failure_window` | Code bug: `ForceLockout::recordSuccess` did not restart the failure window, so failures before a success still counted | Only failures after the latest success are counted |
| `CliApiV1Test::test_force_limit_is_per_ip_and_email_and_covers_legacy_routes` | The test expected 401 where the lockout (after 5 failures) now answers 423 | Expects 401 for attempts 1 to 5, then 423, then 429 from the rate limit |
| `CliApiV1Test::test_upload_list_filter_and_download` | Responses now include `sha256` | The expected keys include `sha256` |
| `ConsolidatedMigrationTest::test_fresh_install_matches_legacy_migrations` | It compared the legacy schema with all migrations, including later ones (lockout, key hash, content hash) | It compares with the migrations up to the 2.1.2 consolidation. `temporary_credential_messages` is listed as a table new in 2.1.2 |
| `SsoPluginTest` (2) | The tests interfered with each other: saving SSO settings also calls `putenv()`, so `SSO_ENABLED_PROVIDERS=microsoft,gitlab` from `SsoLoginTest` leaked into later tests | `InteractsWithAdminConsole` restores `$_ENV`, `$_SERVER` and the process environment after each test |
| `ExampleTest` | The result depended on whether another test left the installer marker behind | Expects 200 when the console is installed, and a redirect to the installer otherwise |

New tests:
- `SystemKeyHashV1Test::test_v1_upload_keeps_the_sent_key_and_rotation_moves_the_files_to_the_new_key`
- `SystemKeyHashV1Test::test_migration_clears_plaintext_keys_and_hashes_rows_without_a_hash`

## Contract end-to-end: 9 / 9 passed

```
PASS  default: activation now          expected ok                   got ok
PASS  default: activation at +3h01m    expected E_ACTIVATION_WINDOW  got E_ACTIVATION_WINDOW
PASS  default: startup at +3h01m       expected ok                   got ok
PASS  custom: activation now           expected ok                   got ok
PASS  custom: limits read back         expected 2/5/custom           got 2/5/custom
PASS  custom: unknown feature ignored  expected premium              got premium
PASS  custom: node 2 of 2 allowed      expected ok                   got ok
PASS  custom: node 3 of 2 refused      expected NODE_LIMIT_REACHED   got NODE_LIMIT_REACHED
PASS  custom: activation at +61 min    expected E_ACTIVATION_WINDOW  got E_ACTIVATION_WINDOW
```

## Live smoke checks: 9 / 9 as expected

| Check | Result |
|---|---|
| Site `/` | 200 |
| Site `/contact?topic=more_licences` | 200 |
| Site `/dashboard/licences` as guest | 302 to `/login` |
| Site `POST /api/licenses/activate` without a key | 401 |
| Console preview `/install` (already installed) | 302 to `/` |
| Console preview `/profile` as guest | 302 to `/login` |
| Console preview `/` | 200 (sign-in page) |
| Console container to site API (`host.docker.internal:8080`) | 401 (reachable; no key sent) |
| Preview containers `atglp-app`, `-scheduler`, `-db`, `-redis` | up, healthy |

## Not covered by automated tests

- These manual browser checks on the preview are still to be done:
  - installer without a licence;
  - password mismatch message;
  - profile first, then licence;
  - online key activation against the local site;
  - licence file upload.
- The console preview image was not rebuilt with the fixes for the 45 failures.
