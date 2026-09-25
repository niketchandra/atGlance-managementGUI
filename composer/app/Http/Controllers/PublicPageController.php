<?php

namespace App\Http\Controllers;

use App\Models\ContactSubmission;
use App\Support\SiteProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The organization's public pages, linked from the home page Quick Links.
 */
class PublicPageController extends Controller
{
    public function show(string $page): View
    {
        $profile = SiteProfile::current();

        abort_unless($profile->hasPage($page), 404);

        return view('public-page', [
            'page' => $page,
            'pageTitle' => SiteProfile::PAGE_TITLES[$page],
            'profile' => $profile,
        ]);
    }

    public function submitContact(Request $request): RedirectResponse
    {
        abort_unless(SiteProfile::current()->contactEnabled(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        ContactSubmission::create($validated + [
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        return redirect()
            ->route('public.page', ['page' => 'contact'])
            ->with('success', 'Thank you for reaching out! We will get back to you soon.');
    }
}
