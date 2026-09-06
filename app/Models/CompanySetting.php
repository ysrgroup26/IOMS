<?php

namespace App\Models;

use App\Models\Scopes\CompanySettingScope;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-scoped key/value settings (branding, company identity, module
 * toggles, notification preferences).
 *
 * v2.40.0 -- TWO TIERS. Before this release the table had no tenant
 * discriminator at all and `key` was globally unique, so one tenant read
 * and overwrote every other tenant's company identity, reaching PDFs,
 * Excel exports, reports and notifications. See the migration
 * 2026_09_06_100200 for the full defect write-up and backfill reasoning.
 *
 *   tenant_id IS NULL -> platform default (guests on login/landing, a
 *                        Platform Super Admin, and any tenant that has
 *                        not overridden the key).
 *   tenant_id = X     -> that tenant's own override.
 *
 * Resolution order in get(): current tenant -> platform default ->
 * caller-supplied default. Writes always land in the CURRENT bucket, so
 * a tenant admin can never edit another tenant's value, and the seeder
 * (console, no tenant) correctly writes platform defaults.
 *
 * CACHING -- v1.6.8's lesson is preserved, not re-learned. That release
 * fixed a real bug where get()'s cache key gained a hash suffix that
 * set()'s forget() was never updated to match, so saving a setting
 * stopped invalidating it. The rule that came out of it: cache ONLY the
 * raw stored row under a key set() can reconstruct exactly, and apply
 * defaults outside the cache. That still holds here -- each TIER is
 * cached separately under "company_setting:{tenant|platform}:{key}", the
 * two-tier fallback happens outside the cache, and set() forgets exactly
 * the one key it just wrote. A tenant's cache can therefore never be
 * poisoned by, or go stale against, another tenant's write.
 */
class CompanySetting extends Model
{
    protected $fillable = ['tenant_id', 'key', 'value'];

    /** Sentinel meaning "no row stored", so a legitimately null/'' value is still cacheable. */
    private const UNSET = "\0__unset__\0";

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanySettingScope);
    }

    private static function cacheKey(?int $tenantId, string $key): string
    {
        return 'company_setting:'.($tenantId ?? 'platform').':'.$key;
    }

    /** Raw stored value for exactly one tier, or the UNSET sentinel. Cached per tier. */
    private static function storedFor(?int $tenantId, string $key, bool $useCache = true): mixed
    {
        $read = function () use ($tenantId, $key) {
            $query = static::withoutGlobalScopes()->where('key', $key);

            $tenantId === null
                ? $query->whereNull('tenant_id')
                : $query->where('tenant_id', $tenantId);

            return $query->value('value') ?? self::UNSET;
        };

        return $useCache
            ? Cache::rememberForever(self::cacheKey($tenantId, $key), $read)
            : $read();
    }

    private static function resolve(string $key, mixed $default, bool $useCache): mixed
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId !== null) {
            $own = static::storedFor($tenantId, $key, $useCache);

            if ($own !== self::UNSET) {
                return $own;
            }
        }

        $platform = static::storedFor(null, $key, $useCache);

        return $platform === self::UNSET ? $default : $platform;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::resolve($key, $default, true);
    }

    /**
     * Same two-tier resolution, cache bypassed.
     *
     * `enabled_modules` is read this way on purpose (see
     * HandleInertiaRequests): two separate production bugs came from
     * forever-caching that one value, and it is a tiny indexed single-row
     * lookup that was never hot enough to justify the risk. This exists
     * so those callers get tenant-correct resolution WITHOUT giving up
     * the deliberate cache bypass -- previously they had to drop to raw
     * Eloquent, which is exactly how they ended up reading across tenants.
     */
    public static function getUncached(string $key, mixed $default = null): mixed
    {
        return static::resolve($key, $default, false);
    }

    /** Writes into the CURRENT bucket: the resolved tenant, or the platform tier when none is resolved. */
    public static function set(string $key, mixed $value): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        static::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => $value],
        );

        Cache::forget(self::cacheKey($tenantId, $key));
    }

    /**
     * Effective settings for the current bucket, platform defaults merged
     * under the tenant's own overrides.
     */
    public static function all_settings(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        $platform = static::withoutGlobalScopes()->whereNull('tenant_id')->pluck('value', 'key')->toArray();

        if ($tenantId === null) {
            return $platform;
        }

        $own = static::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('value', 'key')->toArray();

        return array_merge($platform, $own);
    }
}
