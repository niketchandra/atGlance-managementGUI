# Notifications

AtGlance notifies the people responsible for a workspace when something happens to its servers.

- The **super admin** (`rbac_id` 100) allows channels and sets their organization-level connection on the **Notification** tab (`/admin/settings?tab=notification`).
- **Workspace admins with the Admin role** (`rbac_id` 101 with `workspace_user.is_admin`) add **groups** for the workspaces they manage on the **Notifications** page (`/admin/notifications`, linked from the workspace's Notifications tab). A group is one channel, one target, and the events it receives. An email group can be a distribution list; a Slack, Teams or Telegram group is a chat channel.
- Workspace admins with the User role see the workspace's groups and settings read-only on the workspace's Notifications tab.
- On the workspace's **Notifications** tab, workspace admins with the Admin role choose which events the workspace sends at all, and which events members get by email by default.
- **Members** choose their own events under **Settings > Notifications**, per workspace. Until a member saves a choice, the workspace default applies.
- The super admin also manages **organization** groups, which receive org-wide events.

Example: the super admin sets SMTP on the Email Configuration tab and allows Email. The admin of the `ansible` workspace then adds a group "Ansible on-call" with the team's email addresses. Every host registered in `ansible` sends them an email.

## Channels

| Channel | Super admin sets | Group target |
|---|---|---|
| Email | SMTP on the Email Configuration tab (check it there with **Send test email**, which uses the saved settings) | Email addresses, comma separated |
| Microsoft Teams | Allow | Teams channel webhook URL from the Workflows template "Send webhook alerts to a channel", https. Not for private channels; about 28 KB per message |
| Slack | Allow | Slack incoming webhook URL, `https://hooks.slack.com/...` (one channel per webhook) |
| WhatsApp (SimpleFloww) | Not available yet: waiting for the provider's API details | — |
| n8n | Allow; optional Header auth (name, value) and/or Basic auth (user, password), matching the Webhook node | n8n **Production** URL (the workflow must be active) |
| Telegram | Allow; bot token from @BotFather; optional "Send silently" (`disable_notification`) | Chat ID (e.g. `-1001234567890`) or `@channel`; the bot must be in the chat (an admin in channels) |
| Webhook | Allow; optional signing secret | Webhook URL (http or https) |
| SMS (Mailchimp Transactional) | Allow; API key; approved sending number (E.164); consent type (`recurring` default, `recurring-no-confirm`, `onetime`) | Phone numbers in E.164, comma separated |

A channel can be used only when it is allowed and its required settings are complete. The Notification tab lists the channels on the left ("Ready", "Setup needed", "Off", "Coming soon") and, for the open channel, the official guide link, the organization setup steps, the fields, and the steps for a workspace group's target with an example. Guides: `NotificationSettings::GUIDES`.

SMS uses the request body from Mailchimp's "Send your first SMS" guide:
`POST https://mandrillapp.com/api/1.1/messages/send-sms` with `{"key": ..., "message": {"sms": {"text", "to", "from", "consent"}}}`.
Earlier versions sent `message.{to, from, text}` to `api/1.0` without `consent`.

## Events

| Event | Scope | Sent when |
|---|---|---|
| `system.registered` | Workspace | A host is registered or reactivated in the workspace |
| `system.deregistered` | Workspace | A host in the workspace is deregistered |
| `config.uploaded` | Workspace | The CLI uploads a config file for a host in the workspace |
| `config.upload_failed` | Workspace | The console could not save an upload for a known host |
| `ai.issues_found` | Workspace | An AI review (manual or automatic) ends with status `error` or `warning` |
| `workspace.backup_succeeded` | Workspace | A workspace backup is saved (`backup:workspace` or Run now) |
| `workspace.backup_failed` | Workspace | A workspace backup fails |
| `workspace.member_added` | Workspace | A user or workspace admin is added to the workspace |
| `workspace.member_removed` | Workspace | A member is removed from the workspace |
| `backup.succeeded` | Organization | `backup:config` or `backup:database` (or Run now) saves a backup |
| `backup.failed` | Organization | A scheduled backup fails |

Workspace events go to the groups of the host's workspace, and are sent only when the workspace has the event turned on (all are on until the Notifications tab is saved). Hosts with no workspace send nothing. Organization events go to organization groups.

Workspace events are also emailed to members who want them (when the Email channel is allowed). This is one message with the members in Bcc. Addresses that an email group already received are skipped. The member list comes from `WorkspaceNotificationPreference::recipientsFor()`.

System events come from `App\Models\SystemRegister` model events, so every API path (register, deregister, force, reactivate) is covered. Backup events come from `App\Services\BackupService`.

To add an event, such as a service heartbeat going up or down:
1. Add it to `App\Notifications\NotificationEvents`.
2. Call `app(App\Services\Notifier::class)->notify($event, $workspaceId, $title, $facts, $data)` where it happens.

## Delivery

- Messages are sent immediately, in the request or command that triggered the event. They are not queued.
- Each HTTP call has a 5-second timeout.
- A failure is logged and saved on the group (`last_status`, `last_error`). It never fails the API call or the backup.
- The Notifications page shows each group's last delivery. **Send test** sends a test message and reports the result.

## Webhook and n8n payload

```json
{
  "event": "system.registered",
  "title": "System registered: web-01",
  "text": "System registered: web-01\nOrganization: Acme Ops\nSystem: web-01\n...",
  "facts": {"Organization": "Acme Ops", "System": "web-01", "Workspace": "ansible", "IP address": "10.0.0.5", "OS": "linux ubuntu 24.04", "Status": "active"},
  "data": {"system_id": 626821015, "status": "active", "workspace_id": 4},
  "sent_at": "2026-09-26T10:00:00+00:00"
}
```

Webhook requests carry these headers:
- `X-AtGlance-Event: <event>`
- `X-AtGlance-Signature: sha256=<hex HMAC-SHA256 of the raw body with the signing secret>`, sent only when a signing secret is set.

To verify a request, compute the HMAC of the raw request body and compare it with the header.

## Security

- Group targets (webhook URLs, chat IDs, emails, phone numbers) and channel secrets are encrypted at rest.
- Secrets are removed from saved error messages.
- Webhook and n8n URLs can point anywhere the server can reach, including internal addresses. Only admins can set them.

## Code

- `app/Support/NotificationSettings.php`: channel catalog, allowed channels, credentials, SMTP config.
- `app/Services/Notifier.php`: finds matching groups and sends.
- `app/Notifications/Channels/*`: one driver per channel.
- `app/Http/Controllers/NotificationSettingsController.php`: Notification tab.
- `app/Http/Controllers/NotificationsController.php`: Notifications page.
- Table: `notification_groups`.
