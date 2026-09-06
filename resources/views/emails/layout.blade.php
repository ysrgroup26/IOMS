{{--
    v2.51.0 -- the one transactional email shell for IOMS.

    Table-based and inline-styled on purpose: every serious mail client
    (Outlook desktop above all) still renders a stripped subset of CSS
    with no flex, no grid and no external stylesheet, so the layout has to
    survive on the same primitives the PDF templates use.

    Carries the IOMS platform identity, not a tenant's -- these messages
    come from IOMS to a customer about their IOMS subscription. Tenant
    branding belongs on documents the tenant generates, not on billing
    mail from their supplier.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine ?? config('ioms.name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:Segoe UI, Roboto, Helvetica, Arial, sans-serif; color:#0f2747;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">

                <tr>
                    <td style="background-color:#0f2747; padding:22px 28px;">
                        <span style="font-size:18px; font-weight:700; letter-spacing:0.5px; color:#ffffff;">IOMS</span>
                        <span style="font-size:11px; color:#94a3b8; letter-spacing:1.5px; text-transform:uppercase; margin-left:10px;">Industrial Operations Platform</span>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;">
                        @if (! empty($heading))
                            <h1 style="margin:0 0 14px; font-size:19px; font-weight:600; color:#0f2747;">{{ $heading }}</h1>
                        @endif

                        @yield("content")
                    </td>
                </tr>

                <tr>
                    <td style="border-top:1px solid #e2e8f0; padding:16px 28px; background-color:#f8fafc;">
                        <p style="margin:0; font-size:11px; line-height:1.6; color:#64748b;">
                            This message was sent by IOMS regarding your subscription.
                            Questions? Reply to this email or contact {{ config('ioms.support_email') }}.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
