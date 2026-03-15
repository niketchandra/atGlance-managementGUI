<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Handle user registration
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Log the user in
            Auth::login($user);

            return redirect()->route('dashboard')->with('success', 'Registration successful! Welcome to AtGlance.');
        } catch (\Exception $e) {
            return back()->withErrors(['register' => 'Registration failed. Please try again.']);
        }
    }

    /**
     * Handle user login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find the user by email
        $user = User::where('email', $credentials['email'])->first();

        // Check if user exists and password matches
        $passwordMatches = $user ? Hash::check($credentials['password'], $user->password_hash) : false;

        Log::info('web_login_attempt', [
            'email' => $credentials['email'],
            'db_default' => config('database.default'),
            'db_name' => DB::connection()->getDatabaseName(),
            'db_host' => config('database.connections.mysql.host'),
            'user_found' => (bool) $user,
            'password_hash_length' => $user ? strlen((string) $user->password_hash) : 0,
            'password_matches' => $passwordMatches,
        ]);

        if ($user && $passwordMatches) {
            // Log the user in
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            return redirect()->route('dashboard')->with('success', 'Welcome back!');
        }

        return back()->withErrors([
            'login' => 'The provided credentials do not match our records.',
        ]);
    }

    /**
     * Handle user logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'You have been logged out successfully.');
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetLink(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
        ]);

        // Generate a reset token (in a real app, use Laravel's password reset functionality)
        $token = str_random(64);

        // Store the token in the database or cache
        // For now, just return a message
        return back()->with('status', 'If an account exists with this email, a password reset link will be sent shortly.');
    }

    /**
     * Store contact form submission
     */
    public function storeContact(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|min:10',
        ]);

        try {
            // Here you would typically save the contact message to database
            // and send it to your email
            
            // For now, just return success
            return back()->with('success', 'Thank you for reaching out! We will get back to you soon.');
        } catch (\Exception $e) {
            return back()->withErrors(['contact' => 'Failed to send message. Please try again later.']);
        }
    }
}
