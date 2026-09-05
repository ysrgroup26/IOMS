<?php

namespace Tests\Feature;

use App\Concerns\HasSecureDocument;
use App\Support\SecureDocumentRegistry;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * v2.38.0 (Master Audit) -- architectural guard rails.
 *
 * The document-exposure defect was not caused by anyone being careless;
 * it was caused by there being nothing that could notice. Tenant safety
 * in IOMS rests on developers remembering a convention, and a convention
 * with no enforcement decays silently. These tests convert the convention
 * into something CI can fail on.
 *
 * They intentionally assert about the shape of the codebase rather than
 * runtime behaviour, which makes them cheap, fast, and effective at
 * catching the *next* occurrence rather than re-proving this one.
 */
class SecureDocumentContractTest extends TestCase
{
    /**
     * Models that legitimately expose a public URL. Anything not listed
     * here must not hand out a raw `/storage/...` link.
     *
     * `Company` is the tenant's own branding/logo, rendered on the public
     * login page, the landing page and inside generated PDFs -- it is
     * meant to be publicly fetchable. `User` avatars and `Employee`
     * photos are deliberately left public FOR NOW: they are rendered as
     * inline <img> across many pages, they are low-sensitivity relative
     * to compliance documents, and routing every avatar through an
     * authorising controller is a performance decision that deserves its
     * own discussion rather than being smuggled into a security pass.
     * That reasoning is recorded here rather than lost.
     */
    private const PUBLIC_URL_ALLOWED = [
        \App\Models\Company::class,
        \App\Models\User::class,
        \App\Models\Employee::class,
    ];

    public function test_no_unexpected_model_hands_out_a_public_storage_url(): void
    {
        $offenders = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (in_array($class, self::PUBLIC_URL_ALLOWED, true)) {
                continue;
            }

            $source = file_get_contents($file);

            // Only real code, not the doc comments that explain the fix.
            $codeOnly = preg_replace('#/\*.*?\*/#s', '', $source);
            $codeOnly = preg_replace('#//.*#', '', (string) $codeOnly);

            if (str_contains((string) $codeOnly, "asset('storage/'")) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These models expose a raw public storage URL, bypassing authentication and the tenant check.',
            'Use App\Concerns\HasSecureDocument and register the model in App\Support\SecureDocumentRegistry,',
            'or add it to PUBLIC_URL_ALLOWED with a written justification if it is genuinely public.',
        ]));
    }

    /** Every registered type must resolve to a real model that opted into the contract. */
    public function test_every_registered_document_type_uses_the_trait(): void
    {
        foreach (SecureDocumentRegistry::TYPES as $type => $class) {
            $this->assertTrue(class_exists($class), "Registered type [$type] points at a missing class [$class].");
            $this->assertTrue(is_subclass_of($class, Model::class), "Registered type [$type] is not an Eloquent model.");

            $this->assertContains(
                HasSecureDocument::class,
                class_uses_recursive($class),
                "Registered type [$type] ({$class}) must `use HasSecureDocument` or the controller cannot resolve its owner."
            );
        }
    }

    /** The reverse lookup the trait relies on must be unambiguous. */
    public function test_registry_type_keys_map_to_distinct_models(): void
    {
        $classes = array_values(SecureDocumentRegistry::TYPES);

        $this->assertSame(
            count($classes),
            count(array_unique($classes)),
            'Two type keys map to the same model; SecureDocumentRegistry::typeFor() would become ambiguous.'
        );
    }
}
