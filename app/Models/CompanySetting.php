<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CompanySetting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * v1.6.8 (second pass): reverted the hash-suffixed cache key from an
     * earlier fix. That approach introduced a real, confirmed bug of its
     * own: get()'s cache key gained a hash suffix, but set()'s forget()
     * call was never updated to match it, since set() only knows the key
     * and new value being saved -- not what default any given caller
     * might pass to get() later. The forget() call was therefore
     * targeting a cache key that was never actually the one in use,
     * meaning saving ANY setting through this helper stopped actually
     * invalidating its cache at all.
     *
     * Simpler and more robust: cache only the raw stored value (or a
     * sentinel meaning "nothing stored"), never the caller-supplied
     * default, and apply the default outside the cached closure. A
     * single, predictable "company_setting:{$key}" cache key is then
     * exactly what set()'s forget() already targets, with no way for the
     * two to drift out of sync again.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever("company_setting:{$key}", function () use ($key) {
            return static::where('key', $key)->value('value') ?? "\0__unset__\0";
        });

        return $stored === "\0__unset__\0" ? $default : $stored;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("company_setting:{$key}");
    }

    public static function all_settings(): array
    {
        return static::pluck('value', 'key')->toArray();
    }
}
