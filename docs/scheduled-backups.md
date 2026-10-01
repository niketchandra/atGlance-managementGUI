# Scheduled Backups

The **Backup & Restore** tab in `/admin/settings` has two separate sections:

| Section | What it backs up | Command | Who can restore |
|---|---|---|---|
| **Database backup & restore** | A gzipped SQL dump of every table (made in PHP, so `mysqldump` is not needed). | `php artisan backup:database` | Super admin only (`rbac_id` 100) |
| **Configuration & console files backup & restore** | A zip with `config.json` (the `services`, `system_register`, `configuration_files`, `raw_data` and `config_ai_validations` tables; `raw_data.file_data` holds each file's content) and `files/` (the console's own files, see below). | `php artisan backup:config` | Admins (`rbac_id` 100 and 101) |

`php artisan backup:portal` still exists and runs the database backup, so older
host crontabs keep working.

The logic is in `composer/app/Services/BackupService.php`, the settings in
`composer/app/Support/BackupSettings.php`, and the commands in
`composer/routes/console.php`.

## Console files in the configuration backup

`BackupService::CONSOLE_FILE_ROOTS` lists them:

| Archive folder | Directory | Contents |
|---|---|---|
| `files/public/` | `storage/app/public` | Branding images and other public uploads |
| `files/caddy/pki/` | `storage/caddy/pki` | The built-in proxy's local CA (including its private key) |
| `files/caddy/certificates/` | `storage/caddy/certificates` | Certificates the built-in proxy issued |

Keep the S3 bucket private: the archive contains the CA private key.

## Settings for each section

| Setting | Values |
|---|---|
| Scheduled backup | On / Off |
| How often | Every hour, 6 hours, 12 hours, day, week, month, or a **custom cron expression** (5 fields: minute hour day month weekday, for example `30 2 * * *`) |
| Keep local copies on this server | Yes / No, and how many copies to keep (1-365) |
| Upload to S3 | Yes / No, and how many copies to keep (1-365). Needs S3 enabled on the S3 tab. |

At least one destination is required when the schedule is on. The master
**Enable Backup and Restore** switch turns every backup off; restores still work.

Settings are stored in `admin_settings` as `backup_{database|config}_{enabled|frequency|cron_expression|to_s3|to_local|keep_s3|keep_local}`.
Settings saved before the split (`backup_config_to_s3`, `backup_config_cron`,
`backup_portal_to_s3`, `backup_portal_cron`) are read as defaults (S3 only)
until the tab is saved again.

Saving with the files backup set to S3 also sets
`configuration_file_base_location` and `CONFIGURATION_FILES_BASE_DISK` to `s3`,
as before.

## Where backups are kept

| Place | Path |
|---|---|
| S3 | `backups/{database|config}/YYYY/MM/{database-backup-YYYYMMDD-HHMMSS.sql.gz | config-backup-YYYYMMDD-HHMMSS.zip}` |
| Local copies | The same path on the local disk: `storage/app/private/backups/...` |
| Pre-restore snapshots | `storage/app/private/backups/snapshots/{type}/pre-restore-...` |

Local copies are on the same server and volume as the app. They help with
mistakes and bad restores, not with losing the server; use S3 for that.

## Retention

After each successful backup, the oldest copies beyond the "copies to keep"
number are deleted, separately for local and S3. Only files named like this
service's backups (`database-backup-*.sql.gz`, `config-backup-*.zip` and the
older `config-backup-*.json.gz`) are counted and deleted. Pre-restore snapshots
are never deleted automatically.

## Run now

Each section has a **Run now** button (`POST /admin/settings/backups/run`,
6 per minute). It uses the saved destinations and retention even when the
schedule is off, but not when the master switch is off.

## When a run is skipped

A run is recorded as `skipped` and nothing is written when:

- Backup & Restore (`backup_restore_enabled`) is off
- The section's schedule is off (scheduled runs only)
- No destination is selected
- S3 is the only destination and S3 is disabled

When both destinations are selected and S3 is disabled, the local copy is
still saved and the message says that no S3 copy was made.

## Last run status

Each run writes its result to `admin_settings` under `backup_database_last_run`
or `backup_config_last_run`: `status` (`success`, `failed` or `skipped`),
`message`, `object_key`, `started_at` and `finished_at`. The section shows it
under its buttons, and the **Crons** tab lists each scheduled backup with its
destinations.

A failed run writes a `Scheduled backup failed` entry to the Laravel log, sends
the `backup.failed` notification, and the command exits with code 1.

## Running the scheduler in deployment

The scheduler must run in exactly one place. Pick one of these options.

**Docker Compose:** the `scheduler` service in `docker-compose.yml` runs
`php artisan schedule:work`.

**Cron on the host:** add this entry to the crontab of the user that owns the
app:

```cron
* * * * * cd /path/to/composer && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler reads the settings from `admin_settings` each time it evaluates
the schedule, so a change in the UI applies without a restart. Schedules are
registered only after the installer has finished.

The scheduler container must share these with the app container:

- The same `APP_KEY`. The S3 secret in `admin_settings` is encrypted with it.
- The `storage/` volume. Local copies, snapshots, console files and the installer marker live there.

## Restore

Each section has its own **Restore** list (`GET /admin/settings/backups?section=database|files`).
It loads when the tab is opened and shows, newest first:

- backups in S3 (when S3 is enabled)
- local copies on this server
- pre-restore snapshots on this server

To restore, pick a backup, confirm your password, and tick the overwrite
confirmation.

| Backup | What a restore does |
|---|---|
| Database | Replaces the whole database with the dump, then runs pending migrations. Everyone may need to sign in again. |
| Older portal backup (`portal-backup-*.zip`, listed in the Database section) | Replaces the database with its `database.sql`, runs migrations, and writes its `.env` back. Restart the app containers afterwards. |
| Configuration & console files | Adds missing records back and resets changed records to their backed-up values; records created after the backup are kept. Writes each configuration file's content back to its disk (S3 when available, otherwise local), and writes the console files back. Older `.json.gz` backups (no console files) can still be restored. |

Every restore:

- Needs the current user's password.
- Saves a snapshot of the current state first, as a local backup of the
  same type. The success or error message names the snapshot. To undo a
  restore, restore that snapshot.
- Writes an entry to `activity_logs` with the backup, the snapshot, and the
  result.

The code is in `composer/app/Services/RestoreService.php` and
`composer/app/Http/Controllers/BackupRestoreController.php`.

## Checking the schedule

```bash
php artisan schedule:list      # shows registered backups and next due time
php artisan backup:database    # runs the database backup now (scheduled settings)
php artisan backup:config
```

## Tests

`tests/Feature/ScheduledBackupTest.php` and `tests/Feature/BackupRestoreTest.php`
cover both archive formats, local copies and retention, S3 and local together,
custom cron, settings validation, Run now, the per-section restore lists,
database, configuration and older portal restores, and console file restores.
