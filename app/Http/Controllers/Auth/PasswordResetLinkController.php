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
     * Uses Laravel's built-in Password broker -- the `password_reset_tokens`
     * table came with the original scaffolding and User already has the
     * Notifiable trait.
     *
     * v2.57.0: what the broker SENDS is no longer Laravel's stock
     * notification. `User::sendPasswordResetNotification()` routes it
     * through App\Mail\PasswordResetLink, so the message carries the IOMS
     * letterhead, comes from the noreply mailbox and replies to support.
     * That was invisible while MAIL_MAILER was `log` -- which it still is
     * locally, so a reset link lands in storage/logs rather than an inbox
     * until real SMTP credentials are configured on the server.
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
