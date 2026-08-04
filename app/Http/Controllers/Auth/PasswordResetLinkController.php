<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Uses Laravel's built-in Password broker (password_reset_tokens table
     * already existed from the original scaffolding; User already has the
     * Notifiable trait needed for the default reset-link notification) --
     * no new infrastructure required. MAIL_MAILER defaults to `log` in
     * .env.example, so the reset link is written to storage/logs, not
     * actually emailed, until real SMTP credentials are configured.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? back()->with('success', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
