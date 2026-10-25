<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * v2.131.0 -- A BROKEN MAIL SERVER IS NOT A BROKEN REGISTRATION.
 *
 * The payment provider's review team registered at /register and got an
 * HTTP 500. The account row had already been written; the verification
 * email was then sent synchronously, the SMTP transport threw, and nothing
 * caught it. Retrying then said "already registered". Every other mail
 * send in IOMS was already guarded -- these two were not.
 */
class RegistrationSurvivesMailFailureTest extends TestCase
{
    use RefreshDatabase;

    private function breakTheMailer(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.example.test:465"'));
    }

    private function account(): User
    {
        return User::create([
            'name' => 'Rina Kusuma',
            'email' => 'rina@contoh.test',
            'password' => bcrypt('Perancah2026kuat'),
            'role' => User::ROLE_ACCOUNT,
            'tenant_id' => null,
            'company_id' => null,
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rina Kusuma',
            'email' => 'rina@contoh.test',
            'password' => 'Perancah2026kuat',
            'password_confirmation' => 'Perancah2026kuat',
            'terms' => true,
        ], $overrides);
    }

    public function test_registration_completes_when_the_verification_email_cannot_be_sent(): void
    {
        $this->breakTheMailer();

        $this->post('/register', $this->payload())
            ->assertRedirect('/register/welcome')
            ->assertSessionHas('verification_mail_failed', true);

        $user = User::withoutGlobalScopes()->where('email', 'rina@contoh.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_the_welcome_page_says_the_email_did_not_go_rather_than_that_it_did(): void
    {
        $this->breakTheMailer();

        $this->post('/register', $this->payload());

        $this->get('/register/welcome')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/AccountCreated')
                ->where('account.verification_mail_failed', true));
    }

    public function test_no_transport_detail_reaches_the_browser(): void
    {
        $this->breakTheMailer();

        $response = $this->followingRedirects()->post('/register', $this->payload());

        $response->assertOk();
        $this->assertStringNotContainsString('smtp.example.test', $response->getContent());
        $this->assertStringNotContainsString('TransportException', $response->getContent());
    }

    public function test_resend_reports_failure_honestly(): void
    {
        $user = $this->account();
        $this->breakTheMailer();

        $this->actingAs($user)->from('/account')->post('/email/verification-notification')
            ->assertRedirect('/account')
            ->assertSessionHas('error')
            ->assertSessionMissing('success');
    }

    public function test_a_password_reset_request_does_not_500_when_mail_is_down(): void
    {
        $this->account();
        $this->breakTheMailer();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'rina@contoh.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    }

    public function test_a_duplicate_address_after_a_failed_send_points_at_sign_in(): void
    {
        $this->breakTheMailer();
        $this->post('/register', $this->payload());
        auth()->logout();

        $this->from('/register')->post('/register', $this->payload())
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');
    }
}
