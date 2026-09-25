# Scheduled S3 Backups

The **Backup & Restore** tab in `/admin/settings` stores two backup schedules.
Laravel's scheduler runs them as artisan commands and uploads each result to
the S3 bucket configured on the **S3** tab.

## Commands

| Command | What it uploads | S3 object key |
|---|---|---|
| `php artisan backup:config` | Gzipped JSON export of the `services`, `system_register`, `configuration_files` and `raw_data` tables. `raw_data.file_data` holds each file's content. | `backups/config/YYYY/MM/config-backup-YYYYMMDD-HHMMSS.json.gz` |
| `php artisan backup:portal` | Zip that holds `.env`, `admin_settings.json` and `database.sql` (a full SQL dump of every table, made in PHP, so `mysqldump` is not needed). | `backups/portal/YYYY/MM/portal-backup-YYYYMMDD-HHMMSS.zip` |

Both commands are in `composer/routes/console.php`. The logic is in
`composer/app/Services/BackupService.php`.

The portal archive contains `.env` (including `APP_KEY`) and the encrypted
admin settings. Keep the bucket private.

## When a run is skipped

A run is recorded as `skipped` and nothing is uploaded when any of these
settings is off:

- Backup & Restore (`backup_restore_enabled`)
- The backup's own toggle (`backup_config_to_s3` or `backup_portal_to_s3`)
- S3 (`s3_enabled`)

A skipped schedule is also not registered with the scheduler.

## Frequencies

| Setting value | Cron expression |
|---|---|
| `hourly` | `0 * * * *` |
| `every_six_hours` | `0 */6 * * *` |
| `every_twelve_hours` | `0 */12 * * *` |
| `daily` | `0 0 * * *` |
| `weekly` | `0 0 * * 0` |
| `monthly` | `0 0 1 * *` |

The portal backup accepts only `daily`, `weekly` and `monthly`.

The scheduler reads the frequencies from `admin_settings` each time it
evaluates the schedule. A change in the UI applies to the next evaluation
without a restart. Schedules are registered only after the installer has
finished (`storage/app/installer/installed.json` exists).

## Last run status

Each run writes its result to `admin_settings`, under the keys
`backup_config_last_run` and `backup_portal_last_run`. The result holds
`status` (`success`, `failed` or `skipped`), `message`, `object_key`,
`started_at` and `finished_at`. The **Crons** tab shows the last run of each
configured backup.

A failed run also writes a `Scheduled backup failed` entry to the Laravel log.
The command exits with code 1.

## Running the scheduler in deployment

The scheduler must run in exactly one place. Pick one of these options.

**Docker Compose:** the `scheduler` service in `docker-compose.yml` runs
`php artisan schedule:work`.

**Cron on the host:** add this entry to the crontab of the user that owns the
app:

```cron
* * * * * cd /path/to/composer && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler container must share these with the `api` container:

- The same `APP_KEY`. The S3 secret in `admin_settings` is encrypted with it.
- The `storage/` volume. The installer marker lives there.
- The same `.env` file, if the portal backup must contain the `.env` that the
  web UI edits.

## Checking the schedule

```bash
php artisan schedule:list      # shows registered backups and next due time
php artisan backup:config      # runs one backup now
php artisan backup:portal
```
