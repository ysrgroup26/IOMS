<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.75.0 -- THE EMAIL LOGO MUST NOT DEPEND ON WHO SENT THE EMAIL.
 *
 * It rendered as an empty box in webmail. The image address came from
 * APP_URL of the sending host (localhost in development, the legacy domain
 * elsewhere), which a mail provider's image proxy cannot fetch; and the
 * artwork was near-white on transparency, which vanishes wherever a client
 * drops the navy header background. See emails/partials/logo.blade.php.
 */
class EmailLogoTest extends TestCase
{
    use RefreshDatabase;

    private function renderedVerificationEmail(): string
    {
        $user = User::create([
            'name' => 'Rina Kusuma', 'email' => 'rina@contoh.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_ACCOUNT, 'tenant_id' => null, 'is_active' => true,
        ]);

        return (new VerifyAccountEmail($user, 'https://example.test/verify/abc', 60))->render();
    }

    public function test_the_logo_is_an_absolute_https_url_on_the_production_origin(): void
    {
        // The exact situation that broke it: a development APP_URL.
        config(['app.url' => 'http://localhost:8000']);
        url()->forceRootUrl('http://localhost:8000');

        $html = $this->renderedVerificationEmail();

        $this->assertStringContainsString('src="https://iomsuite.com/branding/ioms-logo-email.png"', $html);
        $this->assertDoesNotMatchRegularExpression('#<img[^>]+src="https?://(localhost|127\.0\.0\.1|ioms\.web\.id)#', $html);
        $this->assertDoesNotMatchRegularExpression('#<img[^>]+src="/#', $html, 'A relative image path resolves against the mail client.');
    }

    public function test_the_blocked_image_fallback_is_kept(): void
    {
        $html = $this->renderedVerificationEmail();

        $this->assertStringContainsString('alt="IOMS"', $html);
        $this->assertStringContainsString('width="132"', $html);
        $this->assertStringContainsString('Industrial Operations Platform', $html);
    }

    /** The asset exists, is a PNG (Outlook has no SVG), and is opaque. */
    public function test_the_email_logo_asset_is_an_opaque_png(): void
    {
        $path = public_path(config('branding.assets.logo_email_png'));

        $this->assertFileExists($path);
        $this->assertSame('image/png', getimagesize($path)['mime']);

        $image = imagecreatefrompng($path);
        $corner = imagecolorat($image, 0, 0);

        $this->assertSame(0, ($corner >> 24) & 0x7F, 'The email logo must not be transparent.');
        $this->assertSame(0x0F2747, $corner & 0xFFFFFF, 'The flattened background must match the email header navy.');
    }

    /** The image moved; the working link did not. */
    public function test_the_verification_link_is_untouched(): void
    {
        $this->assertStringContainsString('https://example.test/verify/abc', $this->renderedVerificationEmail());
    }
}
