<?php

namespace App\Console\Commands;

use App\Mail\VerifyAccountEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * v2.131.0 -- ONE COMMAND THAT SAYS WHETHER THIS SERVER CAN SEND MAIL.
 *
 * The registration HTTP 500 the payment provider's reviewers hit was a
 * mail transport failure that nobody had seen, because nothing ever asked
 * the transport a question until a stranger registered. This asks it on
 * purpose, after every mail change and every server move:
 *
 *   php artisan ioms:mail-check you@example.com
 *
 * It prints the configuration it is about to use -- mailer, host, port,
 * scheme, username, From -- and NEVER the password, then sends one plain
 * message and one rendered IOMS email (the verification template, with a
 * link that points nowhere), and reports the transport's own error if it
 * refuses. Exit code 0 only when both were accepted by the transport.
 *
 * "Accepted by the transport" is the honest claim: delivery to an inbox,
 * and passing SPF/DKIM/DMARC there, is checked by reading the headers of
 * the message that arrives. See docs/runbooks/EMAIL-ZOHO.md.
 */
class MailCheck extends Command
{
    protected $signature = 'ioms:mail-check {to : An address you control}';

    protected $description = 'Send two test emails through the configured mailer and report exactly what happened';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not an email address.');

            return self::INVALID;
        }

        $mailer = (string) config('mail.default');
        $smtp = config('mail.mailers.smtp', []);

        $this->table(['Setting', 'Value'], [
            ['MAIL_MAILER', $mailer],
            ['MAIL_HOST', (string) ($smtp['host'] ?? '')],
            ['MAIL_PORT', (string) ($smtp['port'] ?? '')],
            ['MAIL_SCHEME', (string) ($smtp['scheme'] ?? '(inferred from port)')],
            ['MAIL_USERNAME', (string) ($smtp['username'] ?? '')],
            ['MAIL_PASSWORD', filled($smtp['password'] ?? null) ? '(set, not shown)' : '(EMPTY)'],
            ['From', config('mail.from.address').' / '.config('mail.from.name')],
        ]);

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is '{$mailer}': nothing will leave this server. Set MAIL_MAILER=smtp to test delivery.");
        }

        $ok = true;

        $ok = $this->attempt('Plain message', function () use ($to) {
            Mail::raw('IOMS mail check: if you can read this, the server can send mail.', function ($m) use ($to) {
                $m->to($to)->subject('IOMS mail check');
            });
        }) && $ok;

        $ok = $this->attempt('IOMS verification template', function () use ($to) {
            $probe = new User(['name' => 'IOMS Mail Check', 'email' => $to]);
            Mail::to($to)->send(new VerifyAccountEmail($probe, url('/'), 60));
        }) && $ok;

        if ($ok) {
            $this->info('Both messages were accepted by the transport. Now open them and check the headers for spf=pass, dkim=pass and dmarc=pass.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function attempt(string $label, callable $send): bool
    {
        try {
            $send();
            $this->line("  <info>accepted</info>  {$label}");

            return true;
        } catch (Throwable $e) {
            // The transport's message names a host and a reason; it never
            // carries the password, which Symfony Mailer does not echo.
            $this->line("  <error>refused</error>   {$label}: ".$e::class.': '.$e->getMessage());

            return false;
        }
    }
}
