<?php

namespace Tests\Feature;

use App\Support\ErrorMessagePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * v2.68.0 -- THE ERROR PAGE, AND THE TWO THINGS IT MUST NOT DO.
 *
 * `Errors/Show` was built in v1.11.3.2 so an expected error would render
 * inside the product with a way back, instead of breaking out of the SPA
 * into a bare Laravel page. It worked -- but only for requests carrying
 * the `X-Inertia` header, which is to say only for navigation started
 * from inside the already-running app.
 *
 * Every FULL-PAGE request fell through to the default handler: a stale
 * bookmark, a link pasted into a chat, a refresh after the session
 * expired, a deep link into a department the user cannot open. Those are
 * the moments someone actually needs a way back, and all of them showed a
 * bare "404 NOT FOUND" instead. Found by requesting an unknown URL in a
 * browser, which is the only way this was ever going to surface -- the
 * component, the handler and the route table each looked correct alone.
 *
 * The second half of this file guards the risk that came with the fix.
 * Reaching more requests means the message shown reaches more readers, so
 * what gets shown had to become deliberate rather than "whatever
 * getMessage() returns". See ErrorMessagePresenter.
 *
 * NOTHING HERE ASSERTS ON AUTHORIZATION, and nothing about it changed:
 * these tests pin how a status is RENDERED. The status codes themselves,
 * and every abort()/abort_unless() that produces them, are covered by
 * TenantIsolationTest, PtwAccessDepartmentTest and their neighbours.
 */
class ErrorPagePresentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The defect itself: a plain browser navigation to an unknown URL.
     * No `X-Inertia` header, because a browser address bar does not send
     * one.
     */
    public function test_a_full_page_request_to_an_unknown_url_renders_the_error_page(): void
    {
        $response = $this->get('/no-such-page', ['Accept' => 'text/html']);

        $response->assertStatus(404);

        $this->assertSame(
            'Errors/Show',
            $response->viewData('page')['component'],
            'A full-page request to an unknown URL must render the in-product error page, which offers a '
            .'link back. Before v2.68.0 this returned a bare "404 NOT FOUND" with no navigation at all.'
        );
    }

    /** The status code is presentation-independent and must not move. */
    public function test_rendering_the_friendly_page_does_not_change_the_status_code(): void
    {
        $this->get('/no-such-page', ['Accept' => 'text/html'])->assertStatus(404);
        $this->get('/no-such-page', ['X-Inertia' => 'true', 'Accept' => 'text/html'])->assertStatus(404);
    }

    /**
     * A missing asset should stay a cheap default 404 rather than render a
     * React page nothing will read.
     */
    public function test_asset_requests_keep_lightweight_default_handling(): void
    {
        foreach (['/build/assets/missing.js', '/images/nope.png', '/favicon.ico'] as $assetPath) {
            $response = $this->get($assetPath, ['Accept' => 'text/html']);

            $response->assertStatus(404);

            $this->assertStringNotContainsString(
                'Errors/Show',
                $response->getContent(),
                "{$assetPath} looks like an asset and should not render the Inertia error page."
            );
        }
    }

    /**
     * Framework messages name internal classes and explain nothing a
     * reader can act on. Errors/Show has its own copy for every status it
     * handles; these must fall back to it.
     */
    public function test_framework_generated_messages_are_never_shown(): void
    {
        $frameworkMessages = [
            404 => 'No query results for model [App\Models\Employee] 99999',
            405 => 'The route billing/export could not be found.',
            419 => 'CSRF token mismatch.',
            401 => 'Unauthenticated.',
        ];

        foreach ($frameworkMessages as $status => $message) {
            $this->assertNull(
                ErrorMessagePresenter::forStatus($status, new HttpException($status, $message)),
                "\"{$message}\" is written by the framework and names internals. The page's own fallback "
                .'copy should be shown instead.'
            );
        }
    }

    /**
     * The messages this codebase writes on purpose are the reason the
     * message channel exists at all -- suppressing them would make a 403
     * less informative than before, not more.
     */
    public function test_messages_this_codebase_authors_are_still_shown(): void
    {
        $authored = [
            'This page belongs to a different department.',
            'You do not have permission to access this page.',
            'This area is not available in the IOMS Sandbox. Start a subscription to use it with your own company data.',
            // EnforceTenantEntitlement's denial copy. Indonesian on
            // purpose: it is an explanatory sentence, which the language
            // hierarchy assigns to Indonesian (see LanguageHierarchyTest).
            // Losing this one would tell a blocked tenant nothing about
            // why they are blocked.
            'Fitur ini belum tersedia untuk paket perusahaan Anda.',
        ];

        foreach ($authored as $message) {
            $this->assertSame(
                $message,
                ErrorMessagePresenter::forStatus(403, new HttpException(403, $message)),
                'A deliberately written 403 explanation must reach the reader.'
            );
        }
    }

    /** A 500 must never explain itself: the text is a stack-trace artifact. */
    public function test_server_error_messages_are_never_shown(): void
    {
        foreach ([500, 503] as $status) {
            $this->assertNull(
                ErrorMessagePresenter::forStatus($status, new HttpException($status, 'SQLSTATE[42S02] table missing')),
                'Server-error detail must not be rendered to a user.'
            );
        }
    }
}
