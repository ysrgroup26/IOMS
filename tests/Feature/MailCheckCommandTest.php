<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** v2.131.0 -- ioms:mail-check reports the transport's answer and never the password. */
class MailCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_success_without_printing_the_password(): void
    {
        Mail::fake();
        config(['mail.mailers.smtp.password' => 'super-secret-app-password']);

        $this->artisan('ioms:mail-check', ['to' => 'ops@contoh.test'])
            ->doesntExpectOutputToContain('super-secret-app-password')
            ->expectsOutputToContain('(set, not shown)')
            ->assertSuccessful();
    }

    public function test_it_fails_loudly_when_the_transport_refuses(): void
    {
        Mail::shouldReceive('raw')->andThrow(new TransportException('Expected response code "235" but got code "535", with message "535 Authentication Failed".'));
        Mail::shouldReceive('to')->andThrow(new TransportException('535 Authentication Failed'));

        $this->artisan('ioms:mail-check', ['to' => 'ops@contoh.test'])
            ->expectsOutputToContain('535 Authentication Failed')
            ->assertFailed();
    }

    public function test_it_refuses_something_that_is_not_an_address(): void
    {
        $this->artisan('ioms:mail-check', ['to' => 'not-an-address'])->assertExitCode(2);
    }
}
