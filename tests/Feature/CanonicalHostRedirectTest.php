<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.81.0 -- ONE ADDRESS, AND THE ONE REQUEST THAT MUST NOT BE MOVED.
 *
 * Everything that DESCRIBES the site already named the canonical origin:
 * canonical tags, the sitemap, Open Graph and structured data are built
 * from `config('ioms.public_url')` rather than from the request, and any
 * other host is already `noindex`. What was missing was the redirect, so a
 * crawler on `www.iomsuite.com` was served the page and stayed there.
 *
 * The dangerous half of adding one is the payment webhook. A 301 on a POST
 * may be turned into a GET with the body dropped, which would discard a
 * provider's settlement notification in a way nothing would report: IOMS
 * would simply never learn the customer had paid. That is asserted here
 * directly rather than left to the middleware's comment.
 */
class CanonicalHostRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://iomsuite.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PackageSeeder::class);
        config(['ioms.public_url' => self::ORIGIN, 'seo.redirect_www' => true]);
    }

    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    /* ==================================================================
     * What it moves
     * ================================================================== */

    public function test_www_is_permanently_redirected_to_the_canonical_host(): void
    {
        $this->asProduction();

        $this->get('https://www.iomsuite.com/')
            ->assertStatus(301)
            ->assertRedirect(self::ORIGIN.'/');
    }

    public function test_the_path_and_query_survive_the_redirect(): void
    {
        // A redirect that drops the visitor on the home page loses the page
        // they asked for, and a search engine reads it as a soft 404.
        $this->asProduction();

        $this->get('https://www.iomsuite.com/pricing?plan=business')
            ->assertStatus(301)
            ->assertRedirect(self::ORIGIN.'/pricing?plan=business');
    }

    public function test_a_head_request_is_redirected_too(): void
    {
        // Crawlers and uptime checks use HEAD; leaving it unmoved would keep
        // the www host alive in exactly the places that matter for indexing.
        $this->asProduction();

        $this->head('https://www.iomsuite.com/pricing')->assertStatus(301);
    }

    /* ==================================================================
     * What it must NOT move
     * ================================================================== */

    public function test_a_payment_webhook_posted_to_the_www_host_is_never_redirected(): void
    {
        $this->asProduction();

        // No signature, so the webhook itself refuses it -- which is the
        // point: it must reach the verification code and be REJECTED there,
        // not be bounced by a redirect that silently drops the body.
        $response = $this->postJson('https://www.iomsuite.com'.route('webhooks.payment.midtrans', [], false), [
            'order_id' => 'INV1-20260101000000',
            'status_code' => '200',
            'gross_amount' => '1000.00',
            'signature_key' => 'not-a-real-signature',
            'transaction_status' => 'settlement',
        ]);

        $this->assertNotSame(301, $response->getStatusCode(), 'A webhook POST must never be 301-redirected.');
        $this->assertFalse($response->isRedirect(), 'A webhook POST must never be redirected at all.');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('status', Invoice::STATUS_PAID)->count());
    }

    public function test_no_unsafe_method_is_redirected(): void
    {
        $this->asProduction();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $response = $this->{$method}('https://www.iomsuite.com/login');

            $this->assertFalse(
                $response->isRedirect() && $response->getStatusCode() === 301,
                strtoupper($method).' must not be 301-redirected across hosts.'
            );
        }
    }

    public function test_the_canonical_host_is_served_directly(): void
    {
        $this->asProduction();

        $this->get(self::ORIGIN.'/pricing')->assertOk();
    }

    public function test_an_unrelated_host_is_left_alone(): void
    {
        // Only `www.` + the canonical host is moved. Redirecting "anything
        // that is not canonical" would bounce a health check by IP, an
        // internal hostname, and the legacy domain -- which is a separate
        // decision and already handled by being noindex.
        $this->asProduction();

        $this->get('https://ioms.web.id/pricing')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_nothing_is_redirected_outside_production(): void
    {
        // A redirect in development would send a developer to the live site.
        $this->get('https://www.iomsuite.com/pricing')->assertOk();
    }

    public function test_the_redirect_can_be_switched_off(): void
    {
        // If the www host is ever genuinely needed as its own address, it is
        // configuration rather than a code change.
        $this->asProduction();
        config(['seo.redirect_www' => false]);

        $this->get('https://www.iomsuite.com/pricing')->assertOk();
    }

    /* ==================================================================
     * And the metadata stays canonical either way
     * ================================================================== */

    public function test_the_canonical_origin_is_still_what_every_page_declares(): void
    {
        $this->asProduction();

        $html = $this->get(self::ORIGIN.'/pricing')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://iomsuite.com/pricing">', $html);
        $this->assertStringNotContainsString('www.iomsuite.com', $html);
    }
}
