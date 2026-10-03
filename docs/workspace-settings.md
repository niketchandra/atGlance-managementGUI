# Workspace Settings

Each workspace has its own settings page. Open it from **Workspace** in the sidebar
(`/admin/workspaces/{id}`) or, as super admin, from the Enterprise Console
(`/workspace/{id}`).

## Who can change them

Any user can be a workspace admin (`workspace_user.is_admin = 1`), whatever their account role.

- **Workspace admins with the Admin role** (`rbac_id` 101) and **the super admin** (100) can change everything.
- **Workspace admins with the User role** (102) can change only what an Admin-role workspace admin (or the super admin) allows. This access is chosen in the Add Workspace Admin form, and later through **Edit** in the Access column of the Members table. It is stored in `workspace_user.permissions`.

| Access (`Workspace::PERMISSIONS`) | Default for a User-role workspace admin |
|---|---|
| `general`: change General settings (name, description) | Yes |
| `members`: add and remove users | Yes |
| `admins`: add and remove other workspace admins | No |
| `vulnerability_checks`: change Vulnerability Checks, run a check | No |
| `backups`: change Backups, run a backup | No |
| `notifications`: change Notifications and the workspace's channel groups | No |

- Workspace Tags can be changed by every workspace admin.
- Tabs without access are shown greyed out, read only.
- Only Admin-role workspace admins and the super admin can set access. A User-role workspace admin cannot change anyone's access, including their own.
- `Workspace::allows($user, $permission)` is the check, and the server returns 403 without it.
- Workspace status and deleting a workspace stay super-admin only.

Removing members:
- Nobody can remove themselves or the super admin.
- Removing a workspace admin needs `admins`; removing a user needs `members`.
- A workspace admin with the User role cannot remove a workspace admin who has the Admin role.
- See `AdminDashboardController::canRemoveMember()`.

## Tabs

| Tab | What it sets |
|---|---|
| General | Name and description. Super admin also sees status and Delete. |
| Members | Add workspace admins and users; one table of members with workspace role and account role. |
| Workspace Tags | Key/value tags (`env` = `prod`), added and removed by any workspace admin. Shown and filterable on Manage Workspaces and in the Enterprise Console. When editing a system in the workspace they are offered as `key=value`, and they are suggested in the Systems tag filter. `system_register.tags` is still a free comma-separated string. |
| Vulnerability Checks | Automatic AI review of the **latest version** of each config file: on upload, and/or on a schedule. See below. |
| Backups | Scheduled backup of the workspace's stored config files, with notes and history. See below. |
| Notifications | Which events the workspace sends, default member emails, channel groups, member choices. See `docs/notifications.md`. |

## Storage

| Table | Holds |
|---|---|
| `workspace_settings` | One JSON document per workspace (`App\Support\WorkspaceSettings`, defaults in `DEFAULTS`). `tags` is a list of `{key, value}`; older plain tags are read as keys with no value. |
| `workspace_backup_runs` | One row per workspace backup run: status, message, file path and disk, notes, who ran it. |
| `workspace_notification_preferences` | A member's own choice of events and email on/off for one workspace. |

`config_ai_validations.user_id` is nullable (automatic reviews have no user) and
`config_ai_validations.trigger` is `manual`, `upload` or `schedule`.

## Vulnerability Checks

- **Check new uploads**: after the CLI uploads a config (`POST /api/config-files/upload`),
  `App\Jobs\ValidateConfigWithAi` is queued for it.
- **Scheduled re-check**: `php artisan ai:validate-workspace {id}` runs on the chosen schedule
  (preset or custom five-field cron). It queues the latest version of every
  `(system, file name)` pair in the workspace. With **Skip files already reviewed**
  (the default), latest versions that already have a review are left out.
- **Run check now** runs the same sweep immediately.
- **Check Queue** on the tab shows the latest run as a progress bar with a percentage and counts of reviewed, skipped and failed files. It refreshes every 3 seconds while the run is in progress (`GET /admin/workspaces/{id}/ai/progress`). Each run is a `workspace_ai_runs` row; every queued job adds 1 to `done`, `skipped` or `failed` when it ends. A job killed by the worker counts as failed, so a run always finishes.

The job runs on the queue worker, with one try and a 200-second timeout, so a failing provider is not called again by retries.
It does nothing when AI Connect is off, or when a newer upload of the same file
arrived before it ran. A review with status `error` or `warning` sends
`ai.issues_found`. This also applies to manual reviews.

Every review is an AI call that your provider bills for. A re-check with skipping
turned off reviews every latest version on every run.

## Backups

`php artisan backup:workspace {id}` (scheduled per workspace, or **Run backup now**)
writes `workspace-backup-YYYYMMDD-HHMMSS.zip` to
`backups/workspace-{id}/YYYY/MM/` on the local disk and/or S3, then keeps the
newest N copies in each place. The zip has:

- `config.json`: the workspace's rows of `system_register`, `services`,
  `configuration_files`, `raw_data` (file content) and `config_ai_validations`
- `workspace.json`: id, name, notes, time, counts
- `notes.txt`: the notes from the Backups tab, when set

The history lists the last 20 runs with their notes and a download link.
A run sends `workspace.backup_succeeded` or `workspace.backup_failed`.

The console has no restore for a single workspace's backup.
The organization configuration backup (`docs/scheduled-backups.md`) still covers
every workspace and can be restored.

## Schedules

Both schedules are registered in `composer/routes/console.php` from
`WorkspaceSettings::workspacesWith(...)`. They only run for active workspaces.
The scheduler container (`schedule:work`) re-reads them every minute, so changes
apply without a restart. Check them with `php artisan schedule:list`.
