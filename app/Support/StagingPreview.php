<?php

namespace App\Support;

/**
 * v2.141.1 (ADR 085) -- WHAT A STAGING PREVIEW MAY SHOW ITS OWN TESTER.
 *
 * Staging writes mail to the log (`MAIL_MAILER=log`) so a copy of production data can never email a
 * customer. That also meant the account confirmation link -- the first step of a sandbox purchase --
 * reached nobody, and testing checkout required reading a server log as root.
 *
 * On a deployment that is NOT production AND whose mail goes to the log, the account-created page
 * shows the signed-in person their own confirmation link. Both conditions are required: production
 * never matches (whatever its mailer), and a staging that really sends mail keeps the inbox step.
 * The link is the same signed, expiring URL the email would carry, offered only to the account it
 * belongs to.
 */
final class StagingPreview
{
    public static function revealsMailLinks(): bool
    {
        return ! app()->environment('production') && config('mail.default') === 'log';
    }
}
