# Notifications

AtGlance notifies the people responsible for a workspace when something happens to its servers.

- The **super admin** (`rbac_id` 100) allows channels and sets their organization-level connection on the **Notification** tab (`/admin/settings?tab=notification`).
- **Workspace admins** (`rbac_id` 101 with `workspace_user.is_admin`) add **groups** for the workspaces they manage on the **Notifications** page (`/admin/notifications`). A group is one channel, one target, and the events it receives.
- The super admin also manages **organization** groups, which receive org-wide events.

Example: the super admin sets SMTP on the Email Configuration tab and allows Email. The admin of the `ansible` workspace then adds a group "Ansible on-call" with the team's email addresses. Every host registered in `ansible` sends them an email.

## Channels

| Channel | Super admin sets | Group target |
|---|---|---|
| Email | SMTP on the Email Configuration tab | Email addresses, comma separated |
| Microsoft Teams | Allow | Teams channel webhook URL (Workflows template "Post to a channel when a webhook request is received"), https |
| Slack | Allow | Slack incoming webhook URL, `https://hooks.slack.com/...` |
| WhatsApp (SimpleFloww) | Not available yet: waiting for the provider's API details | — |
| n8n | Allow; optional auth header name and value (for the Webhook node's Header Auth) | n8n webhook URL |
| Telegram | Allow; bot token | Chat ID (e.g. `-1001234567890`) or `@channel`; the bot must be in the chat |
| Webhook | Allow; signing secret | Webhook URL (http or https) |
| SMS (Mailchimp Transactional) | Allow; API key; from number (E.164) | Phone numbers in E.164, comma separated |

A channel can be used only when it is allowed and its required settings are complete. The Notification tab shows "Ready" or "Setup needed" for each channel.

## Events

| Event | Scope | Sent when |
|---|---|---|
| `system.registered` | Workspace | A host is registered or reactivated in the workspace |
| `system.deregistered` | Workspace | A host in the workspace is deregistered |
| `backup.succeeded` | Organization | `backup:config` or `backup:portal` uploads a backup |
| `backup.failed` | Organization | A scheduled backup fails |

Workspace events go to the groups of the host's workspace. Hosts with no workspace send nothing. Organization events go to organization groups.

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
