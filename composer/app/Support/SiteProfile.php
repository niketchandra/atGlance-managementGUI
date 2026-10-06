<?php

namespace App\Support;

use App\Models\AdminSetting;
use App\Models\Organization;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * The organization's white-label profile: name, logo and the public pages
 * (About, Features, FAQ, Support, Contact) set on the Site Configuration tab.
 */
class SiteProfile
{
    public const DEFAULT_NAME = 'AtGlance';
    public const DEFAULT_ORGANIZATION_ID = 200;

    public const PAGES = ['about', 'features', 'faq', 'support', 'contact'];

    public const PAGE_TITLES = [
        'about' => 'About',
        'features' => 'Features',
        'faq' => 'FAQ',
        'support' => 'Support',
        'contact' => 'Contact',
    ];

    private const SETTING_KEYS = [
        'site_logo_url',
        'site_logo_path',
        'site_description',
        'site_about',
        'site_features',
        'site_faq',
        'site_support_contact_name',
        'site_support_contact_email',
        'site_support_contact_phone',
        'site_support_hours',
        'site_support_request_url',
        'site_support_details',
        'site_contact_enabled',
        'site_contact_intro',
    ];

    private array $settings;
    private string $organizationName;

    private function __construct()
    {
        $this->settings = [];
        $this->organizationName = '';

        try {
            if (Schema::hasTable('admin_settings')) {
                foreach (AdminSetting::query()->whereIn('setting_key', self::SETTING_KEYS)->get() as $setting) {
                    $this->settings[$setting->setting_key] = $setting->is_encrypted
                        ? AdminSetting::getValue($setting->setting_key, '')
                        : $setting->setting_value;
                }
            }

            if (Schema::hasTable('organizations')) {
                $this->organizationName = trim((string) Organization::query()
                    ->whereKey(self::DEFAULT_ORGANIZATION_ID)
                    ->value('name'));
            }
        } catch (Throwable $e) {
            // Not installed yet, or the database is down: fall back to defaults.
        }
    }

    /**
     * One profile per request, so every view shares a single lookup.
     */
    public static function current(): self
    {
        $request = request();
        $profile = $request->attributes->get(self::class);

        if (!$profile instanceof self) {
            $profile = new self();
            $request->attributes->set(self::class, $profile);
        }

        return $profile;
    }

    /**
     * Drops the cached profile after the settings change in this request.
     */
    public static function forget(): void
    {
        request()->attributes->remove(self::class);
    }

    public function name(): string
    {
        return $this->organizationName !== '' ? $this->organizationName : self::DEFAULT_NAME;
    }

    public function logoUrl(): string
    {
        $override = $this->string('site_logo_url');
        if ($override !== '') {
            return $override;
        }

        $path = ltrim($this->string('site_logo_path'), '/');
        if ($path === '') {
            return '';
        }

        // Served through the app so it works for the public disk and for S3.
        return url('/site-logo/' . str_replace('%2F', '/', rawurlencode($path)));
    }

    public function description(): string
    {
        return $this->string('site_description');
    }

    public function about(): string
    {
        return $this->string('site_about');
    }

    /**
     * Features, one per line. "Title: description" splits into both parts.
     *
     * @return array<int, array{title: string, description: string}>
     */
    public function features(): array
    {
        return collect($this->list('site_features'))
            ->map(function ($line) {
                $line = trim((string) $line);
                if (str_contains($line, ':')) {
                    [$title, $description] = array_map('trim', explode(':', $line, 2));

                    return ['title' => $title, 'description' => $description];
                }

                return ['title' => $line, 'description' => ''];
            })
            ->filter(fn (array $feature) => $feature['title'] !== '' || $feature['description'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public function faq(): array
    {
        return collect($this->list('site_faq'))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'question' => trim((string) ($item['question'] ?? '')),
                'answer' => trim((string) ($item['answer'] ?? '')),
            ])
            ->filter(fn (array $item) => $item['question'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{contact_name: string, contact_email: string, contact_phone: string, hours: string, request_url: string, details: string}
     */
    public function support(): array
    {
        return [
            'contact_name' => $this->string('site_support_contact_name'),
            'contact_email' => $this->string('site_support_contact_email'),
            'contact_phone' => $this->string('site_support_contact_phone'),
            'hours' => $this->string('site_support_hours'),
            'request_url' => $this->string('site_support_request_url'),
            'details' => $this->string('site_support_details'),
        ];
    }

    public function contactEnabled(): bool
    {
        return filter_var($this->string('site_contact_enabled'), FILTER_VALIDATE_BOOL);
    }

    public function contactIntro(): string
    {
        return $this->string('site_contact_intro');
    }

    public function hasPage(string $page): bool
    {
        return match ($page) {
            'about' => $this->about() !== '',
            'features' => $this->features() !== [],
            'faq' => $this->faq() !== [],
            'support' => collect($this->support())->contains(fn (string $value) => $value !== ''),
            'contact' => $this->contactEnabled(),
            default => false,
        };
    }

    /**
     * Pages with content, in Quick Links order, as [page => title].
     */
    public function availablePages(): array
    {
        return collect(self::PAGE_TITLES)
            ->filter(fn (string $title, string $page) => $this->hasPage($page))
            ->all();
    }

    /**
     * Renders admin-written Markdown. Raw HTML is stripped and unsafe links
     * (javascript:, data:) are dropped.
     */
    public static function markdown(string $text): HtmlString
    {
        return new HtmlString(Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    private function string(string $key): string
    {
        $value = trim((string) ($this->settings[$key] ?? ''));

        return strtolower($value) === 'null' ? '' : $value;
    }

    private function list(string $key): array
    {
        $decoded = json_decode($this->string($key), true);

        return is_array($decoded) ? $decoded : [];
    }
}
