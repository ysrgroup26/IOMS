<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.1 (ADR 085) -- a staging preview shows its tester the confirmation link, and nothing else does.
 */
class StagingPreviewMailLinkTest extends TestCase
{
    use RefreshDatabase;

    private function register(): void
    {
        Mail::fake();
        $this->post('/register', [
            'name' => 'Rina Kusuma', 'email' => 'rina@contoh.test',
            'password' => 'Perancah2026kuat', 'password_confirmation' => 'Perancah2026kuat', 'terms' => true,
        ])->assertRedirect('/register/welcome');
    }

    public function test_staging_with_mail_to_the_log_offers_the_signed_link_and_it_confirms_the_account(): void
    {
        $this->register();
        // Set after the POST: CSRF is skipped only while the environment is `testing`.
        $this->app['env'] = 'staging';
        config(['mail.default' => 'log']);

        $url = null;
        $this->get('/register/welcome')->assertInertia(function ($page) use (&$url) {
            $url = $page->toArray()['props']['account']['staging_verify_url'];
        });

        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', $url);
        $this->app['env'] = 'testing';
        $this->get($url)->assertRedirect();
        $this->assertTrue(User::where('email', 'rina@contoh.test')->first()->hasVerifiedEmail());
    }

    public function test_production_never_offers_the_link_even_with_mail_to_the_log(): void
    {
        $this->register();
        // Set after the POST: CSRF is skipped only while the environment is `testing`.
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);

        $this->get('/register/welcome')->assertInertia(fn ($page) => $page->where('account.staging_verify_url', null));
    }

    public function test_a_staging_that_really_sends_mail_keeps_the_inbox_step(): void
    {
        $this->register();
        // Set after the POST: CSRF is skipped only while the environment is `testing`.
        $this->app['env'] = 'staging';
        config(['mail.default' => 'smtp']);

        $this->get('/register/welcome')->assertInertia(fn ($page) => $page->where('account.staging_verify_url', null));
    }
}
