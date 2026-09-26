<?php

namespace App\Http\Controllers;

use App\Models\NotificationGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationEvents;
use App\Services\Notifier;
use App\Support\NotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Notifications console: workspace admins add the groups (email lists, Teams
 * or Slack channels, Telegram chats, ...) that their workspace notifies. The
 * super admin also manages organization-level groups.
 */
class NotificationsController extends Controller
{
    public const ORGANIZATION_SCOPE = 'organization';

    public function index(Request $request): View
    {
        $scopes = $this->scopes();
        $scope = (string) $request->query('scope', (string) array_key_first($scopes));
        if (!array_key_exists($scope, $scopes)) {
            $scope = (string) array_key_first($scopes);
        }

        $groups = $scope === ''
            ? collect()
            : $this->groupsQuery($scope)->orderBy('name')->get();

        return view('admin.notifications', [
            'scopes' => $scopes,
            'scope' => $scope,
            'groups' => $groups,
            'channels' => NotificationSettings::usableChannels(),
            'events' => $scope === '' ? [] : NotificationEvents::forScope($this->eventScope($scope)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $scope = (string) $request->input('scope', '');
        $this->authorizeScope($scope);

        $validated = $this->validateGroup($request, $scope, null);

        NotificationGroup::create($validated + [
            'workspace_id' => $scope === self::ORGANIZATION_SCOPE ? null : (int) $scope,
            'created_by' => Auth::id(),
        ]);

        return $this->backTo($scope)->with('success', 'Notification group added.');
    }

    public function update(Request $request, NotificationGroup $group): RedirectResponse
    {
        $scope = $this->scopeOf($group);
        $this->authorizeScope($scope);

        $group->update($this->validateGroup($request, $scope, $group));

        return $this->backTo($scope)->with('success', 'Notification group updated.');
    }

    public function destroy(NotificationGroup $group): RedirectResponse
    {
        $scope = $this->scopeOf($group);
        $this->authorizeScope($scope);

        $group->delete();

        return $this->backTo($scope)->with('success', 'Notification group deleted.');
    }

    public function test(NotificationGroup $group, Notifier $notifier): RedirectResponse
    {
        $scope = $this->scopeOf($group);
        $this->authorizeScope($scope);

        if (!NotificationSettings::isAllowed($group->channel)) {
            return $this->backTo($scope)->withErrors(['notification' => 'This channel is not allowed by the super admin.']);
        }

        $error = $notifier->test($group);

        return $error === null
            ? $this->backTo($scope)->with('success', 'Test notification sent to "' . $group->name . '".')
            : $this->backTo($scope)->withErrors(['notification' => 'Test failed for "' . $group->name . '": ' . $error]);
    }

    /**
     * Scopes the current user manages: scope key => label. The super admin
     * gets the organization and every workspace; an admin gets the
     * workspaces where they are a workspace admin.
     *
     * @return array<string, string>
     */
    private function scopes(): array
    {
        /** @var User $user */
        $user = Auth::user();

        if ((int) $user->rbac_id === 100) {
            $workspaces = Workspace::query()->orderBy('name')->get(['id', 'name']);

            return [self::ORGANIZATION_SCOPE => 'Organization (org-wide events)']
                + $workspaces->mapWithKeys(fn (Workspace $workspace) => [(string) $workspace->id => $workspace->name])->all();
        }

        return $user->workspaces()
            ->wherePivot('is_admin', true)
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name'])
            ->mapWithKeys(fn (Workspace $workspace) => [(string) $workspace->id => $workspace->name])
            ->all();
    }

    private function authorizeScope(string $scope): void
    {
        abort_unless($scope !== '' && array_key_exists($scope, $this->scopes()), 403, 'You do not manage this workspace.');
    }

    private function scopeOf(NotificationGroup $group): string
    {
        return $group->workspace_id === null ? self::ORGANIZATION_SCOPE : (string) $group->workspace_id;
    }

    private function eventScope(string $scope): string
    {
        return $scope === self::ORGANIZATION_SCOPE ? NotificationEvents::SCOPE_ORGANIZATION : NotificationEvents::SCOPE_WORKSPACE;
    }

    private function groupsQuery(string $scope)
    {
        return $scope === self::ORGANIZATION_SCOPE
            ? NotificationGroup::query()->whereNull('workspace_id')
            : NotificationGroup::query()->where('workspace_id', (int) $scope);
    }

    private function backTo(string $scope): RedirectResponse
    {
        return redirect()->route('admin.notifications', ['scope' => $scope]);
    }

    /**
     * On update, the channel is fixed and a blank target keeps the saved one.
     */
    private function validateGroup(Request $request, string $scope, ?NotificationGroup $group): array
    {
        $channels = array_keys(NotificationSettings::usableChannels());
        if ($group !== null) {
            $channels[] = $group->channel;
        }

        $validated = $request->validate([
            'channel' => $group === null ? ['required', Rule::in($channels)] : ['nullable'],
            'name' => ['required', 'string', 'max:255'],
            'target' => [$group === null ? 'required' : 'nullable', 'string', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(NotificationEvents::forScope($this->eventScope($scope))))],
            'enabled' => ['nullable', 'boolean'],
        ], [
            'channel.in' => 'Choose a channel the super admin has allowed.',
            'events.required' => 'Choose at least one event.',
        ]);

        $channel = $group?->channel ?? $validated['channel'];
        $result = [
            'name' => trim($validated['name']),
            'events' => array_values(array_unique($validated['events'])),
            'enabled' => $request->boolean('enabled', $group === null),
        ];

        $target = trim((string) ($validated['target'] ?? ''));
        if ($group === null || $target !== '') {
            $result['target'] = $this->normalizeTarget($channel, $target);
        }

        if ($group === null) {
            $result['channel'] = $channel;
        }

        return $result;
    }

    private function normalizeTarget(string $channel, string $target): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['target' => $message]);

        switch ($channel) {
            case 'email':
            case 'sms':
                $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $target)))));
                if ($items === [] || count($items) > 50) {
                    $fail('Enter between 1 and 50 entries.');
                }
                foreach ($items as $item) {
                    $valid = $channel === 'email'
                        ? filter_var($item, FILTER_VALIDATE_EMAIL) !== false
                        : preg_match('/^\+[1-9]\d{6,14}$/', $item) === 1;
                    if (!$valid) {
                        $fail($channel === 'email' ? "\"{$item}\" is not a valid email address." : "\"{$item}\" is not an E.164 phone number (e.g. +919876543210).");
                    }
                }

                return implode(',', $items);

            case 'telegram':
                if (preg_match('/^(-?\d{5,20}|@[A-Za-z][A-Za-z0-9_]{4,63})$/', $target) !== 1) {
                    $fail('Enter a Telegram chat ID (e.g. -1001234567890) or channel username (e.g. @ops_alerts).');
                }

                return $target;

            default:
                $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
                $host = strtolower((string) parse_url($target, PHP_URL_HOST));
                if (filter_var($target, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                    $fail('Enter a valid http or https URL.');
                }
                if (in_array($channel, ['slack', 'teams'], true) && $scheme !== 'https') {
                    $fail('The webhook URL must use https.');
                }
                if ($channel === 'slack' && $host !== 'hooks.slack.com') {
                    $fail('A Slack incoming webhook URL starts with https://hooks.slack.com/.');
                }

                return $target;
        }
    }
}
