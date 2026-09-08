@extends('emails.layout', ['heading' => 'Reset your IOMS password'])

@section('content')
    <p style="margin:0 0 18px; font-size:14px; line-height:1.65; color:#334155;">
        We received a request to reset the password for this IOMS account. Use the button below to
        choose a new one.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#2166c4; border-radius:8px;">
                <a href="{{ $resetUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                    Reset password
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 18px; font-size:13px; line-height:1.6; color:#64748b;">
        This link expires in {{ $expiresInMinutes }} minutes. If the button does not work, copy this
        address into your browser:
        <br>
        <span style="word-break:break-all; color:#2166c4;">{{ $resetUrl }}</span>
    </p>

    {{-- Said plainly rather than as boilerplate: a reset link arriving
         unexpectedly is the one signal a customer has that somebody is
         trying to get into their account. --}}
    <p style="margin:0; font-size:12px; line-height:1.6; color:#64748b;">
        If you did not request this, no action is needed and your password stays unchanged. If you
        keep receiving these, contact {{ $supportEmail }}.
    </p>
@endsection
