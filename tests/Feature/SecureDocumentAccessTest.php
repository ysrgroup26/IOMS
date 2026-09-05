<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v2.38.0 (Master Audit, P1). Proves the tenant-sensitive document
 * delivery boundary introduced by SecureDocumentController.
 *
 * Before this, `ContractorDocument::getUrlAttribute()` returned
 * `asset('storage/'.$file_path)` -- a permanent public URL served
 * directly off the `public/storage` symlink by the web server, with no
 * authentication, no tenant check and no revocation. These tests pin
 * every property of the replacement: unauthenticated requests are turned
 * away, another tenant's document is indistinguishable from a missing
 * one, and a tenant's own document still downloads correctly.
 */
class SecureDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');

        $tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $this->companyA = Company::withoutGlobalScopes()->create(['name' => 'A', 'tenant_id' => $tenantA->id]);
        $this->companyB = Company::withoutGlobalScopes()->create(['name' => 'B', 'tenant_id' => $tenantB->id]);

        $this->userA = User::create([
            'name' => 'Admin A', 'email' => 'a@example.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenantA->id, 'is_active' => true,
        ]);
    }

    private function documentFor(Company $company, string $body): ContractorDocument
    {
        $contractor = Contractor::create([
            'code' => 'CTR-'.$company->id,
            'company_id' => $company->id,
            'company_name' => 'Contractor of '.$company->name,
        ]);

        $path = "uploads/contractor-documents/{$company->id}-licence.pdf";
        Storage::disk('public')->put($path, $body);

        return ContractorDocument::create([
            'contractor_id' => $contractor->id,
            'document_type' => 'license',
            'file_path' => $path,
            'original_name' => 'licence.pdf',
        ]);
    }

    public function test_unauthenticated_request_cannot_download_a_document(): void
    {
        $doc = $this->documentFor($this->companyA, 'SENSITIVE');

        $this->get(route('secure-documents.show', ['type' => 'contractor-document', 'id' => $doc->id]))
            ->assertRedirect('/login');
    }

    public function test_user_cannot_download_another_tenants_document(): void
    {
        $foreignDoc = $this->documentFor($this->companyB, 'TENANT B SECRET');

        $this->actingAs($this->userA)
            ->get(route('secure-documents.show', ['type' => 'contractor-document', 'id' => $foreignDoc->id]))
            ->assertNotFound();
    }

    public function test_user_can_download_their_own_tenants_document(): void
    {
        $ownDoc = $this->documentFor($this->companyA, 'OWN DOCUMENT');

        $response = $this->actingAs($this->userA)
            ->get(route('secure-documents.show', ['type' => 'contractor-document', 'id' => $ownDoc->id]));

        $response->assertOk();
        $this->assertSame('OWN DOCUMENT', $response->streamedContent());
    }

    /** An unknown type key must not fall through to some generic path handler. */
    public function test_unknown_document_type_is_rejected(): void
    {
        $this->actingAs($this->userA)
            ->get(route('secure-documents.show', ['type' => 'not-a-real-type', 'id' => 1]))
            ->assertNotFound();
    }

    /** The accessor must no longer hand out a raw public storage URL. */
    public function test_document_url_no_longer_points_at_public_storage(): void
    {
        $doc = $this->documentFor($this->companyA, 'X');

        $this->assertStringNotContainsString('/storage/uploads', $doc->url);
        $this->assertStringContainsString('/secure-documents/contractor-document/', $doc->url);
    }
}
