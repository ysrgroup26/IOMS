<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssued;
use App\Mail\PasswordResetLink;
use App\Mail\TenantActivated;
use App\Mail\VerifyRegistrationEmail;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * v2.57.0 -- WHO EACH IOMS EMAIL COMES FROM, AND WHERE A REPLY GOES.
 *
 * Four mailboxes with four jobs, and the rule is the same for all of them:
 * every message is SENT from `noreply@`, because nobody monitors an
 * automated sender, and every message carries a Reply-To pointing at the
 * human who owns that conversation. Getting the second half wrong is
 * invisible in development — `MAIL_MAILER=log` writes to a file — and
 * becomes a customer replying into a void the moment SMTP is switched on.
 *
 * Deliberately asserts the ENVELOPE rather than delivery. No SMTP
 * credential is needed, referenced, or possible to leak from these tests.
 */
class EmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function registration(): TenantRegistration
    {
        $package = Package::create([
            'name' => 'Professional', 'slug' => 'professional',
            'price_monthly' => 999000, 'price_yearly' => 9990000, 'currency' => 'IDR',
            'max_users' => 50, 'max_companies' => 2, 'is_active' => true, 'is_public' => true,
        ]);

        return TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_AWAITING_PAYMENT,
            'contact_name' => 'Budi Santoso', 'contact_email' => 'budi@contoh.test',
            'password' => bcrypt('secret-pass-1'),
            'company_legal_name' => 'PT Contoh Industri', 'company_address' => 'Jl. Industri 1',
            'company_city' => 'Batam', 'company_province' => 'Kepulauan Riau',
            'package_id' => $package->id, 'billing_cycle' => 'yearly',
            'amount' => 9990000, 'currency' => 'IDR', 'email_verified_at' => now(),
        ]);
    }

    /* ================================================================
     * 1. THE FOUR OFFICIAL IDENTITIES
     * ================================================================ */

    public function test_the_four_mailboxes_are_the_official_iomsuite_addresses(): void
    {
        $this->assertSame('support@iomsuite.com', config('ioms.emails.support'));
        $this->assertSame('billing@iomsuite.com', config('ioms.emails.billing'));
        $this->assertSame('noreply@iomsuite.com', config('ioms.emails.noreply'));
        $this->assertSame('hello@iomsuite.com', config('ioms.emails.hello'));
    }

    /**
     * The Mailables set From explicitly, so a mismatched MAIL_FROM_ADDRESS
     * would not break them — but anything Laravel sends WITHOUT an explicit
     * From (a framework notification, a future Mailable that forgets)
     * falls back to this. It has to agree with the noreply mailbox, or a
     * message leaves under an address the sending domain does not
     * authorise, which is how mail lands in spam.
     */
    public function test_the_global_from_address_agrees_with_the_noreply_mailbox(): void
    {
        $this->assertSame(config('ioms.emails.noreply'), config('mail.from.address'));
    }

    /* ================================================================
     * 2. FROM IS ALWAYS NOREPLY; REPLY-TO IS THE CONVERSATION'S OWNER
     * ================================================================ */

    public function test_invoice_mail_replies_to_billing(): void
    {
        $registration = $this->registration();

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-0001',
            'registration_id' => $registration->id,
            'period_start' => now()->toDateString(), 'period_end' => now()->addYear()->toDateString(),
            'amount' => 9990000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED, 'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $envelope = (new InvoiceIssued($invoice, 'Professional', 'Rp9.990.000'))->envelope();

        $this->assertSame('noreply@iomsuite.com', $envelope->from->address);
        $this->assertSame('IOMS', $envelope->from->name);
        $this->assertSame('billing@iomsuite.com', $envelope->replyTo[0]->address);
    }

    public function test_activation_mail_replies_to_support(): void
    {
        $registration = $this->registration();

        $envelope = (new TenantActivated($registration, 'https://iomsuite.com/login'))->envelope();

        $this->assertSame('noreply@iomsuite.com', $envelope->from->address);
        $this->assertSame('support@iomsuite.com', $envelope->replyTo[0]->address);
    }

    /** A prospect confirming an email is still a sales conversation, not a support one. */
    public function test_registration_verification_mail_replies_to_sales(): void
    {
        $registration = $this->registration();

        $envelope = (new VerifyRegistrationEmail($registration, 'https://iomsuite.com/verify'))->envelope();

        $this->assertSame('noreply@iomsuite.com', $envelope->from->address);
        $this->assertSame('hello@iomsuite.com', $envelope->replyTo[0]->address);
    }

    /* ================================================================
     * 3. PASSWORD RESET — THE ONE THAT WAS STOCK LARAVEL
     * ================================================================ */

    public function test_password_reset_mail_carries_the_ioms_identity(): void
    {
        $envelope = (new PasswordResetLink('https://iomsuite.com/reset-password/abc', 60))->envelope();

        $this->assertSame('noreply@iomsuite.com', $envelope->from->address);
        $this->assertSame('IOMS', $envelope->from->name);
        // Somebody locked out of their account needs a human, not a void.
        $this->assertSame('support@iomsuite.com', $envelope->replyTo[0]->address);
        $this->assertStringContainsString('IOMS', $envelope->subject);
    }

    /**
     * And the password broker actually routes through it. Without the
     * `sendPasswordResetNotification` override, Laravel sends its own
     * unbranded notification and this Mailable is never reached.
     */
    public function test_requesting_a_reset_link_sends_the_ioms_mailable(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['name' => 'Org', 'slug' => 'org', 'status' => Tenant::STATUS_ACTIVE]);

        User::create([
            'name' => 'Admin', 'email' => 'admin@contoh.test', 'password' => bcrypt('secret-pass-1'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Password::sendResetLink(['email' => 'admin@contoh.test']);

        Mail::assertSent(PasswordResetLink::class, fn ($mail) => $mail->hasTo('admin@contoh.test'));
    }

    /* ================================================================
     * 4. NO STALE PRODUCTION-FACING IDENTITY
     * ================================================================ */

    /**
     * The seeded `@ioms.local` accounts are deliberate development
     * fixtures and are NOT covered here. What must never carry a stale
     * address is anything a customer receives or reads.
     */
    public function test_no_stale_address_reaches_a_customer_facing_identity(): void
    {
        $productionFacing = [
            config('ioms.emails.support'),
            config('ioms.emails.billing'),
            config('ioms.emails.noreply'),
            config('ioms.emails.hello'),
            config('ioms.support_email'),
            config('mail.from.address'),
            config('ioms.sandbox.user_email'),
        ];

        foreach ($productionFacing as $address) {
            foreach (['@ioms.id', '@ioms.local', 'shipyard.local'] as $stale) {
                $this->assertStringNotContainsString(
                    $stale,
                    (string) $address,
                    "A customer-facing identity still uses the stale domain {$stale}."
                );
            }
        }
    }

    /** IOMS is a standalone brand; the long-form expansion is not the product name. */
    public function test_the_mail_sender_name_is_the_product_name(): void
    {
        $this->assertSame('IOMS', config('ioms.name'));
        $this->assertStringNotContainsString('Integrated Operations Management System', (string) config('mail.from.name'));
    }

    /* ================================================================
     * 5. NO SECRET IS REQUIRED TO BE IN SOURCE CONTROL
     * ================================================================ */

    /**
     * The SMTP password is entered on the server and nowhere else. This
     * asserts the shipped example file keeps every credential key EMPTY —
     * so a filled one would be a failing test, not a quiet commit.
     */
    public function test_the_committed_example_environment_holds_no_credentials(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        foreach (['MAIL_PASSWORD', 'MIDTRANS_SERVER_KEY', 'MIDTRANS_CLIENT_KEY', 'APP_KEY', 'DB_PASSWORD'] as $key) {
            if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $example, $m)) {
                continue;
            }

            $value = trim($m[1], " \"'");

            $this->assertTrue(
                $value === '' || $value === 'null',
                "{$key} in .env.example must ship empty, found: {$value}"
            );
        }
    }

    /** The suite itself must never depend on a real mail credential. */
    public function test_the_test_environment_sends_no_real_mail(): void
    {
        $this->assertContains(config('mail.default'), ['array', 'log', 'smtp']);
        $this->assertEmpty(config('mail.mailers.smtp.password'));
    }
}
