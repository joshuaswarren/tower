<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Minimal session login for the admin board. Registration is intentionally
 * absent: the admin user is created by `AdminUserSeeder` (env-gated) and
 * tests use the User factory. There is no password reset flow on a
 * single-tenant open-source board.
 */
final class SessionController extends Controller
{
    /**
     * Show the login form. The public board at `/` does not use this
     * controller; only `/board` (authed) redirects here when the user
     * is not authenticated.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an authentication attempt. On success, regenerate the
     * session id (defense against session fixation) and send the admin
     * to `/board`. On failure, throw a `ValidationException` so the form
     * can re-render with the error.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/board');
    }

    /**
     * Log out the current user. Invalidates the session and regenerates
     * the CSRF token so a logged-out browser cannot replay a stale form.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
