<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v2.41.0 -- tenant branding assets.
 *
 * `brand_wordmark_path` and `brand_icon_path` have been READ by
 * HandleInertiaRequests since v1.5.3 with nothing able to write them.
 * This release adds them to the existing Settings > Company form rather
 * than building a second branding surface, and fixes two real defects
 * found while doing it:
 *
 *   - ImageUploadField's Remove button only cleared the PENDING file, so
 *     clicking it on an already-saved asset looked like it worked and
 *     silently changed nothing on save.
 *   - Replaced assets were left on disk forever, leaking a file per upload.
 *
 * Removal is only safe to do destructively because company_settings became
 * tenant-scoped in v2.40.0: each tenant's path is its own row pointing at
 * its own file.
 */
class TenantBrandingUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $slug): User
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
        Company::withoutGlobalScopes()->create(['name' => strtoupper($slug), 'tenant_id' => $tenant->id]);

        $user = User::create([
            'name' => 'Admin', 'email' => "admin@{$slug}.test", 'password' => bcrypt('secret'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['company_name' => 'ACME Shipyard'], $overrides);
    }

    public function test_an_admin_can_upload_a_wordmark_and_brand_icon(): void
    {
        Storage::fake('public');
        $admin = $this->admin('acme');

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'wordmark' => UploadedFile::fake()->image('wordmark.png', 400, 100),
            'brand_icon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);

        $wordmark = CompanySetting::get('brand_wordmark_path');
        $icon = CompanySetting::get('brand_icon_path');

        $this->assertNotNull($wordmark, 'Wordmark path was never written.');
        $this->assertNotNull($icon, 'Brand icon path was never written.');
        Storage::disk('public')->assertExists($wordmark);
        Storage::disk('public')->assertExists($icon);
    }

    /** The whole reason the upload UI was blocked until v2.40.0. */
    public function test_one_tenants_uploaded_wordmark_is_invisible_to_another(): void
    {
        Storage::fake('public');
        $a = $this->admin('acme');
        $b = $this->admin('borneo');

        $this->actingAs($a)->post(route('settings.company'), $this->payload([
            'wordmark' => UploadedFile::fake()->image('acme.png'),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($b->tenant);
        $this->assertNull(CompanySetting::get('brand_wordmark_path'), "Tenant B sees Tenant A's wordmark.");

        app(CurrentTenant::class)->set($a->tenant);
        $this->assertNotNull(CompanySetting::get('brand_wordmark_path'));
    }

    /** A save by one tenant must not blank another tenant's branding. */
    public function test_a_second_tenant_save_does_not_clear_the_first(): void
    {
        Storage::fake('public');
        $a = $this->admin('acme');
        $b = $this->admin('borneo');

        $this->actingAs($a)->post(route('settings.company'), $this->payload([
            'wordmark' => UploadedFile::fake()->image('acme.png'),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($a->tenant);
        $before = CompanySetting::get('brand_wordmark_path');

        $this->actingAs($b)->post(route('settings.company'), $this->payload([
            'company_name' => 'Borneo Fabrication',
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($a->tenant);
        $this->assertSame($before, CompanySetting::get('brand_wordmark_path'));
        Storage::disk('public')->assertExists($before);
    }

    /** The defect: Remove used to look like it worked and change nothing. */
    public function test_removing_a_saved_wordmark_clears_it_and_deletes_the_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin('acme');

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'wordmark' => UploadedFile::fake()->image('wordmark.png'),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);
        $path = CompanySetting::get('brand_wordmark_path');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'remove_wordmark' => true,
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);
        $this->assertNull(CompanySetting::get('brand_wordmark_path'), 'Remove did not clear the saved wordmark.');
        Storage::disk('public')->assertMissing($path);
    }

    /** Replacing an asset must not leak the previous file. */
    public function test_replacing_an_asset_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin('acme');

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'logo' => UploadedFile::fake()->image('first.png'),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);
        $first = CompanySetting::get('company_logo_path');

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'logo' => UploadedFile::fake()->image('second.png'),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);
        $second = CompanySetting::get('company_logo_path');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    /** Saving unrelated fields must not silently drop existing branding. */
    public function test_saving_other_fields_leaves_assets_untouched(): void
    {
        Storage::fake('public');
        $admin = $this->admin('acme');

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);
        $path = CompanySetting::get('company_logo_path');

        $this->actingAs($admin)->post(route('settings.company'), $this->payload([
            'company_name' => 'ACME Shipyard Renamed',
        ]))->assertRedirect();

        app(CurrentTenant::class)->set($admin->tenant);
        $this->assertSame($path, CompanySetting::get('company_logo_path'));
        Storage::disk('public')->assertExists($path);
    }

    /** Executable uploads must be rejected outright. */
    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->admin('acme');

        $this->actingAs($admin)
            ->post(route('settings.company'), $this->payload([
                'wordmark' => UploadedFile::fake()->create('payload.php', 8, 'application/x-php'),
            ]))
            ->assertSessionHasErrors('wordmark');

        app(CurrentTenant::class)->set($admin->tenant);
        $this->assertNull(CompanySetting::get('brand_wordmark_path'));
    }

    /** Branding is Super Admin only; a lesser role must not reach it. */
    public function test_a_non_super_admin_cannot_change_branding(): void
    {
        Storage::fake('public');
        $admin = $this->admin('acme');

        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@acme.test', 'password' => bcrypt('secret'),
            'role' => 'hrd', 'tenant_id' => $admin->tenant_id, 'is_active' => true,
        ]);

        // The route sits behind `role:super_admin`, so this never reaches the
        // controller at all -- what matters is that nothing was written.
        $this->actingAs($staff)
            ->post(route('settings.company'), $this->payload([
                'wordmark' => UploadedFile::fake()->image('nope.png'),
            ]))
            ->assertForbidden();

        app(CurrentTenant::class)->set($admin->tenant);
        $this->assertNull(CompanySetting::get('brand_wordmark_path'), 'A non-super-admin changed tenant branding.');
    }
}
